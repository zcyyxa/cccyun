<?php

namespace plugins\addons\blacklist\lib;

use think\facade\Db;

class Service
{
    const SYNC_BATCH = 50;

    const LOG_THROTTLE = 300;

    private static ?array $config = null;

    private static ?Client $client = null;

    public static function config(): array
    {
        if (self::$config !== null) return self::$config;

        $saved = function_exists('plugin_config_get') ? \plugin_config_get('addons', 'blacklist') : [];
        if (!is_array($saved)) $saved = [];

        return self::$config = array_merge(self::defaults(), $saved);
    }

    private static function defaults(): array
    {
        return [
            'api_url'         => '',
            'api_key'         => '',
            'timeout'         => '3',
            'complaint_sync'  => '0',
            'complaint_days'  => '7',
            'link_complaint'  => '1',
            'sync_days'       => '180',
            'skip_list'       => '',
            'auto_sync'       => '0',
            'auto_sync_limit' => '50',
            'auto_refresh'    => '0',
            'report_days'     => '7',
            'push_enable'      => '1',
            'pull_interval'    => '60',
            'pay_interval'     => '10',
            'pull_type'        => '0',
            'pull_min_reports' => '1',
            'verify_ttl'       => '1800',
        ];
    }

    public static function client(): Client
    {
        return self::$client ??= new Client(self::config());
    }

    public static function reset(): void
    {
        self::$config = null;
        self::$client = null;
    }

    public static function flag(string $key): bool
    {
        return (string)(self::config()[$key] ?? '0') === '1';
    }

    public static function num(string $key, int $default, int $min, int $max): int
    {
        $v = (int)(self::config()[$key] ?? $default);
        if ($v < $min) return $min;
        if ($v > $max) return $max;
        return $v;
    }

    public static function handleComplainAutoReply(array $params): string
    {
        try {
            $thirdid = trim((string)($params['thirdid'] ?? ''));
            $status  = (int)($params['status'] ?? 0);

            if ($thirdid === '' || $status >= 2) return '';
            if (!self::flag('complaint_sync')) return '';
            if (!self::client()->ready()) return '';

            self::reportComplaintByThirdId($thirdid, (string)($params['complaint_content'] ?? ''));
        } catch (\Throwable $e) {
            self::log('error', 0, '', 0, '投诉上报异常：' . $e->getMessage());
        }
        return '';
    }

    private static function reportComplaintByThirdId(string $thirdid, string $fallbackContent = ''): ?array
    {
        $complain = self::findComplainByThirdId($thirdid);
        if (!$complain) {
            self::log('report', 0, '', 0, '找不到投诉记录（thirdid=' . $thirdid . '），未上报');
            return null;
        }

        $tradeNo = trim((string)($complain['trade_no'] ?? ''));
        if ($tradeNo === '') {
            $list = trim((string)($complain['trade_no_list'] ?? ''));
            if ($list !== '') $tradeNo = trim(explode(',', $list)[0]);
        }
        if ($tradeNo === '') {
            self::log('report', 0, '', 0, '这条投诉没有关联订单，平台不收，未上报');
            return null;
        }

        if (self::ledgerFindByTradeNo($tradeNo)) {
            return null;
        }

        $order = self::findOrder($tradeNo);
        if (!$order) {
            self::log('report', 0, '', 0, '订单 ' . $tradeNo . ' 已经查不到了，未上报', $tradeNo);
            return null;
        }

        $buyer  = trim((string)($order['buyer'] ?? ''));
        $mobile = trim((string)($order['mobile'] ?? ''));
        $ip     = trim((string)($order['ip'] ?? ''));

        if ($buyer !== '') {
            $type = 0; $content = $buyer;
        } elseif ($mobile !== '') {
            $type = 0; $content = $mobile;
        } elseif (filter_var($ip, FILTER_VALIDATE_IP)) {
            $type = 1; $content = $ip;
        } else {
            self::log('report', 0, '', 0, '这笔订单没有买家账号/手机号/IP，未上报', $tradeNo);
            return null;
        }

        $text = trim((string)($complain['content'] ?? ''));
        if ($text === '') $text = trim($fallbackContent);
        if ($text === '') {
            self::log('report', $type, $content, 0, '投诉正文是空的，平台不收，未上报', $tradeNo);
            return null;
        }

        return self::sendReport($type, $content, self::num('complaint_days', 7, 1, 36500), [
            'trade_no'          => $tradeNo,
            'is_complaint'      => 1,
            'order_buyer'       => $buyer,
            'order_mobile'      => $mobile,
            'order_ip'          => $ip,
            'complaint_title'   => (string)($complain['title'] ?? ''),
            'complaint_content' => $text,
            'complaint_type'    => (string)($complain['type'] ?? ''),
            'complaint_time'    => (string)($complain['addtime'] ?? ''),
        ], 'complaint');
    }

