<?php

namespace plugins\addons\blacklist\controller;

class Asset
{
    public function js()
    {
        $file = dirname(__DIR__) . '/static/admin.js';

        if (!is_file($file)) {
            $js = 'console.error("[公共黑名单] 插件脚本文件丢了：plugins/addons/blacklist/static/admin.js");';
        } else {
            $js = (string)file_get_contents($file);
        }

        return response($js, 200, [
            'Content-Type'  => 'application/javascript; charset=utf-8',
            'Cache-Control' => 'no-cache, no-store, must-revalidate',
        ]);
    }
}
