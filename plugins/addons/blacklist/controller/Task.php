<?php

namespace plugins\addons\blacklist\controller;

use plugins\addons\blacklist\lib\Down;
use plugins\addons\blacklist\lib\Service;

class Task
{
    public function run()
    {
        $key = (string)request()->param('key', '');

        if (!Down::checkTaskKey($key)) {
            return json_response(403, '密钥不对。这条地址请从「公共黑名单」插件页上整条复制（换过密钥的话，记得把宝塔里那条也改掉）');
        }

        try {
            @set_time_limit(30);
        } catch (\Throwable $e) {
        }

        $r = Service::autoSync(50, 2, 4.0);

        if (empty($r['ok'])) {
            return json_response(400, (string)$r['text']);
        }

        return json_response(200, (string)$r['text'], [
            'text' => (string)$r['text'],
            'warn' => (bool)$r['warn'],
            'sent' => (int)$r['sent'],
            'left' => (int)$r['left'],
        ]);
    }
}