    public static function handleCronDaily(array $params): string
    {
        try {
            if (!self::client()->ready()) return '';

            $done = [];

            if (self::flag('auto_sync')) {
                $r = self::syncLocal(self::num('auto_sync_limit', 50, 1, 500));
                $done[] = '自动同步 成功' . $r['sent'] . '条/失败' . $r['failed'] . '条/剩余' . $r['left'] . '条';
            }

            if (self::flag('auto_refresh')) {
                $r = self::refreshStatus();
                $done[] = '刷新台账 ' . $r['updated'] . '条';
            }

            if (self::flag('push_enable')) {
                $r = Down::run(true);
                if (empty($r['skipped'])) {
                    $s = Down::sweep(4);
                    $done[] = '下发 拉新' . (int)$r['pulled'] . '条/写库' . (int)$r['pushed'] . '条，复核'
                        . ((int)$r['verified'] + (int)$s['checked']) . '条';
                    if ((int)$r['revoked'] + (int)$s['revoked'] > 0) {
                        $done[] = '撤下 ' . ((int)$r['revoked'] + (int)$s['revoked']) . '条';
                    }
                } else {
                    $done[] = '下发这轮跳过：' . $r['msg'];
                }
            }

            if ($done) {
                self::log('cron', 0, '', 1, implode('；', $done));
            }
        } catch (\Throwable $e) {
            self::log('error', 0, '', 0, '定时任务异常：' . $e->getMessage());
        }
        return '';
    }

    public static function localPending(): array
    {
        $done = [];
        foreach (self::ledgerAll() as $r) {
            $done[(int)$r['type'] . '|' . $r['content']] = true;
        }

        $rows  = [];
        $total = 0;
        try {
            $list = Db::name('blacklist')->field('type,content,endtime,remark')->select()->toArray();
        } catch (\Throwable $e) {
            $list = [];
        }

        foreach ($list as $row) {
            $total++;
            $type    = ((int)$row['type'] === 1) ? 1 : 0;
            $content = trim((string)$row['content']);
            if ($content === '') continue;
            if (isset($done[$type . '|' . $content])) continue;
            if (self::isSkipped($content)) continue;

            if (Down::isOurs((string)($row['remark'] ?? ''))) continue;

            $days = self::daysFromEntry($row, self::num('sync_days', 180, 1, 36500));
            if ($days <= 0) continue;

            $rows[] = [
                'type'    => $type,
                'content' => $content,
                'days'    => $days,
                'remark'  => (string)($row['remark'] ?? ''),
            ];
        }

        return ['rows' => $rows, 'done' => count($done), 'total' => $total];
    }

