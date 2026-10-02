<?php

namespace plugins\addons\blacklist\controller;

use plugins\addons\blacklist\lib\Down;
use plugins\addons\blacklist\lib\Service;

class Admin
{

    public function info()
    {
        try {
            Service::reset();
            return json_response(200, 'ok', self::infoData());
        } catch (\Throwable $e) {
            return json_response(200, 'ok', [
                'err'            => '插件状态读取出错：' . $e->getMessage(),
                'ready'          => false,
                'plugin_version' => self::pluginVersion(),
                'down'           => [],
                'pending'        => ['count' => 0, 'local' => 0, 'synced' => 0],
                'stats'          => [],
                'cron'           => self::cronBlock(),
                'task_url'       => '',
            ]);
        }
    }

    private static function infoData(): array
    {
        $client = Service::client();
        $c      = Service::config();

        $pend = ['rows' => [], 'done' => 0, 'total' => 0];
        try {
            $pend = Service::localPending();
        } catch (\Throwable $e) {
        }

        return [
            'ready'    => $client->ready(),
            'api_url'  => $client->baseUrl(),
            'api_key'  => self::maskKey($client->key()),
            'timeout'  => (string)($c['timeout'] ?? '3'),
            'complaint_sync'  => (string)($c['complaint_sync'] ?? '0'),
            'complaint_days'  => (string)($c['complaint_days'] ?? '7'),
            'link_complaint'  => (string)($c['link_complaint'] ?? '1'),
            'sync_days'       => (string)($c['sync_days'] ?? '180'),
            'skip_list'       => (string)($c['skip_list'] ?? ''),
            'auto_sync'       => (string)($c['auto_sync'] ?? '0'),
            'auto_sync_limit' => (string)($c['auto_sync_limit'] ?? '50'),
            'auto_refresh'    => (string)($c['auto_refresh'] ?? '0'),
            'report_days_default' => (string)($c['report_days'] ?? '7'),
            'push_enable'      => (string)($c['push_enable'] ?? '0'),
            'pay_interval'     => (string)($c['pay_interval'] ?? '10'),
            'pull_interval'    => (string)($c['pull_interval'] ?? '60'),
            'pull_type'        => (string)($c['pull_type'] ?? '0'),
            'pull_min_reports' => (string)($c['pull_min_reports'] ?? '1'),
            'verify_ttl'       => (string)($c['verify_ttl'] ?? '1800'),
            'plugin_version' => self::pluginVersion(),
            'stats'    => Service::stats(),
            'down'     => Down::stats(),
            'pending'  => ['count' => count($pend['rows']), 'local' => (int)$pend['total'], 'synced' => (int)$pend['done']],
            'cron'     => self::cronBlock(),
            'task_url' => self::taskUrl(),
        ];
    }

    private static function cronBlock(): array
    {
        return [
            'mode'        => (string)self::cfg('cron_mode', 'url'),
            'swoole'      => extension_loaded('swoole'),
            'daemon_time' => (string)self::cfg('cron_task_time', ''),
            'task'        => Down::sysTaskInfo(),
        ];
    }

    private static function cfg(string $key, string $default = ''): mixed
    {
        try {
            return function_exists('config_get') ? config_get($key, $default) : $default;
        } catch (\Throwable $e) {
            return $default;
        }
    }

    private static function taskUrl(): string
    {
        $host = '';
        $root = '';
        try {
            $host = rtrim((string)request()->domain(), '/');
        } catch (\Throwable $e) {
        }
        try {
            if (method_exists(request(), 'root')) {
                $root = rtrim((string)request()->root(), '/');
            }
        } catch (\Throwable $e) {
        }

        return $host . $root . '/addon/blacklist/task?key=' . Down::taskKey();
    }

    private static function maskKey(string $key): string
    {
        if ($key === '') return '';
        $len = strlen($key);
        if ($len <= 8) return str_repeat('*', $len);
        return substr($key, 0, 4) . str_repeat('*', min(20, $len - 8)) . substr($key, -4);
    }

    private static function pluginVersion(): string
    {
        $f = dirname(__DIR__) . '/info.json';
        if (!is_file($f)) return '';
        $info = json_decode((string)file_get_contents($f), true);
        return (string)($info['version'] ?? '');
    }

