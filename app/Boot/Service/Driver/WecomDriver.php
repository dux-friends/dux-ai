<?php

declare(strict_types=1);

namespace App\Boot\Service\Driver;

use App\Boot\Models\BootBot;
use App\Boot\Service\DTO\InboundMessage;
use App\Boot\Service\Media\VideoCompressionService;
use App\Boot\Service\Message;
use Core\App;
use Core\Handlers\ExceptionBusiness;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;
use GuzzleHttp\RequestOptions;
use Psr\Http\Message\ServerRequestInterface;

class WecomDriver extends AbstractDriver
{
    private const TOKEN_URL = 'https://qyapi.weixin.qq.com/cgi-bin/gettoken';
    private const SEND_URL = 'https://qyapi.weixin.qq.com/cgi-bin/message/send';
    private const MEDIA_UPLOAD_URL = 'https://qyapi.weixin.qq.com/cgi-bin/media/upload';
    private const IMAGE_MAX_BYTES = 2 * 1024 * 1024;
    private const VIDEO_MAX_BYTES = 10 * 1024 * 1024;
    private const IMAGE_DOWNLOAD_TIMEOUT = 20;
    private const VIDEO_DOWNLOAD_TIMEOUT = 120;
    private const HTTP_RETRY_ATTEMPTS = 3;
    private const HTTP_RETRY_BACKOFF_MS = 300;

    public function __construct(
        private readonly ?VideoCompressionService $videoCompressionService = null,
    ) {
    }

    // 驱动平台标识
    public function platform(): string
    {
        return 'wecom';
    }

    // 平台元信息
    public function meta(): array
    {
        return [
            'label' => '企业微信',
            'value' => 'wecom',
            'icon' => 'i-tabler:brand-wechat',
            'color' => 'green',
            'style' => [
                'iconClass' => 'text-emerald-500',
                'iconBgClass' => 'bg-emerald-500/10',
            ],
        ];
    }

    // 发送企业微信应用消息（主动发送）
    public function send(BootBot $bot, Message $message): array
    {
        $cfg = $this->config($bot);
        $corpId = trim((string)($cfg['corp_id'] ?? $cfg['app_key'] ?? ''));
        $secret = trim((string)($cfg['app_secret'] ?? ''));
        $agentId = (int)($cfg['agent_id'] ?? 0);
        if ($corpId === '' || $secret === '' || $agentId <= 0) {
            throw new ExceptionBusiness('企业微信应用配置不完整，请填写 corp_id / app_secret / agent_id');
        }

        $toUser = trim((string)($message->metaValue()['to_user'] ?? $message->conversationIdValue() ?? ''));
        if ($toUser === '') {
            throw new ExceptionBusiness('企业微信接收用户为空，请传入 to_user');
        }

        $token = $this->accessToken($bot, $corpId, $secret);
        $payload = match ($message->type()) {
            'image' => $this->imagePayloadForSend($message, $token),
            'video' => $this->videoPayloadForSendSafely($message, $token),
            default => $message->payloadFor($this->platform()),
        };
        $payload['touser'] = $toUser;
        $payload['agentid'] = $agentId;

        $result = $this->requestJson(self::SEND_URL, $payload, [], ['access_token' => $token]);
        if ((int)($result['errcode'] ?? 0) !== 0) {
            if ($message->type() === 'video' && ($payload['msgtype'] ?? '') === 'video') {
                App::log('boot')->warning('boot.wecom.video.send_failed_fallback_text', [
                    'error' => (string)($result['errmsg'] ?? 'unknown'),
                    'to_user' => $toUser,
                ]);
                $fallback = $this->videoFallbackTextPayload($message);
                $fallback['touser'] = $toUser;
                $fallback['agentid'] = $agentId;
                $fallbackResult = $this->requestJson(self::SEND_URL, $fallback, [], ['access_token' => $token]);
                if ((int)($fallbackResult['errcode'] ?? 0) === 0) {
                    return $fallbackResult;
                }
            }
            throw new ExceptionBusiness('企业微信发送失败: ' . (string)($result['errmsg'] ?? 'unknown'));
        }
        return $result;
    }

