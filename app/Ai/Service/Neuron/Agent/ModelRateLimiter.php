<?php

declare(strict_types=1);

namespace App\Ai\Service\Neuron\Agent;

use App\Ai\Models\AiAgent;
use App\Ai\Models\AiModel;
use App\Ai\Models\AiProvider;
use App\Ai\Support\AiRuntime;
use Core\App;
use Psr\SimpleCache\CacheInterface;
use Ramsey\Uuid\Uuid;

final class ModelRateLimiter
{
    private const CACHE_PREFIX = 'ai.agent.model_budget.';
    private const WINDOW_SECONDS = 60;
    private const CACHE_TTL = 180;

    /**
     * @return array{
     *     enabled: bool,
     *     model_key: string,
     *     reservation_id: string,
     *     limit: int,
     *     requested_tokens: int,
     *     used_tokens: int,
     *     waited_ms: int,
     *     forced: bool
     * }
     */
    public static function acquireForAgent(AiAgent $agent, int $requestedTokens): array
    {
        $agent->loadMissing('model.provider');
        $model = $agent->model;
        if (!$model instanceof AiModel) {
            return self::disabledReservation();
        }

        $limit = self::resolveTpmLimit($model);
        if ($limit <= 0 || $requestedTokens <= 0) {
            return self::disabledReservation(self::modelKey($model));
        }

        $modelKey = self::modelKey($model);
        $maxWaitMs = self::resolveMaxWaitMs($model);
        $deadline = microtime(true) + ($maxWaitMs / 1000);
        $waitedMs = 0;
        $forced = false;

        while (true) {
            $state = self::readState($modelKey);
            $used = self::sumTokens($state);
            if ($used + $requestedTokens <= $limit) {
                $reservationId = self::appendReservation($modelKey, $state, $requestedTokens);
                return [
                    'enabled' => true,
                    'model_key' => $modelKey,
                    'reservation_id' => $reservationId,
                    'limit' => $limit,
                    'requested_tokens' => $requestedTokens,
                    'used_tokens' => $used,
                    'waited_ms' => $waitedMs,
                    'forced' => false,
                ];
            }

            $waitMs = self::nextWaitMs($state);
            if ($waitMs <= 0) {
                $waitMs = 200;
            }

            $remainingMs = max(0, (int)ceil(($deadline - microtime(true)) * 1000));
            if ($remainingMs <= 0 || $waitMs > $remainingMs) {
                $forced = true;
                $reservationId = self::appendReservation($modelKey, $state, $requestedTokens);
                return [
                    'enabled' => true,
                    'model_key' => $modelKey,
                    'reservation_id' => $reservationId,
                    'limit' => $limit,
                    'requested_tokens' => $requestedTokens,
                    'used_tokens' => $used,
                    'waited_ms' => $waitedMs,
                    'forced' => true,
                ];
            }

            usleep($waitMs * 1000);
            $waitedMs += $waitMs;
        }
    }

    public static function finalize(array $reservation, int $actualTokens): void
    {
        if (!($reservation['enabled'] ?? false)) {
            return;
        }

        $modelKey = trim((string)($reservation['model_key'] ?? ''));
        $reservationId = trim((string)($reservation['reservation_id'] ?? ''));
        if ($modelKey === '' || $reservationId === '') {
            return;
        }

        $requestedTokens = max(0, (int)($reservation['requested_tokens'] ?? 0));
        $actualTokens = max(0, $actualTokens);
        $tokens = $actualTokens > 0 ? $actualTokens : $requestedTokens;

        $state = self::readState($modelKey);
        foreach ($state as $index => $item) {
            if ((string)($item['id'] ?? '') !== $reservationId) {
                continue;
            }
            $state[$index]['tokens'] = $tokens;
            $state[$index]['updated_at'] = microtime(true);
            self::writeState($modelKey, $state);
            return;
        }
    }

    public static function clear(string $modelKey): void
    {
        self::cache()->delete(self::cacheKey($modelKey));
    }

    /**
     * @return array{used_tokens:int,reservations:int}
     */
    public static function snapshot(string $modelKey): array
    {
        $state = self::readState($modelKey);
        return [
            'used_tokens' => self::sumTokens($state),
            'reservations' => count($state),
        ];
    }