    public static function syncLocal(int $limit = 0): array
    {
        $limit = $limit > 0 ? $limit : self::SYNC_BATCH;
        $pend  = self::localPending();
        $rows  = array_slice($pend['rows'], 0, $limit);

        $out = ['pending' => count($pend['rows']), 'sent' => 0, 'failed' => 0, 'skipped' => 0, 'left' => 0, 'details' => []];

        foreach ($rows as $row) {
            $type    = (int)$row['type'];
            $content = (string)$row['content'];

            if ($type === 1 && !filter_var($content, FILTER_VALIDATE_IP)) {
                $out['skipped']++;
                $out['details'][] = ['content' => $content, 'ok' => false, 'msg' => '不是合法 IP，跳过', 'days' => 0];
                continue;
            }

            $days    = (int)$row['days'];
            $payload = [];
            $source  = 'sync';

            $matched = self::flag('link_complaint') ? self::findComplaintFor($content, $type) : null;
            if ($matched && !self::ledgerFindByTradeNo((string)$matched['trade_no'])) {
                $payload = [
                    'trade_no'          => (string)$matched['trade_no'],
                    'is_complaint'      => 1,
                    'order_buyer'       => (string)$matched['buyer'],
                    'order_mobile'      => (string)$matched['mobile'],
                    'order_ip'          => (string)$matched['ip'],
                    'complaint_title'   => (string)$matched['title'],
                    'complaint_content' => (string)$matched['content'],
                    'complaint_type'    => (string)$matched['type'],
                    'complaint_time'    => (string)$matched['addtime'],
                ];
                $source = 'complaint';
            }

            $res = self::sendReport($type, $content, $days, $payload, $source);

            if ($res && $res['ok']) {
                $out['sent']++;
            } else {
                $out['failed']++;
            }
            $out['details'][] = [
                'content' => $content,
                'ok'      => (bool)($res['ok'] ?? false),
                'msg'     => (string)($res['msg'] ?? ''),
                'days'    => $days,
                'complaint' => $source === 'complaint',
            ];
        }

        $out['left'] = max(0, count($pend['rows']) - $out['sent'] - $out['failed'] - $out['skipped']);

        return $out;
    }

    private static function sendReport(int $type, string $content, int $days, array $payload, string $source): array
    {
        $data = array_merge(['type' => $type, 'content' => $content, 'days' => $days], $payload);

        $res = self::client()->report($data);

        if ($res['ok']) {
            $tradeNo = trim((string)($payload['trade_no'] ?? ''));
            self::saveLedger($type, $content, $tradeNo !== '' ? $tradeNo : null, $days, $res['data'], $source);
            self::log('report', $type, $content, 1,
                ($source === 'complaint' ? '投诉上报成功' : '上报成功') . '：' . $res['msg'], $tradeNo);

            self::pushDown($type, $content, (array)$res['data']);
        } else {
            self::log('report', $type, $content, 0, '上报失败：' . $res['msg'], (string)($payload['trade_no'] ?? ''));
        }

        return $res;
    }

    private static function pushDown(int $type, string $content, array $data): void
    {
        try {
            Down::pushEntryData((int)($data['entry_id'] ?? 0), $type, $content, $data);
        } catch (\Throwable $e) {
        }
    }

    private static function daysFromEntry(array $row, int $default): int
    {
        $end = trim((string)($row['endtime'] ?? ''));
        if ($end === '' || str_starts_with($end, '0000-00-00')) return $default;

        $ts = strtotime($end);
        if ($ts === false) return $default;

        $days = (int)ceil(($ts - time()) / 86400);
        if ($days <= 0) return 0;
        return min($days, $default);
    }

    public static function isSkipped(string $content): bool
    {
        $raw = trim((string)(self::config()['skip_list'] ?? ''));
        if ($raw === '') return false;

        foreach (preg_split('/[,，\s]+/u', $raw) as $one) {
            $one = trim($one);
            if ($one !== '' && strcasecmp($one, $content) === 0) return true;
        }
        return false;
    }

    private static function findComplaintFor(string $content, int $type): ?array
    {
        try {
            $q = Db::name('complain')->alias('a')
                ->join('order b', 'a.trade_no = b.trade_no')
                ->field('a.trade_no,a.title,a.content,a.type,a.addtime,b.buyer,b.mobile,b.ip')
                ->order('a.id', 'desc')
                ->limit(1);

            if ($type === 1) {
                if (!filter_var($content, FILTER_VALIDATE_IP)) return null;
                $q->where('b.ip', $content);
            } else {
                $q->where(function ($w) use ($content) {
                    $w->where('b.buyer', $content)->whereOr('b.mobile', $content);
                });
            }

            $row = $q->find();
            if (!$row) return null;

            $tradeNo = trim((string)($row['trade_no'] ?? ''));
            $text    = trim((string)($row['content'] ?? ''));
            if ($tradeNo === '' || $text === '') return null;

            return $row;
        } catch (\Throwable $e) {
            return null;
        }
    }