    public function verifyCallbackRequest(BootBot $bot, ServerRequestInterface $request): array|string|null
    {
        if (strtoupper($request->getMethod()) !== 'GET') {
            return null;
        }
        return $this->verifyCallbackUrl($bot, $request);
    }

    private function imagePayloadForSend(Message $message, string $token): array
    {
        $meta = $message->metaValue();
        $mediaId = trim((string)($meta['media_id'] ?? ''));
        if ($mediaId === '') {
            $imageUrl = trim($message->imageUrlValue());
            if ($imageUrl === '') {
                throw new ExceptionBusiness('企业微信图片消息缺少 image_url');
            }
            $mediaId = $this->uploadImageAsMedia($imageUrl, $token);
        }

        return [
            'msgtype' => 'image',
            'image' => ['media_id' => $mediaId],
        ];
    }

    private function uploadImageAsMedia(string $imageUrl, string $token): string
    {
        return $this->uploadMediaAsId($imageUrl, $token, 'image');
    }

    private function videoPayloadForSend(Message $message, string $token): array
    {
        $meta = $message->metaValue();
        $mediaId = trim((string)($meta['media_id'] ?? ''));
        if ($mediaId === '') {
            $videoUrl = trim($message->videoUrlValue());
            if ($videoUrl === '') {
                throw new ExceptionBusiness('企业微信视频消息缺少 video_url');
            }
            $compressOptions = is_array($meta['video_compress'] ?? null) ? ($meta['video_compress'] ?? []) : [];
            $videoSource = $this->extractVideoSource($meta, $videoUrl);
            $mediaId = $this->uploadVideoAsMedia($videoUrl, $token, $compressOptions, $videoSource);
        }

        $title = trim($message->textContent());
        $metaTitle = trim((string)($meta['video_title'] ?? ''));
        $metaDescription = trim((string)($meta['video_description'] ?? ''));
        if ($metaTitle !== '') {
            $title = $metaTitle;
        }
        $title = $this->sanitizeVideoTitle($title);
        $video = ['media_id' => $mediaId];
        if ($title !== '') {
            $video['title'] = $title;
        }
        if ($metaDescription !== '') {
            $video['description'] = $metaDescription;
        }

        return [
            'msgtype' => 'video',
            'video' => $video,
        ];
    }

    private function sanitizeVideoTitle(string $title): string
    {
        $title = trim(preg_replace('/https?:\/\/\S+/u', '', $title) ?? $title);
        if (preg_match('/^视频任务已完成（[^）]+）$/u', $title) === 1) {
            return '视频已生成';
        }
        return $title;
    }

    private function videoPayloadForSendSafely(Message $message, string $token): array
    {
        try {
            return $this->videoPayloadForSend($message, $token);
        } catch (\Throwable $e) {
            App::log('boot')->warning('boot.wecom.video.build_failed_fallback_text', [
                'error' => $e->getMessage(),
                'content' => $message->contentForLog(),
            ]);
            return $this->videoFallbackTextPayload($message);
        }
    }

    private function videoFallbackTextPayload(Message $message): array
    {
        $title = trim($message->textContent());
        $videoUrl = trim($message->videoUrlValue());
        $content = $title !== '' ? $title : '视频已生成';
        if ($videoUrl !== '') {
            $content .= "\n" . $videoUrl;
        }
        return [
            'msgtype' => 'text',
            'text' => ['content' => $content],
        ];
    }

    /**
     * @param array<string, mixed> $compressOptions
     * @param array<string, mixed> $videoSource
     */
    private function uploadVideoAsMedia(string $videoUrl, string $token, array $compressOptions = [], array $videoSource = []): string
    {
        return $this->uploadMediaAsId($videoUrl, $token, 'video', $compressOptions, $videoSource);
    }