    private static function appendReservation(string $modelKey, array $state, int $tokens): string
    {
        $reservationId = (string)Uuid::uuid7();
        $state[] = [
            'id' => $reservationId,
            'tokens' => max(0, $tokens),
            'created_at' => microtime(true),
            'updated_at' => microtime(true),
        ];
        self::writeState($modelKey, $state);
        return $reservationId;
    }

    /**
     * @param array<int, array<string, mixed>> $state
     */
    private static function sumTokens(array $state): int
    {
        $sum = 0;
        foreach ($state as $item) {
            $sum += max(0, (int)($item['tokens'] ?? 0));
        }
        return $sum;
    }

    /**
     * @param array<int, array<string, mixed>> $state
     */
    private static function nextWaitMs(array $state): int
    {
        $oldest = null;
        foreach ($state as $item) {
            $createdAt = (float)($item['created_at'] ?? 0);
            if ($createdAt <= 0) {
                continue;
            }
            if ($oldest === null || $createdAt < $oldest) {
                $oldest = $createdAt;
            }
        }

        if ($oldest === null) {
            return 0;
        }

        return max(100, (int)ceil((($oldest + self::WINDOW_SECONDS) - microtime(true)) * 1000) + 50);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private static function readState(string $modelKey): array
    {
        $items = self::cache()->get(self::cacheKey($modelKey), []);
        if (!is_array($items)) {
            return [];
        }

        $cutoff = microtime(true) - self::WINDOW_SECONDS;
        $resolved = [];
        foreach ($items as $item) {
            if (!is_array($item)) {
                continue;
            }
            $createdAt = (float)($item['created_at'] ?? 0);
            if ($createdAt <= 0 || $createdAt < $cutoff) {
                continue;
            }
            $resolved[] = $item;
        }
        return $resolved;
    }

    /**
     * @param array<int, array<string, mixed>> $state
     */
    private static function writeState(string $modelKey, array $state): void
    {
        self::cache()->set(self::cacheKey($modelKey), array_values($state), self::CACHE_TTL);
    }

    private static function resolveTpmLimit(AiModel $model): int
    {
        $options = is_array($model->options ?? null) ? ($model->options ?? []) : [];
        $rateLimit = is_array($options['rate_limit'] ?? null) ? ($options['rate_limit'] ?? []) : [];

        $candidates = [
            $rateLimit['tpm'] ?? null,
            $rateLimit['tokens_per_minute'] ?? null,
            $options['tpm'] ?? null,
            $options['tokens_per_minute'] ?? null,
        ];

        foreach ($candidates as $candidate) {
            if (is_numeric($candidate)) {
                return max(0, (int)$candidate);
            }
        }

        return 0;
    }

    private static function resolveMaxWaitMs(AiModel $model): int
    {
        $options = is_array($model->options ?? null) ? ($model->options ?? []) : [];
        $rateLimit = is_array($options['rate_limit'] ?? null) ? ($options['rate_limit'] ?? []) : [];
        $candidate = $rateLimit['max_wait_ms'] ?? $options['rate_limit_max_wait_ms'] ?? 8000;
        if (!is_numeric($candidate)) {
            return 8000;
        }
        return max(0, min(60000, (int)$candidate));
    }

    private static function modelKey(AiModel $model): string
    {
        $providerCode = '';
        if ($model->provider instanceof AiProvider) {
            $providerCode = trim((string)($model->provider->code ?? ''));
        }
        $modelCode = trim((string)($model->code ?? ''));
        $remoteModel = trim((string)($model->model ?? ''));

        return implode(':', array_filter([
            $providerCode !== '' ? $providerCode : ('provider' . (int)$model->provider_id),
            $modelCode !== '' ? $modelCode : ('model' . (int)$model->id),
            $remoteModel,
        ]));
    }

    /**
     * @return array{
     *     enabled: bool,
     *     model_key: string,
     *     reservation_id: string,
     *     limit: int,
     *     requested_tokens: int,
     *     used_tokens: int,
     *     waited_ms: int,
     *     forced: bool
     * }
     */
    private static function disabledReservation(string $modelKey = ''): array
    {
        return [
            'enabled' => false,
            'model_key' => $modelKey,
            'reservation_id' => '',
            'limit' => 0,
            'requested_tokens' => 0,
            'used_tokens' => 0,
            'waited_ms' => 0,
            'forced' => false,
        ];
    }

    private static function cacheKey(string $modelKey): string
    {
        return self::CACHE_PREFIX . md5($modelKey);
    }

    private static function cache(): CacheInterface
    {
        return App::cache();
    }
}