    public static function autoSync(int $upLimit = 0, int $upRounds = 4, float $budget = 5.0): array
    {
        $out = [
            'ok'     => true,
            'text'   => '',
            'warn'   => false,
            'active' => false,
            'down'   => [],
            'sent'   => 0,
            'failed' => 0,
            'skip'   => 0,
            'left'   => 0,
        ];

        if (!self::client()->ready()) {
            $out['ok']   = false;
            $out['warn'] = true;
            $out['text'] = '还没配置平台接口地址和密钥，先去插件配置里填上';
            return $out;
        }

        $upLimit  = $upLimit > 0 ? $upLimit : self::SYNC_BATCH;
        $upRounds = max(1, min($upRounds, 10));
        $parts    = [];
        $warn     = false;

        try {
            $d = Down::run(true);
        } catch (\Throwable $e) {
            $d = ['ok' => false, 'msg' => $e->getMessage()];
        }
        $out['down'] = $d;

        if (!empty($d['ok']) && !empty($d['skipped'])) {
            $parts[] = '下发：' . (string)($d['msg'] ?? '跳过');
        } elseif (!empty($d['skipped'])) {
            $parts[] = '下发拦截没开，这半边跳过了';
            $warn    = true;
        } elseif (empty($d['ok'])) {
            $parts[] = '拉取失败（' . (string)($d['msg'] ?? '') . '）';
            $warn    = true;
        } else {
            $pulled = (int)($d['pulled'] ?? 0);
            $t = $pulled > 0
                ? ('平台下来 ' . $pulled . ' 条，拦上 ' . (int)($d['pushed'] ?? 0) . ' 条')
                : '平台没有新条目';
            if ((int)($d['revoked'] ?? 0) > 0) $t .= '，撤下 ' . (int)$d['revoked'] . ' 条';
            if ((int)($d['failed'] ?? 0) > 0) {
                $t .= '（有一次请求失败，下一轮会自己重来）';
                $warn = true;
            }
            $parts[] = $t;
            if ($pulled > 0 || (int)($d['pushed'] ?? 0) > 0 || (int)($d['revoked'] ?? 0) > 0) {
                $out['active'] = true;
            }
        }

        $sent = 0; $failed = 0; $skip = 0; $left = 0;
        $t0   = microtime(true);
        $upErr = '';
        $rounds = 0;

        try {
            for ($round = 0; $round < $upRounds; $round++) {
                $rounds++;
                $u = self::syncLocal($upLimit);
                $sent   += (int)$u['sent'];
                $failed += (int)$u['failed'];
                $skip   += (int)$u['skipped'];
                $left    = (int)$u['left'];

                if ($left <= 0 || $failed > 0 || (microtime(true) - $t0) > $budget) break;
            }
        } catch (\Throwable $e) {
            $upErr   = $e->getMessage();
            $parts[] = '上报出错：' . $upErr;
            $warn    = true;
        }

        $out['sent']   = $sent;
        $out['failed'] = $failed;
        $out['skip']   = $skip;
        $out['left']   = $left;

        if ($sent > 0) {
            $t = '本站报上去 ' . $sent . ' 条';
            if ($failed > 0) { $t .= '，失败 ' . $failed . ' 条'; $warn = true; }
            if ($left > 0)   { $t .= '；还剩 ' . $left . ' 条，下一轮接着报'; $warn = true; }
            $parts[] = $t;
            $out['active'] = true;
        } elseif ($failed > 0) {
            $parts[] = '上报失败 ' . $failed . ' 条';
            $warn    = true;
        } elseif ($left > 0) {
            $parts[] = '本站没有要报的（' . ($skip > 0 ? $skip . ' 条格式不对，跳过了' : '剩 ' . $left . ' 条报不出去') . '）';
            $warn    = true;
        } else {
            $parts[] = '本站没有要报的';
        }

        $out['text'] = (!$out['active'] && !$warn && $failed === 0 && $sent === 0 && empty($d['skipped']))
            ? '同步完成，两边都是最新的'
            : implode('；', $parts);
        $out['warn'] = $warn;

        if ($out['active'] || $failed > 0 || empty($d['ok'])) {
            self::log('cron', 0, '', ($failed > 0 || empty($d['ok'])) ? 0 : 1, '自动同步：' . $out['text']);
        }

        return $out;
    }