    /**
     * @param array<string, mixed> $compressOptions
     * @param array<string, mixed> $videoSource
     */
    private function uploadMediaAsId(string $url, string $token, string $mediaType, array $compressOptions = [], array $videoSource = []): string
    {
        $timeout = $mediaType === 'video' ? self::VIDEO_DOWNLOAD_TIMEOUT : self::IMAGE_DOWNLOAD_TIMEOUT;
        $client = new Client([
            'timeout' => $timeout,
            'http_errors' => false,
        ]);

        $maxBytes = $mediaType === 'video' ? self::VIDEO_MAX_BYTES : self::IMAGE_MAX_BYTES;
        $binary = '';
        $contentType = '';
        $contentLength = 0;
        if ($mediaType === 'video') {
            $resolved = $this->readVideoBinaryBySource($videoSource);
            if (is_array($resolved)) {
                $binary = (string)($resolved['binary'] ?? '');
                $contentType = trim((string)($resolved['content_type'] ?? ''));
                $contentLength = (int)($resolved['content_length'] ?? 0);
            }
        }
        if ($binary === '') {
            $accept = $mediaType === 'video' ? 'video/*,*/*' : 'image/*';
            $download = $this->requestWithRetry($client, 'GET', $url, [
                RequestOptions::HEADERS => [
                    'Accept' => $accept,
                ],
            ]);
            $status = $download->getStatusCode();
            if ($status < 200 || $status >= 300) {
                throw new ExceptionBusiness(sprintf('企业微信%s下载失败（HTTP %d）', $mediaType === 'video' ? '视频' : '图片', $status));
            }
            $binary = (string)$download->getBody();
            if ($binary === '') {
                throw new ExceptionBusiness('企业微信' . ($mediaType === 'video' ? '视频' : '图片') . '下载为空');
            }
            $contentLength = (int)($download->getHeaderLine('Content-Length') ?: 0);
            $contentType = trim((string)$download->getHeaderLine('Content-Type'));
            if ($contentType === '') {
                $contentType = $mediaType === 'video' ? 'video/mp4' : 'image/jpeg';
            }
        }

        if ($mediaType !== 'video' && $contentLength > 0 && $contentLength > $maxBytes) {
            throw new ExceptionBusiness(sprintf(
                '企业微信%s超过大小限制（最大 %dMB）',
                $mediaType === 'video' ? '视频' : '图片',
                (int)($maxBytes / 1024 / 1024)
            ));
        }
        $prefix = $mediaType . '/';
        if (!str_starts_with(strtolower($contentType), $prefix)) {
            throw new ExceptionBusiness('企业微信' . ($mediaType === 'video' ? '视频' : '图片') . '格式无效');
        }
        if ($mediaType === 'video') {
            $binary = $this->compressVideoBinary($binary, $compressOptions, self::VIDEO_MAX_BYTES);
            $contentType = 'video/mp4';
        }
        if (strlen($binary) > $maxBytes) {
            throw new ExceptionBusiness(sprintf(
                '企业微信%s超过大小限制（最大 %dMB）',
                $mediaType === 'video' ? '视频' : '图片',
                (int)($maxBytes / 1024 / 1024)
            ));
        }

        $upload = $this->requestWithRetry($client, 'POST', self::MEDIA_UPLOAD_URL, [
            RequestOptions::QUERY => [
                'access_token' => $token,
                'type' => $mediaType,
            ],
            RequestOptions::MULTIPART => [
                [
                    'name' => 'media',
                    'contents' => $binary,
                    'filename' => $this->mediaFilename($url, $contentType, $mediaType),
                    'headers' => [
                        'Content-Type' => $contentType,
                    ],
                ],
            ],
        ]);

        $body = (string)$upload->getBody();
        $data = json_decode($body, true);
        if (!is_array($data)) {
            throw new ExceptionBusiness('企业微信上传' . ($mediaType === 'video' ? '视频' : '图片') . '返回格式错误');
        }
        if ((int)($data['errcode'] ?? 0) !== 0) {
            throw new ExceptionBusiness('企业微信上传' . ($mediaType === 'video' ? '视频' : '图片') . '失败: ' . (string)($data['errmsg'] ?? 'unknown'));
        }

        $mediaId = trim((string)($data['media_id'] ?? ''));
        if ($mediaId === '') {
            throw new ExceptionBusiness('企业微信上传' . ($mediaType === 'video' ? '视频' : '图片') . '失败: media_id 为空');
        }
        return $mediaId;
    }

