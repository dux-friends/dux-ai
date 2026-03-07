<?php

declare(strict_types=1);

namespace App\Boot\Models;

use Core\Database\Attribute\AutoMigrate;
use Core\Database\Model;
use Illuminate\Database\Schema\Blueprint;

#[AutoMigrate]
class BootBot extends Model
{
    protected $table = 'boot_bot';

    protected $casts = [
        'enabled' => 'boolean',
        'config' => 'array',
    ];

    public function migration(Blueprint $table): void
    {
        $table->id();
        $table->string('name')->comment('实例名称');
        $table->string('code')->unique()->comment('实例编码');
        $table->string('platform')->comment('平台');
        $table->boolean('enabled')->default(true)->comment('启用状态');
        $table->json('config')->nullable()->comment('平台配置');
        $table->string('verify_secret')->nullable()->comment('回调密钥');
        $table->unsignedInteger('timeout_ms')->default(10000)->comment('超时毫秒');
        $table->string('remark')->nullable()->comment('备注');
        $table->timestamps();

        $table->index('platform');
        $table->index(['platform', 'enabled']);
    }

    public function transform(): array
    {
        $domain = trim((string)\Core\App::config('use')->get('app.domain', ''));
        if ($domain !== '' && !preg_match('#^https?://#i', $domain)) {
            $domain = 'http://' . $domain;
        }
        $callbackPath = '/boot/webhook/' . $this->code;
        $callbackUrl = $domain ? rtrim($domain, '/') . $callbackPath : $callbackPath;

        return [
            'id' => $this->id,
            'name' => $this->name,
            'code' => $this->code,
            'platform' => $this->platform,
            'platform_name' => match ($this->platform) {
                'dingtalk' => '钉钉',
                'feishu' => '飞书',
                'qq_bot' => 'QQ机器人',
                'wecom' => '企业微信',
                default => $this->platform,
            },
            'enabled' => (bool)$this->enabled,
            'config' => is_array($this->config) ? $this->config : [],
            'verify_secret' => $this->verify_secret,
            'callback_path' => $callbackPath,
            'callback_base' => $domain,
            'callback_url' => $callbackUrl,
            'remark' => $this->remark,
            'created_at' => $this->created_at?->toDateTimeString(),
            'updated_at' => $this->updated_at?->toDateTimeString(),
        ];
    }
}