    public function test()
    {
        if (!Service::client()->ready()) {
            return json_response(400, '还没配置平台接口地址和密钥，先去插件配置里填上');
        }

        $t0  = microtime(true);
        $res = Service::client()->ping();
        $ms  = (int)round((microtime(true) - $t0) * 1000);

        if (!$res['ok']) {
            Service::log('check', 0, '', 0, '测试连接失败：' . $res['msg']);
            return json_response(400, '连接失败：' . $res['msg'] . '（用时 ' . $ms . ' 毫秒）');
        }

        $hit = !empty($res['data']['hit']);
        if ($hit) {
            return json_response(400, '连上了，但探测请求被判成命中，请确认平台地址填对了');
        }

        return json_response(200, '连接正常，用时 ' . $ms . ' 毫秒', ['ms' => $ms]);
    }

    public function check()
    {
        $type    = ((int)request()->param('type', 0) === 1) ? 1 : 0;
        $content = trim((string)request()->param('content', ''));

        if ($content === '') return json_response(400, '请填要查的内容');
        if ($type === 1 && !filter_var($content, FILTER_VALIDATE_IP)) {
            return json_response(400, '这一条要填合法 IP');
        }
        if (!Service::client()->ready()) return json_response(400, '还没配置平台接口地址和密钥');

        $res = Service::client()->check([['type' => $type, 'content' => $content]]);
        if (!$res['ok']) return json_response(400, '查询失败：' . $res['msg']);

        $data = $res['data'];
        $one  = [];
        foreach ((array)($data['results'] ?? []) as $r) {
            if ((int)($r['type'] ?? -1) === $type && (string)($r['content'] ?? '') === $content) {
                $one = $r;
                break;
            }
        }

        return json_response(200, 'ok', [
            'hit'        => !empty($data['hit']),
            'total_days' => (int)($one['total_days'] ?? 0),
            'endtime'    => (string)($one['endtime'] ?? ''),
            'permanent'  => !empty($one['permanent']),
            'reports'    => (int)($one['report_count'] ?? 0),
            'reason'     => (string)($one['reason'] ?? ''),
            'server_time'=> (string)($data['server_time'] ?? ''),
            'skipped'    => Service::isSkipped($content),
        ]);
    }

    public function report()
    {
        $type    = ((int)request()->param('type', 0) === 1) ? 1 : 0;
        $content = trim((string)request()->param('content', ''));
        $days    = (int)request()->param('days', 0);
        $remark  = trim((string)request()->param('remark', ''));

        if ($content === '') return json_response(400, '请填要上报的内容');
        if ($type === 1 && !filter_var($content, FILTER_VALIDATE_IP)) {
            return json_response(400, '报 IP 的话得填合法 IP');
        }
        if (!Service::client()->ready()) return json_response(400, '还没配置平台接口地址和密钥');
        if ($days <= 0) $days = 7;
        if ($days > 36500) $days = 36500;

        $res = Service::client()->report([
            'type'    => $type,
            'content' => $content,
            'days'    => $days,
        ]);

        Service::log('report', $type, $content, $res['ok'] ? 1 : 0,
            ($res['ok'] ? '手动上报成功' : '手动上报失败：' . $res['msg']) . ($remark !== '' ? '（' . $remark . '）' : ''));

        if (!$res['ok']) return json_response(400, '上报失败：' . $res['msg']);

        $d = $res['data'];

        Service::saveLedger($type, $content, null, $days, $d, 'manual');

        return json_response(200, '上报成功', [
            'report_id'     => (int)($d['report_id'] ?? 0),
            'days'          => (int)($d['days'] ?? $days),
            'accepted_days' => (int)($d['accepted_days'] ?? 0),
            'total_days'    => (int)($d['total_days'] ?? 0),
            'endtime'       => (string)($d['endtime'] ?? ''),
            'permanent'     => !empty($d['permanent']),
        ]);
    }

    public function sync()
    {
        if (!Service::client()->ready()) return json_response(400, '还没配置平台接口地址和密钥');

        $dry = (int)request()->param('dry', 0) === 1;

        if ($dry) {
            $pend = Service::localPending();
            return json_response(200, 'ok', [
                'pending' => count($pend['rows']),
                'local'   => (int)$pend['total'],
                'synced'  => (int)$pend['done'],
                'sample'  => array_slice($pend['rows'], 0, 10),
            ]);
        }

        $r = Service::syncLocal();
        $msg = '这次报上去 ' . $r['sent'] . ' 条';
        if ($r['failed'] > 0) $msg .= '，失败 ' . $r['failed'] . ' 条';
        if ($r['skipped'] > 0) $msg .= '，跳过 ' . $r['skipped'] . ' 条';
        if ($r['left'] > 0)   $msg .= '；还剩 ' . $r['left'] . ' 条，再点一次继续';

        return json_response(200, $msg, [
            'sent'    => $r['sent'],
            'failed'  => $r['failed'],
            'skipped' => $r['skipped'],
            'left'    => $r['left'],
            'pending' => $r['pending'],
            'details' => array_slice($r['details'], 0, 30),
        ]);
    }