    public static function ledgerAll(int $limit = 0): array
    {
        try {
            $q = Db::name('blacklist_sync')->order('id', 'desc');
            if ($limit > 0) $q->limit($limit);
            return $q->select()->toArray();
        } catch (\Throwable $e) {
            return [];
        }
    }

    private static function ledgerFindByTradeNo(string $tradeNo): ?array
    {
        if ($tradeNo === '') return null;
        try {
            $row = Db::name('blacklist_sync')->where('trade_no', $tradeNo)->find();
            return $row ?: null;
        } catch (\Throwable $e) {
            return null;
        }
    }

    private static function ledgerFind(int $id): ?array
    {
        try {
            $row = Db::name('blacklist_sync')->where('id', $id)->find();
            return $row ?: null;
        } catch (\Throwable $e) {
            return null;
        }
    }

    public static function saveLedger(int $type, string $content, ?string $tradeNo, int $days, array $data, string $source): void
    {
        $now = date('Y-m-d H:i:s');
        $row = [
            'type'       => $type,
            'content'    => $content,
            'trade_no'   => ($tradeNo !== null && $tradeNo !== '') ? $tradeNo : null,
            'report_id'  => (int)($data['report_id'] ?? 0),
            'entry_id'   => (int)($data['entry_id'] ?? 0),
            'days'       => $days,
            'total_days' => (int)($data['total_days'] ?? 0),
            'endtime'    => !empty($data['endtime']) ? (string)$data['endtime'] : null,
            'permanent'  => !empty($data['permanent']) ? 1 : 0,
            'status'     => 1,
            'msg'        => mb_substr((string)($data['msg'] ?? ''), 0, 200),
            'source'     => $source,
            'addtime'    => $now,
            'updatetime' => $now,
        ];

        try {
            $exists = Db::name('blacklist_sync')->where(['type' => $type, 'content' => $content])->find();
            if ($exists) {
                $row['addtime'] = $exists['addtime'];
                Db::name('blacklist_sync')->where('id', $exists['id'])->update($row);
            } else {
                Db::name('blacklist_sync')->insert($row);
            }
        } catch (\Throwable $e) {
        }
    }

    public static function retry(int $id): array
    {
        $row = self::ledgerFind($id);
        if (!$row) return ['ok' => false, 'msg' => '台账里没这条记录'];
        if (!self::client()->ready()) return ['ok' => false, 'msg' => '还没配置平台接口地址和密钥'];

        $type    = (int)$row['type'];
        $content = (string)$row['content'];
        $tradeNo = trim((string)($row['trade_no'] ?? ''));
        $days    = (int)$row['days'] > 0 ? (int)$row['days'] : self::num('report_days', 7, 1, 36500);

        $payload = [];

        if ($tradeNo !== '' && (string)$row['source'] === 'complaint') {
            $complain = self::findComplainByTradeNo($tradeNo);
            $order    = self::findOrder($tradeNo);
            if (!$complain || !$order) {
                return ['ok' => false, 'msg' => '本地已经找不到这笔订单的投诉记录了，只能当普通上报重报'];
            }
            $payload = [
                'trade_no'          => $tradeNo,
                'is_complaint'      => 1,
                'order_buyer'       => (string)($order['buyer'] ?? ''),
                'order_mobile'      => (string)($order['mobile'] ?? ''),
                'order_ip'          => (string)($order['ip'] ?? ''),
                'complaint_title'   => (string)($complain['title'] ?? ''),
                'complaint_content' => (string)($complain['content'] ?? ''),
                'complaint_type'    => (string)($complain['type'] ?? ''),
                'complaint_time'    => (string)($complain['addtime'] ?? ''),
            ];
        }

        $res = self::sendReport($type, $content, $days, $payload, (string)$row['source']);

        return ['ok' => $res['ok'], 'msg' => $res['msg']];
    }

