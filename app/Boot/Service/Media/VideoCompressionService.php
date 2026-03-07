<?php

declare(strict_types=1);

namespace App\Boot\Service\Media;

use Core\Handlers\ExceptionBusiness;
use FFMpeg\Coordinate\Dimension;
use FFMpeg\FFMpeg;
use FFMpeg\Filters\Video\ResizeFilter;
use FFMpeg\Format\Video\DefaultVideo;

final class VideoCompressionService
{
    /**
     * @param array<string, mixed> $options
     */
    public function compress(string $binary, int $targetBytes, array $options = []): string
    {
        if ($binary === '') {
            throw new ExceptionBusiness('视频压缩失败：输入为空');
        }
        if ($targetBytes <= 0) {
            return $binary;
        }

        $enabled = (bool)($options['enabled'] ?? true);
        if (!$enabled) {
            return $binary;
        }

        if (strlen($binary) <= $targetBytes) {
            return $binary;
        }

        $maxWidth = max(160, (int)($options['max_width'] ?? 720));
        $maxHeight = max(160, (int)($options['max_height'] ?? 1280));
        $fps = 24;
        $audioKbps = max(16, min(192, (int)($options['audio_kbps'] ?? 48)));
        $minVideoKbps = max(80, (int)($options['min_video_kbps'] ?? 180));
        $maxVideoKbps = max($minVideoKbps, (int)($options['max_video_kbps'] ?? 2000));
        $timeout = max(10, (int)($options['timeout'] ?? 120));
        $inputPath = $this->tempFilePath('media_video_input_', '.mp4');
        $outputPath = $this->tempFilePath('media_video_output_', '.mp4');

        try {
            if (file_put_contents($inputPath, $binary) === false) {
                throw new ExceptionBusiness('视频压缩失败：写入临时文件失败');
            }

            $ffmpeg = FFMpeg::create([
                'timeout' => $timeout,
            ]);
            $duration = (float)($ffmpeg->getFFProbe()->format($inputPath)->get('duration') ?? 0.0);
            if ($duration <= 0) {
                $duration = 8.0;
            }

            $totalKbps = (int)floor(($targetBytes * 8 / 1024 / max(1.0, $duration)) * 0.9);
            $videoKbps = max($minVideoKbps, min($maxVideoKbps, $totalKbps - $audioKbps));

            $codecCandidates = $this->codecCandidates($options);
            $attemptErrors = [];
            foreach ($codecCandidates as $codec) {
                @unlink($outputPath);
                try {
                    $video = $ffmpeg->open($inputPath);
                    $video->filters()->resize(
                        new Dimension($maxWidth, $maxHeight),
                        ResizeFilter::RESIZEMODE_INSET,
                        true
                    );

                    $format = $this->buildFormat($codec, $videoKbps, $audioKbps, $fps);
                    $video->save($format, $outputPath);

                    $compressed = file_get_contents($outputPath);
                    if ($compressed !== false && $compressed !== '') {
                        return $compressed;
                    }
                    $attemptErrors[] = $codec . ': output-empty';
                } catch (\Throwable $e) {
                    $attemptErrors[] = $codec . ': ' . $this->throwableMessageChain($e);
                }
            }

            throw new ExceptionBusiness('视频压缩失败：可用编码器不可用（' . implode(' || ', $attemptErrors) . '）');
        } catch (\Throwable $e) {
            throw new ExceptionBusiness('视频压缩失败: ' . $this->throwableMessageChain($e));
        } finally {
            @unlink($inputPath);
            @unlink($outputPath);
        }
    }

    private function tempFilePath(string $prefix, string $suffix): string
    {
        $tmp = tempnam(sys_get_temp_dir(), $prefix);
        if ($tmp === false) {
            throw new ExceptionBusiness('视频压缩失败：无法创建临时文件');
        }
        $path = $tmp . $suffix;
        @unlink($tmp);
        return $path;
    }

    private function throwableMessageChain(\Throwable $e): string
    {
        $messages = [];
        $current = $e;
        $count = 0;
        while ($current && $count < 6) {
            $msg = trim($current->getMessage());
            if ($msg !== '') {
                $messages[] = $msg;
            }
            $current = $current->getPrevious();
            $count++;
        }
        $messages = array_values(array_unique($messages));
        return $messages === [] ? 'unknown error' : implode(' | ', $messages);
    }

    /**
     * @param array<string, mixed> $options
     * @return list<string>
     */
    private function codecCandidates(array $options): array
    {
        $configured = trim((string)($options['video_codec'] ?? ''));
        $list = [];
        if ($configured !== '') {
            $list[] = $configured;
        }
        foreach (['h264', 'libopenh264', 'libx264', 'mpeg4'] as $codec) {
            if (!in_array($codec, $list, true)) {
                $list[] = $codec;
            }
        }
        return $list;
    }

    private function buildFormat(string $videoCodec, int $videoKbps, int $audioKbps, int $fps): DefaultVideo
    {
        $format = new class('aac', $videoCodec) extends DefaultVideo {
            private int $passes = 1;

            public function __construct(string $audioCodec, string $videoCodec)
            {
                $this->setAudioCodec($audioCodec);
                $this->setVideoCodec($videoCodec);
            }

            public function supportBFrames(): bool
            {
                return false;
            }

            public function getPasses(): int
            {
                return $this->passes;
            }

            public function getAvailableAudioCodecs(): array
            {
                return ['aac', 'libfdk_aac', 'copy'];
            }

            public function getAvailableVideoCodecs(): array
            {
                return ['libx264', 'libopenh264', 'h264', 'mpeg4'];
            }

            public function getModulus(): int
            {
                return 2;
            }
        };

        $params = [
            '-r',
            (string)$fps,
        ];
        if ($videoCodec === 'libx264' || $videoCodec === 'libopenh264' || $videoCodec === 'h264') {
            $params = array_merge([
                '-pix_fmt',
                'yuv420p',
            ], $params);
        }

        $format->setKiloBitrate($videoKbps);
        $format->setAudioKiloBitrate($audioKbps);
        $format->setAdditionalParameters($params);

        return $format;
    }
}
