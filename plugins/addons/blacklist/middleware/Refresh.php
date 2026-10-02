<?php

namespace plugins\addons\blacklist\middleware;

use plugins\addons\blacklist\lib\Down;

class Refresh
{
    public function handle($request, \Closure $next)
    {
        try {
            $path = method_exists($request, 'pathinfo') ? (string)$request->pathinfo() : '';
            if ($path === '' && method_exists($request, 'url')) {
                $path = (string)$request->url();
            }

            if (!str_contains($path, 'addon/blacklist/task')) {
                Down::maybeRun(Down::isPayPath($path));
            }
        } catch (\Throwable $e) {
        }

        return $next($request);
    }
}