    public function syncAll()
    {
        $r = Service::autoSync(50, 8, 10.0);

        if (empty($r['ok'])) return json_response(400, (string)$r['text']);

        $out = [
            'text' => (string)$r['text'],
            'warn' => (bool)$r['warn'],
            'down' => Down::stats(),
        ];

        try {
            $p = Service::localPending();
            $out['pending'] = [
                'count' => count($p['rows']),
                'local' => (int)$p['total'],
                'synced'=> (int)$p['done'],
            ];
        } catch (\Throwable $e) {
        }

        return json_response(200, $out['text'], $out);
    }

    public function cronFix()
    {
        $r = Down::ensureSysTask();

        return json_response(!empty($r['ok']) ? 200 : 400, (string)$r['msg'], ['cron' => self::cronBlock()]);
    }

    public function taskReset()
    {
        Down::resetTaskKey();
        return json_response(200, '换好了，老地址已经失效，把下面这条新地址填到宝塔的计划任务里', [
            'task_url' => self::taskUrl(),
        ]);
    }

    public function logs()
    {
        $act = (string)request()->param('act', 'list');

        if ($act === 'refresh') {
            $r = Service::refreshStatus();
            return json_response($r['ok'] ? 200 : 400, $r['msg'], ['updated' => (int)($r['updated'] ?? 0)]);
        }

        return json_response(200, 'ok', [
            'logs'   => Service::logs((int)request()->param('log_limit', 50) ?: 50),
            'ledger' => Service::ledgerAll((int)request()->param('ledger_limit', 50) ?: 50),
        ]);
    }

    public function retry()
    {
        $id = (int)request()->param('id', 0);
        if ($id <= 0) return json_response(400, '没指定要重报哪一条');

        $r = Service::retry($id);
        return json_response($r['ok'] ? 200 : 400, $r['ok'] ? ('重新上报成功：' . $r['msg']) : $r['msg']);
    }

    public function pull()
    {
        $r = Down::run(true);

        if (empty($r['ok'])) return json_response(400, (string)$r['msg']);
        if (!empty($r['skipped'])) return json_response(200, (string)$r['msg'], ['skipped' => true]);

        $msg = '这一轮：拉新 ' . (int)$r['pulled'] . ' 条（下发 ' . (int)$r['pushed'] . ' 条）'
             . '，复核 ' . (int)$r['verified'] . ' 条';
        if ((int)$r['revoked'] > 0) $msg .= '，撤下 ' . (int)$r['revoked'] . ' 条';
        if ((int)$r['failed'] > 0) $msg .= '；有一次请求失败，下一轮会重来';

        return json_response(200, $msg, ['down' => Down::stats()]);
    }

    public function verify()
    {
        if (!Down::enabled()) return json_response(400, '「下发拦截」是关的，或者平台地址密钥还没填');

        $r = Down::sweep(4, true);
        $msg = '复核了 ' . (int)$r['checked'] . ' 条';
        if ((int)$r['pushed'] > 0)  $msg .= '，其中 ' . (int)$r['pushed'] . ' 条补下发/刷新';
        if ((int)$r['revoked'] > 0) $msg .= '，撤下 ' . (int)$r['revoked'] . ' 条';
        if ((int)$r['failed'] > 0)  $msg .= '；有一次请求失败，这批下次还会再查';

        return json_response(200, $msg, ['down' => Down::stats()]);
    }

    public function pushClear()
    {
        $r = Down::clearPushed();
        return json_response(!empty($r['ok']) ? 200 : 400, (string)$r['msg'], ['down' => Down::stats()]);
    }

    public function pushReset()
    {
        $r = Down::repush(500);
        $msg = '重新下发 ' . (int)$r['done'] . ' 条';
        if ((int)$r['left'] > 0) $msg .= '，还剩 ' . (int)$r['left'] . ' 条，再点一次继续';

        return json_response(200, $msg, ['down' => Down::stats()]);
    }
}