    /**
     * @param array<string, mixed> $options
     */
    private function compressVideoBinary(string $binary, array $options, int $platformMaxBytes): string
    {
        $targetBytes = $platformMaxBytes;
        $maxMb = (int)($options['max_mb'] ?? 0);
        if ($maxMb > 0) {
            $customBytes = $maxMb * 1024 * 1024;
            if ($customBytes > 0) {
                $targetBytes = min($platformMaxBytes, $customBytes);
            }
        }
        try {
            return $this->compressor()->compress($binary, $targetBytes, $options);
        } catch (\Throwable $e) {
            App::log('boot')->error('boot.wecom.video.compress.failed', [
                'error' => $e->getMessage(),
                'error_chain' => $this->throwableMessageChain($e),
                'input_bytes' => strlen($binary),
            ]);
            throw new ExceptionBusiness('企业微信视频压缩失败: ' . $this->throwableMessageChain($e));
        }
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
     */
    private function requestWithRetry(Client $client, string $method, string $url, array $options): \Psr\Http\Message\ResponseInterface
    {
        $last = null;
        for ($attempt = 1; $attempt <= self::HTTP_RETRY_ATTEMPTS; $attempt++) {
            try {
                return $client->request($method, $url, $options);
            } catch (GuzzleException $e) {
                $last = $e;
                if ($attempt >= self::HTTP_RETRY_ATTEMPTS) {
                    break;
                }
                usleep(self::HTTP_RETRY_BACKOFF_MS * 1000 * $attempt);
            }
        }

        throw new ExceptionBusiness('企业微信媒体请求失败: ' . ($last?->getMessage() ?? 'unknown'));
    }

    /**
     * @param array<string, mixed> $source
     */
    private function readVideoBinaryBySource(array $source): ?array
    {
        $localPath = trim((string)($source['local_path'] ?? ''));
        if ($localPath !== '') {
            $binary = @file_get_contents($localPath);
            if (is_string($binary) && $binary !== '') {
                App::log('boot')->info('boot.wecom.video.source.hit', [
                    'source_type' => 'local_path',
                    'local_path' => $localPath,
                ]);
                return [
                    'binary' => $binary,
                    'content_type' => 'video/mp4',
                    'content_length' => strlen($binary),
                ];
            }
        }

        $candidateUrls = [];
        $remoteUrl = trim((string)($source['remote_url'] ?? ''));
        $storageUrl = trim((string)($source['storage_url'] ?? ''));
        if ($remoteUrl !== '') {
            $candidateUrls[$remoteUrl] = ['type' => 'remote_url', 'url' => $remoteUrl];
        }
        if ($storageUrl !== '') {
            $candidateUrls[$storageUrl] = ['type' => 'storage_url', 'url' => $storageUrl];
        }
        if (!$candidateUrls) {
            return null;
        }

        $client = new Client([
            'timeout' => self::VIDEO_DOWNLOAD_TIMEOUT,
            'http_errors' => false,
        ]);
        foreach (array_values($candidateUrls) as $candidate) {
            $url = (string)$candidate['url'];
            try {
                $response = $this->requestWithRetry($client, 'GET', $url, [
                    RequestOptions::HEADERS => [
                        'Accept' => 'video/*,*/*',
                    ],
                ]);
                $status = $response->getStatusCode();
                if ($status < 200 || $status >= 300) {
                    continue;
                }
                $binary = (string)$response->getBody();
                if ($binary === '') {
                    continue;
                }
                $contentType = trim((string)$response->getHeaderLine('Content-Type'));
                if ($contentType === '') {
                    $contentType = 'video/mp4';
                }
                App::log('boot')->info('boot.wecom.video.source.hit', [
                    'source_type' => (string)$candidate['type'],
                    'url' => $url,
                ]);
                return [
                    'binary' => $binary,
                    'content_type' => $contentType,
                    'content_length' => (int)($response->getHeaderLine('Content-Length') ?: 0),
                ];
            } catch (\Throwable $e) {
                App::log('boot')->warning('boot.wecom.video.read_url.failed', [
                    'source_type' => (string)$candidate['type'],
                    'url' => $url,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        return null;
    }

    /**
     * @param array<string, mixed> $meta
     * @return array<string, mixed>
     */
    private function extractVideoSource(array $meta, string $videoUrl): array
    {
        $source = is_array($meta['video_source'] ?? null) ? ($meta['video_source'] ?? []) : [];
        $localPath = trim((string)($source['local_path'] ?? ''));
        $remoteUrl = trim((string)($source['remote_url'] ?? ''));
        $storageUrl = trim((string)($source['storage_url'] ?? ''));

        if ($remoteUrl === '' && $videoUrl !== '') {
            $remoteUrl = $videoUrl;
        }

        return [
            'local_path' => $localPath !== '' ? $localPath : null,
            'remote_url' => $remoteUrl,
            'storage_url' => $storageUrl,
        ];
    }

    private function compressor(): VideoCompressionService
    {
        if ($this->videoCompressionService instanceof VideoCompressionService) {
            return $this->videoCompressionService;
        }
        return new VideoCompressionService();
    }

    private function mediaFilename(string $url, string $contentType, string $mediaType): string
    {
        $path = parse_url($url, PHP_URL_PATH);
        $ext = strtolower(pathinfo((string)$path, PATHINFO_EXTENSION));
        if ($ext === '') {
            if ($mediaType === 'video') {
                $ext = match (strtolower($contentType)) {
                    'video/webm' => 'webm',
                    'video/quicktime' => 'mov',
                    'video/x-matroska' => 'mkv',
                    default => 'mp4',
                };
            } else {
                $ext = match (strtolower($contentType)) {
                    'image/png' => 'png',
                    'image/gif' => 'gif',
                    'image/webp' => 'webp',
                    default => 'jpg',
                };
            }
        }
        return 'wecom_' . $mediaType . '.' . $ext;
    }

    // 校验企业微信回调URL并返回明文 echostr
    public function verifyCallbackUrl(BootBot $bot, ServerRequestInterface $request): string
    {
        $cfg = $this->config($bot);
        $token = trim((string)($cfg['token'] ?? ''));
        $aesKey = trim((string)($cfg['aes_key'] ?? ''));
        if ($token === '' || $aesKey === '') {
            throw new ExceptionBusiness('企业微信回调配置不完整，请填写 token / aes_key', 400);
        }

        $query = $request->getQueryParams();
        $timestamp = trim((string)($query['timestamp'] ?? ''));
        $nonce = trim((string)($query['nonce'] ?? ''));
        $signature = trim((string)($query['msg_signature'] ?? ''));
        $echostr = trim((string)($query['echostr'] ?? ''));
        if ($timestamp === '' || $nonce === '' || $signature === '' || $echostr === '') {
            throw new ExceptionBusiness('企业微信回调参数不完整', 400);
        }

        $arr = [$token, $timestamp, $nonce, $echostr];
        sort($arr, SORT_STRING);
        if (!hash_equals(sha1(implode('', $arr)), $signature)) {
            throw new ExceptionBusiness('签名校验失败', 401);
        }

        $corpId = trim((string)($cfg['corp_id'] ?? $cfg['app_key'] ?? ''));
        return $this->decryptEcho($echostr, $aesKey, $corpId);
    }

    // 解析企业微信回调原始 XML 并在需要时解密
    public function parseWebhookPayload(BootBot $bot, ServerRequestInterface $request): array
    {
        $xml = $this->rawBody($request);
        $payload = $this->parseXml($xml);

        $cfg = $this->config($bot);
        $token = trim((string)($cfg['token'] ?? ''));
        $aesKey = trim((string)($cfg['aes_key'] ?? ''));
        $corpId = trim((string)($cfg['corp_id'] ?? $cfg['app_key'] ?? ''));
        $encrypt = trim((string)($payload['Encrypt'] ?? ''));
        if ($token === '' || $aesKey === '' || $encrypt === '') {
            $payload['__signature_ok'] = true;
            return $payload;
        }

        $query = $request->getQueryParams();
        $timestamp = trim((string)($query['timestamp'] ?? ''));
        $nonce = trim((string)($query['nonce'] ?? ''));
        $signature = trim((string)($query['msg_signature'] ?? ''));
        if ($timestamp === '' || $nonce === '' || $signature === '') {
            $payload['__signature_ok'] = false;
            return $payload;
        }
        $arr = [$token, $timestamp, $nonce, $encrypt];
        sort($arr, SORT_STRING);
        if (!hash_equals(sha1(implode('', $arr)), $signature)) {
            $payload['__signature_ok'] = false;
            return $payload;
        }

        $plain = $this->decryptEcho($encrypt, $aesKey, $corpId);
        $decoded = $this->parseXml($plain);
        $decoded['__signature_ok'] = true;
        return $decoded;
    }

    // 校验企业微信签名与实例密钥
    public function verifyInbound(BootBot $bot, ServerRequestInterface $request, array $payload): bool
    {
        return (bool)($payload['__signature_ok'] ?? true);
    }

    // 解析企业微信回调消息
    public function parseInbound(BootBot $bot, array $payload, ServerRequestInterface $request): InboundMessage
    {
        return new InboundMessage(
            platform: $this->platform(),
            eventId: trim((string)($payload['MsgId'] ?? $payload['MsgType'] ?? md5((string)time()))),
            conversationId: trim((string)($payload['FromUserName'] ?? '')),
            senderId: trim((string)($payload['FromUserName'] ?? '')),
            senderName: trim((string)($payload['FromUserName'] ?? '')),
            text: trim((string)($payload['Content'] ?? '')),
            timestamp: (int)($payload['CreateTime'] ?? time()),
            raw: $payload,
        );
    }

    public function handleWebhookReply(
        BootBot $bot,
        ServerRequestInterface $request,
        InboundMessage $message,
        ?string $replyText,
        bool $ackOnly
    ): array|string {
        if ($ackOnly) {
            return 'success';
        }
        $text = trim((string)$replyText);
        if ($text === '') {
            return 'success';
        }
        return $this->passiveReply($bot, $request, $message, $text);
    }

    // 构建企业微信被动回复（加密XML）
    public function passiveReply(BootBot $bot, ServerRequestInterface $request, InboundMessage $message, string $text): string
    {
        $cfg = $this->config($bot);
        $token = trim((string)($cfg['token'] ?? ''));
        $aesKey = trim((string)($cfg['aes_key'] ?? ''));
        $corpId = trim((string)($cfg['corp_id'] ?? $cfg['app_key'] ?? ''));
        if ($token === '' || $aesKey === '' || $corpId === '') {
            throw new ExceptionBusiness('企业微信回调配置不完整，请填写 token / aes_key / corp_id');
        }

        $payload = $message->raw;
        $from = (string)($payload['FromUserName'] ?? '');
        $to = (string)($payload['ToUserName'] ?? '');
        if ($from === '' || $to === '') {
            throw new ExceptionBusiness('企业微信回调消息体不完整');
        }

        $rawReply = '<xml>'
            . '<ToUserName><![CDATA[' . $from . ']]></ToUserName>'
            . '<FromUserName><![CDATA[' . $to . ']]></FromUserName>'
            . '<CreateTime>' . time() . '</CreateTime>'
            . '<MsgType><![CDATA[text]]></MsgType>'
            . '<Content><![CDATA[' . $text . ']]></Content>'
            . '</xml>';

        $encrypted = $this->encryptXml($rawReply, $aesKey, $corpId);
        $query = $request->getQueryParams();
        $timestamp = trim((string)($query['timestamp'] ?? ''));
        if ($timestamp === '') {
            $timestamp = (string)time();
        }
        $nonce = trim((string)($query['nonce'] ?? ''));
        if ($nonce === '') {
            $nonce = bin2hex(random_bytes(8));
        }
        $arr = [$token, $timestamp, $nonce, $encrypted];
        sort($arr, SORT_STRING);
        $signature = sha1(implode('', $arr));

        return '<xml>'
            . '<Encrypt><![CDATA[' . $encrypted . ']]></Encrypt>'
            . '<MsgSignature><![CDATA[' . $signature . ']]></MsgSignature>'
            . '<TimeStamp>' . $timestamp . '</TimeStamp>'
            . '<Nonce><![CDATA[' . $nonce . ']]></Nonce>'
            . '</xml>';
    }

    private function decryptEcho(string $echostr, string $aesKey, string $corpId): string
    {
        $key = base64_decode($aesKey . '=', true);
        if (!is_string($key) || strlen($key) !== 32) {
            throw new ExceptionBusiness('企业微信 AesKey 配置错误');
        }

        $cipher = base64_decode($echostr, true);
        if ($cipher === false) {
            throw new ExceptionBusiness('企业微信 echostr 解密失败');
        }

        $iv = substr($key, 0, 16);
        $plain = openssl_decrypt($cipher, 'AES-256-CBC', $key, OPENSSL_RAW_DATA | OPENSSL_ZERO_PADDING, $iv);
        if (!is_string($plain) || $plain === '') {
            throw new ExceptionBusiness('企业微信 echostr 解密失败');
        }

        $plain = $this->pkcs7Unpad($plain);
        if (strlen($plain) < 20) {
            throw new ExceptionBusiness('企业微信 echostr 解密失败');
        }

        $content = substr($plain, 16);
        $length = unpack('N', substr($content, 0, 4))[1] ?? 0;
        $echo = substr($content, 4, $length);
        $receiveId = substr($content, 4 + $length);
        if ($corpId !== '' && $receiveId !== '' && !hash_equals($corpId, $receiveId)) {
            throw new ExceptionBusiness('企业微信回调校验失败');
        }
        if ($echo === '') {
            throw new ExceptionBusiness('企业微信 echostr 解密失败');
        }
        return $echo;
    }

    private function encryptXml(string $xml, string $aesKey, string $corpId): string
    {
        $key = base64_decode($aesKey . '=', true);
        if (!is_string($key) || strlen($key) !== 32) {
            throw new ExceptionBusiness('企业微信 AesKey 配置错误');
        }
        $raw = random_bytes(16) . pack('N', strlen($xml)) . $xml . $corpId;
        $raw = $this->pkcs7Pad($raw);
        $iv = substr($key, 0, 16);
        $cipher = openssl_encrypt($raw, 'AES-256-CBC', $key, OPENSSL_RAW_DATA | OPENSSL_ZERO_PADDING, $iv);
        if (!is_string($cipher) || $cipher === '') {
            throw new ExceptionBusiness('企业微信回包加密失败');
        }
        return base64_encode($cipher);
    }

    private function pkcs7Pad(string $text): string
    {
        $block = 32;
        $pad = $block - (strlen($text) % $block);
        if ($pad === 0) {
            $pad = $block;
        }
        return $text . str_repeat(chr($pad), $pad);
    }

    private function pkcs7Unpad(string $text): string
    {
        $length = strlen($text);
        if ($length === 0) {
            throw new ExceptionBusiness('企业微信消息解密失败');
        }
        $pad = ord($text[$length - 1]);
        if ($pad < 1 || $pad > 32 || $pad > $length) {
            throw new ExceptionBusiness('企业微信消息解密失败');
        }
        return substr($text, 0, $length - $pad);
    }

    private function parseXml(string $xml): array
    {
        $xml = trim($xml);
        if ($xml === '') {
            return [];
        }
        libxml_use_internal_errors(true);
        $obj = simplexml_load_string($xml, 'SimpleXMLElement', LIBXML_NOCDATA);
        if (!$obj) {
            return [];
        }
        $result = [];
        foreach ((array)$obj as $key => $value) {
            $result[(string)$key] = is_string($value) ? trim($value) : (string)$value;
        }
        return $result;
    }

    private function accessToken(BootBot $bot, string $corpId, string $secret): string
    {
        $cacheKey = 'boot.bot.wecom.token.' . $bot->id;
        $cached = (string)App::cache()->get($cacheKey, '');
        if ($cached !== '') {
            return $cached;
        }

        $result = $this->requestJson(self::TOKEN_URL, [], [], [
            'corpid' => $corpId,
            'corpsecret' => $secret,
        ], 'GET');
        if ((int)($result['errcode'] ?? 0) !== 0) {
            throw new ExceptionBusiness('企业微信 access_token 获取失败: ' . (string)($result['errmsg'] ?? 'unknown'));
        }

        $token = trim((string)($result['access_token'] ?? ''));
        if ($token === '') {
            throw new ExceptionBusiness('企业微信 access_token 获取失败');
        }
        $ttl = max(60, (int)($result['expires_in'] ?? 7200) - 120);
        App::cache()->set($cacheKey, $token, $ttl);
        return $token;
    }
}
