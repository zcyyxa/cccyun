<?php

namespace plugins\addons\blacklist;

use app\common\BaseAddon;
use plugins\addons\blacklist\lib\Service;
use think\facade\Route;

class BlacklistPlugin extends BaseAddon
{
    private static bool $syncMwDone = false;

    public static function getName(): string
    {
        return 'blacklist';
    }

    public function boot(): void
    {
        \app\lib\AddonManager::addHook('complain_auto_reply', static function (array $params): string {
            return Service::handleComplainAutoReply($params);
        }, 10);

        \app\lib\AddonManager::addHook('cron_daily', static function (array $params): string {
            return Service::handleCronDaily($params);
        }, 10);

        self::registerSyncMiddleware();
    }

    private static function registerSyncMiddleware(): void
    {
        if (self::$syncMwDone) return;
        self::$syncMwDone = true;

        try {
            $mw = app('middleware');
            if ($mw && method_exists($mw, 'route')) {
                $mw->route(\plugins\addons\blacklist\middleware\Refresh::class);
            }
        } catch (\Throwable $e) {
        }
    }

    public function registerAdminRoutes(): void
    {
        Route::group('addon/blacklist', function () {
            Route::get('info', '\plugins\addons\blacklist\controller\Admin@info');
            Route::post('test', '\plugins\addons\blacklist\controller\Admin@test');
            Route::post('check', '\plugins\addons\blacklist\controller\Admin@check');
            Route::post('report', '\plugins\addons\blacklist\controller\Admin@report');
            Route::post('sync', '\plugins\addons\blacklist\controller\Admin@sync');
            Route::post('logs', '\plugins\addons\blacklist\controller\Admin@logs');
            Route::post('retry', '\plugins\addons\blacklist\controller\Admin@retry');
            Route::post('syncAll', '\plugins\addons\blacklist\controller\Admin@syncAll');
            Route::post('taskReset', '\plugins\addons\blacklist\controller\Admin@taskReset');
            Route::post('cronFix', '\plugins\addons\blacklist\controller\Admin@cronFix');
            Route::post('pull', '\plugins\addons\blacklist\controller\Admin@pull');
            Route::post('verify', '\plugins\addons\blacklist\controller\Admin@verify');
            Route::post('pushClear', '\plugins\addons\blacklist\controller\Admin@pushClear');
            Route::post('pushReset', '\plugins\addons\blacklist\controller\Admin@pushReset');
        });
    }

    public function registerCommonRoutes(): void
    {
        Route::get('addon/blacklist/asset', '\plugins\addons\blacklist\controller\Asset@js');

        Route::get('addon/blacklist/task', '\plugins\addons\blacklist\controller\Task@run');
        Route::post('addon/blacklist/task', '\plugins\addons\blacklist\controller\Task@run');

        self::registerSyncMiddleware();
    }

    public function getAdminMenus(): array
    {
        $script = '/addon/blacklist/asset';

        return [
            [
                'parent'    => 'TradeManage',
                'sort'      => 20,
                'scriptUrl' => $script,
                'menu'      => [
                    'path'      => 'blacklist-public',
                    'name'      => 'PublicBlackList',
                    'component' => '/plugin/index',
                    'meta'      => [
                        'title'     => '公共黑名单',
                        'keepAlive' => false,
                        'roles'     => ['R_SUPER', 'R_ADMIN'],
                        'scriptUrl' => $script,
                    ],
                ],
            ],
        ];
    }

    public function install(): bool
    {
        parent::install();
        return true;
    }

    public function uninstall(): bool
    {
        parent::uninstall();
        return true;
    }
}
