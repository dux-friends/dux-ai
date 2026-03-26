<?php

declare(strict_types=1);

namespace App\Ai\Service\Rag;

use App\Ai\Models\RagKnowledge;
use Core\Handlers\ExceptionBusiness;

final class KnowledgeResolver
{
    public static function resolve(string|int|RagKnowledge $knowledge, bool $withStorage = true): RagKnowledge
    {
        if ($knowledge instanceof RagKnowledge) {
            $model = $knowledge;
        } else {
            $query = RagKnowledge::query();
            $query->with($withStorage ? 'config.storage' : 'config');
            $model = $query->find((int)$knowledge);
        }

        if (!$model) {
            throw new ExceptionBusiness('知识库不存在');
        }

        if ($model->config_id || $model->relationLoaded('config')) {
            $model->loadMissing('config');
            if ($withStorage && $model->config?->storage_id) {
                $model->config->loadMissing('storage');
            }
        }

        return $model;
    }
}