    public static function refreshStatus(): array
    {
        if (!self::client()->ready()) return ['ok' => false, 'msg' => '还没配置平台接口地址和密钥', 'updated' => 0];

        $rows    = self::ledgerAll(50);
        $updated = 0;

        foreach ($rows as $row) {
            $rid = (int)$row['report_id'];
            if ($rid <= 0) continue;

            $res = self::client()->status(['report_id' => $rid]);
            if (!$res['ok']) continue;

            $st = (int)($res['data']['status'] ?? 0);
            if ($st <= 0 || $st === (int)$row['status']) continue;

            try {
                Db::name('blacklist_sync')->where('id', (int)$row['id'])->update([
                    'status'     => $st,
                    'msg'        => mb_substr((string)($res['data']['audit_remark'] ?? $res['data']['status_text'] ?? ''), 0, 200),
                    'updatetime' => date('Y-m-d H:i:s'),
                ]);
                $updated++;
            } catch (\Throwable $e) {
            }
        }

        return ['ok' => true, 'msg' => '刷新了 ' . $updated . ' 条', 'updated' => $updated];
    }

    private static function findComplainByThirdId(string $thirdid): ?array
    {
        try {
            $row = Db::name('complain')->where('thirdid', $thirdid)->order('id', 'desc')->find();
            return $row ?: null;
        } catch (\Throwable $e) {
            return null;
        }
    }

    private static function findComplainByTradeNo(string $tradeNo): ?array
    {
        if ($tradeNo === '') return null;
        try {
            $row = Db::name('complain')->where('trade_no', $tradeNo)->order('id', 'desc')->find();
            if (!$row) {
                $row = Db::name('complain')->whereLike('trade_no_list', '%' . $tradeNo . '%')->order('id', 'desc')->find();
            }
            return $row ?: null;
        } catch (\Throwable $e) {
            return null;
        }
    }

    private static function findOrder(string $tradeNo): ?array
    {
        if ($tradeNo === '') return null;
        try {
            $row = Db::name('order')->where('trade_no', $tradeNo)->field('trade_no,buyer,mobile,ip,uid,realmoney')->find();
            return $row ?: null;
        } catch (\Throwable $e) {
            return null;
        }
    }

    public static function log(string $event, int $type, string $content, int $status, string $msg, string $tradeNo = ''): void
    {
        try {
            if ($status === 0 && $msg !== '') {
                $key = 'bl_log_' . md5($event . '|' . $type . '|' . $msg);
                if (self::cacheGet($key)) return;
                self::cacheSet($key, 1, self::LOG_THROTTLE);
            }

            Db::name('blacklist_log')->insert([
                'event'    => mb_substr($event, 0, 20),
                'type'     => $type,
                'content'  => mb_substr($content, 0, 80),
                'status'   => $status,
                'msg'      => mb_substr($msg, 0, 250),
                'trade_no' => $tradeNo !== '' ? mb_substr($tradeNo, 0, 32) : null,
                'addtime'  => date('Y-m-d H:i:s'),
            ]);
        } catch (\Throwable $e) {
        }
    }

    public static function logs(int $limit = 50): array
    {
        try {
            return Db::name('blacklist_log')->order('id', 'desc')->limit($limit)->select()->toArray();
        } catch (\Throwable $e) {
            return [];
        }
    }

    public static function stats(): array
    {
        $out = ['sent' => 0, 'failed' => 0, 'cron' => 0, 'last_cron' => ''];
        try {
            $since = date('Y-m-d H:i:s', time() - 86400);

            $out['sent'] = (int)Db::name('blacklist_log')
                ->whereIn('event', ['report', 'sync', 'retry'])
                ->where('status', 1)->where('addtime', '>=', $since)->count();

            $out['failed'] = (int)Db::name('blacklist_log')
                ->where('status', 0)->where('addtime', '>=', $since)->count();

            $out['cron'] = (int)Db::name('blacklist_log')
                ->where('event', 'cron')->where('addtime', '>=', $since)->count();

            $last = Db::name('blacklist_log')->where('event', 'cron')->order('id', 'desc')->value('addtime');
            $out['last_cron'] = (string)($last ?: '');
        } catch (\Throwable $e) {
        }
        return $out;
    }

    private static function cacheGet(string $key)
    {
        try {
            return function_exists('cache') ? cache($key) : null;
        } catch (\Throwable $e) {
            return null;
        }
    }

    private static function cacheSet(string $key, $value, int $ttl): void
    {
        if ($ttl <= 0) return;
        try {
            if (function_exists('cache')) cache($key, $value, $ttl);
        } catch (\Throwable $e) {
        }
    }
}
