<?php

namespace plugins\addons\blacklist\cron;

use app\common\AbstractCronTask;
use plugins\addons\blacklist\lib\Service;

class BlacklistSync extends AbstractCronTask
{
    public static function identifier(): string
    {
        return 'blacklist_sync';
    }

    public static function name(): string
    {
        return '公共黑名单同步';
    }

    public function execute(array $params = []): string
    {
        try {
            @set_time_limit(60);
        } catch (\Throwable $e) {
        }

        try {
            $r = Service::autoSync(50, 2, 4.0);
        } catch (\Throwable $e) {
            return '公共黑名单同步出错：' . $e->getMessage();
        }

        if (empty($r['ok'])) {
            return '公共黑名单：' . (string)($r['text'] ?? '平台接口地址/密钥还没配置');
        }

        return '公共黑名单：' . (string)($r['text'] ?? '这一轮没什么可做的');
    }
}
