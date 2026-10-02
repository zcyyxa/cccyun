<?php

namespace plugins\addons\blacklist\lib;

use think\facade\Db;

class Down
{
    public const MARK = '公共黑名单#';

    public const MAX_CONTENT = 50;

    public const CHECK_BATCH = 50;

    public const TASK = 'blacklist_sync';

    public const PULL_LIMIT = 200;

    public const PULL_PAGES = 3;

    public const BUSY_TTL = 120;

    public const WAIT_MAX = 8.0;

    public static function enabled(): bool
    {
        return Service::flag('push_enable') && Service::client()->ready();
    }

    private static function api(): Client
    {
        $conf = Service::config();
        $conf['timeout'] = 2;
        return new Client($conf);
    }

    public static function maybeRun(bool $pay = false): void
    {
        try {
            if (!self::enabled()) return;

            $now = time();
            $ttl = $pay
                ? Service::num('pay_interval', 10, 3, 300)
                : Service::num('pull_interval', 60, 15, 86400);

            $st   = self::states(['lock', 'fail_at']);
            $last = (int)($st['lock'] ?? '0');
            if ($last > 0 && $now - $last < $ttl) return;

            $fail = (int)($st['fail_at'] ?? '0');
            if ($fail > 0 && $now - $fail < ($pay ? 30 : $ttl * 5)) return;

            self::run(false, $pay);
        } catch (\Throwable $e) {
        }
    }

    public static function isPayPath(string $path): bool
    {
        $path = strtolower(trim($path, '/'));
        if ($path === '') return false;

        if (preg_match('#\.(js|css|png|jpe?g|gif|webp|svg|ico|woff2?|ttf|map)$#', $path)) return false;

        foreach (['pay', 'qrpay', 'submit', 'cashier', 'applet', 'order', 'api', 'mapi', 'epay'] as $frag) {
            if (str_contains($path, $frag)) return true;
        }
        return false;
    }

    public static function run(bool $force = false, bool $pay = false): array
    {
        if (!self::enabled()) {
            return ['ok' => false, 'msg' => '「下发拦截」是关的，或者平台地址密钥还没填', 'skipped' => true];
        }

        $ttl  = $pay
            ? Service::num('pay_interval', 10, 3, 300)
            : Service::num('pull_interval', 60, 15, 86400);
        $now  = time();
        $last = (int)self::state('lock', '0');

        if (!$force) {
            if ($last > 0 && $now - $last < $ttl) {
                return ['ok' => true, 'msg' => '还没到下一轮（' . ($ttl - ($now - $last)) . ' 秒后自动跑）', 'skipped' => true];
            }
            if (!self::acquire('lock', $ttl)) {
                return ['ok' => true, 'msg' => '另一个请求正在同步，这轮跳过', 'skipped' => true];
            }
        } else {
            self::stamp('lock');
        }

        $got = self::acquire('busy', self::BUSY_TTL);
        if (!$got && $force) {
            $deadline = microtime(true) + self::WAIT_MAX;
            while (microtime(true) < $deadline) {
                usleep(150000);
                if (self::acquire('busy', self::BUSY_TTL)) { $got = true; break; }
            }
        }
        if (!$got) {
            return ['ok' => true, 'msg' => '另一次同步正在跑', 'skipped' => true];
        }

        try {
            $out = ['ok' => true, 'msg' => '', 'pulled' => 0, 'pushed' => 0, 'revoked' => 0, 'verified' => 0, 'failed' => 0];

            $p = self::pull($pay ? 1 : self::PULL_PAGES);
            $out['pulled']   = $p['seen'];
            $out['pushed']  += $p['pushed'];
            $out['revoked'] += $p['revoked'];
            $out['failed']  += $p['failed'] ? 1 : 0;

            $v = self::verify();
            $out['verified']  = $v['checked'];
            $out['pushed']   += $v['pushed'];
            $out['revoked']  += $v['revoked'];
            $out['failed']   += $v['failed'] ? 1 : 0;

            $out['msg'] = self::brief($out);

            self::setState('lock', (string)time());
            self::setState('last_run', (string)$now);
            self::setState('last_msg', $out['msg']);
            self::setState('fail_at', $out['failed'] > 0 ? (string)$now : '0');

            if ($out['pulled'] > 0 || $out['verified'] > 0 || $out['failed'] > 0 || $out['revoked'] > 0) {
                Service::log('pull', 0, '', $out['failed'] > 0 ? 0 : 1, $out['msg']);
            }

            return $out;
        } finally {
            self::release('busy');
        }
    }

    public static function sweep(int $batches = 4, bool $force = false): array
    {
        $sum = ['checked' => 0, 'pushed' => 0, 'revoked' => 0, 'failed' => 0];
        $batches = max(1, min($batches, 20));

        for ($i = 0; $i < $batches; $i++) {
            $r = self::verify(0, $force);
            $sum['checked'] += $r['checked'];
            $sum['pushed']  += $r['pushed'];
            $sum['revoked'] += $r['revoked'];
            $sum['failed']  += $r['failed'] ? 1 : 0;

            if ($r['checked'] < self::CHECK_BATCH) break;
        }
        return $sum;
    }

    public static function pull(int $pages = self::PULL_PAGES): array
    {
        $out = ['seen' => 0, 'pushed' => 0, 'revoked' => 0, 'failed' => false, 'more' => false];

        $pages   = max(1, min($pages, self::PULL_PAGES));
        $sinceId = (int)self::state('pull_last_id', '0');

        for ($i = 0; $i < $pages; $i++) {
            $res = self::api()->list([
                'since_id'    => $sinceId,
                'limit'       => self::PULL_LIMIT,
                'active_only' => 1,
            ]);

            if (!$res['ok']) {
                $out['failed'] = true;
                return $out;
            }

            $items  = (array)($res['data']['items'] ?? []);
            $lastId = (int)($res['data']['last_id'] ?? $sinceId);

            self::setState('total_active', (string)(int)($res['data']['total_active'] ?? 0));

            foreach ($items as $it) {
                if (!is_array($it)) continue;
                $out['seen']++;

                $r = self::apply([
                    'entry_id'     => (int)($it['id'] ?? 0),
                    'type'         => (int)($it['type'] ?? 0),
                    'content'      => (string)($it['content'] ?? ''),
                    'total_days'   => (int)($it['total_days'] ?? 0),
                    'endtime'      => $it['endtime'] ?? null,
                    'permanent'    => !empty($it['permanent']),
                    'report_count' => (int)($it['report_count'] ?? 0),
                    'status'       => (int)($it['status'] ?? 1),
                ]);

                $out['pushed']  += $r['pushed'] ? 1 : 0;
                $out['revoked'] += $r['revoked'] ? 1 : 0;
            }

            if ($lastId > $sinceId) {
                $sinceId = $lastId;
                self::setState('pull_last_id', (string)$sinceId);
            }

            $out['more'] = !empty($res['data']['has_more']);

            if (!$out['more'] || !$items) break;
        }

        self::setState('pull_at', date('Y-m-d H:i:s'));
        return $out;
    }

    public static function verify(int $batch = 0, bool $force = false): array
    {
        $out = ['checked' => 0, 'pushed' => 0, 'revoked' => 0, 'failed' => false];

        $batch = $batch > 0 ? min($batch, self::CHECK_BATCH) : self::CHECK_BATCH;
        $ttl   = Service::num('verify_ttl', 1800, 60, 604800);

        try {
            $q = Db::name('blacklist_entry');
            if (!$force) {
                $line = date('Y-m-d H:i:s', time() - $ttl);
                $q->where(function ($w) use ($line) {
                    $w->whereNull('check_at')->whereOr('check_at', '<', $line);
                });
            }
            $rows = $q->order('check_at', 'asc')->order('id', 'asc')->limit($batch)->select()->toArray();
        } catch (\Throwable $e) {
            return $out;
        }

        if (!$rows) return $out;

        $items = [];
        foreach ($rows as $r) {
            $items[] = ['type' => (int)$r['type'], 'content' => (string)$r['content']];
        }

        $res = self::api()->check($items);
        if (!$res['ok']) {
            $out['failed'] = true;
            return $out;
        }

        $map = [];
        foreach ((array)($res['data']['results'] ?? []) as $one) {
            if (!is_array($one)) continue;
            $map[(((int)($one['type'] ?? 0) === 1) ? 1 : 0) . '|' . (string)($one['content'] ?? '')] = $one;
        }

        $now = date('Y-m-d H:i:s');

        foreach ($rows as $m) {
            $type    = ((int)$m['type'] === 1) ? 1 : 0;
            $content = (string)$m['content'];
            $mine    = (int)$m['id'];
            $entryId = (int)$m['entry_id'];

            $out['checked']++;
            $one = $map[$type . '|' . $content] ?? null;

            if ($one === null) {
            } elseif (!empty($one['invalid'])) {
            } elseif (!empty($one['hit'])) {
                $r = self::apply([
                    'entry_id'     => $entryId,
                    'type'         => $type,
                    'content'      => $content,
                    'total_days'   => (int)($one['total_days'] ?? 0),
                    'endtime'      => $one['endtime'] ?? null,
                    'permanent'    => !empty($one['permanent']),
                    'report_count' => (int)($one['report_count'] ?? 0),
                    'status'       => 1,
                ]);
                $out['pushed'] += $r['pushed'] ? 1 : 0;
            } else {
                $reason = (string)($one['reason'] ?? '');

                if ($reason === '') {
                    $r = self::revoke($type, $content, '平台上已经没有这条了');
                    $out['revoked'] += $r['revoked'] ? 1 : 0;
                    try {
                        Db::name('blacklist_entry')->where('id', $mine)->delete();
                    } catch (\Throwable $e) {
                    }
                    continue;
                }

                $r = self::apply([
                    'entry_id'     => $entryId,
                    'type'         => $type,
                    'content'      => $content,
                    'total_days'   => (int)($one['total_days'] ?? 0),
                    'endtime'      => $one['endtime'] ?? null,
                    'permanent'    => !empty($one['permanent']),
                    'report_count' => (int)($one['report_count'] ?? 0),
                    'status'       => 0,
                ]);
                $out['revoked'] += $r['revoked'] ? 1 : 0;
            }

            try {
                Db::name('blacklist_entry')->where('id', $mine)->update(['check_at' => $now]);
            } catch (\Throwable $e) {
            }
        }

        return $out;
    }

    private static function apply(array $e): array
    {
        $entryId = (int)($e['entry_id'] ?? 0);
        $type    = ((int)($e['type'] ?? 0) === 1) ? 1 : 0;
        $content = trim((string)($e['content'] ?? ''));
        $active  = (int)($e['status'] ?? 1) === 1;
        $perm    = !empty($e['permanent']);
        $endtime = $perm ? null : self::dt($e['endtime'] ?? null);
        $reports = (int)($e['report_count'] ?? 0);

        if ($content === '') return ['pushed' => false, 'revoked' => false, 'msg' => '内容为空'];

        if (!$active)              return self::revoke($entryId, $type, $content, '平台已撤销');
        if (!$perm && !$endtime)   return self::revoke($entryId, $type, $content, '平台已过期');
        if (!$perm && strtotime($endtime) <= time()) {
            return self::revoke($entryId, $type, $content, '平台已过期');
        }

        $skip    = '';
        $cfgType = Service::num('pull_type', 0, 0, 2);

        if ($type === 1 && !filter_var($content, FILTER_VALIDATE_IP)) {
            $skip = '不是合法 IP';
        } elseif (mb_strlen($content) > self::MAX_CONTENT) {
            $skip = '内容长过 ' . self::MAX_CONTENT . ' 个字，系统黑名单表装不下';
        } elseif (Service::isSkipped($content)) {
            $skip = '在插件配置的跳过名单里';
        } elseif ($cfgType === 1 && $type === 1) {
            $skip = '配置里只下发账号';
        } elseif ($cfgType === 2 && $type === 0) {
            $skip = '配置里只下发 IP';
        } elseif ($reports < Service::num('pull_min_reports', 1, 1, 999)) {
            $skip = '只有 ' . $reports . ' 家报过，没到配置的门槛';
        }

        self::mirror($entryId, $type, $content, $e, $skip);

        if ($skip !== '') {
            return ['pushed' => false, 'revoked' => false, 'msg' => $skip];
        }

        return self::push($entryId, $type, $content, $endtime, $perm);
    }

    private static function mirror(int $entryId, int $type, string $content, array $e, string $skip = ''): void
    {
        if ($entryId <= 0) return;

        $now = date('Y-m-d H:i:s');
        $row = [
            'type'         => $type,
            'content'      => mb_substr($content, 0, 191),
            'total_days'   => (int)($e['total_days'] ?? 0),
            'endtime'      => self::dt($e['endtime'] ?? null),
            'permanent'    => !empty($e['permanent']) ? 1 : 0,
            'report_count' => (int)($e['report_count'] ?? 0),
            'status'       => (int)($e['status'] ?? 1) === 1 ? 1 : 0,
            'skip_msg'     => $skip !== '' ? mb_substr($skip, 0, 120) : null,
            'updatetime'   => $now,
        ];

        try {
            $n = Db::name('blacklist_entry')->where('entry_id', $entryId)->update($row);
            if ($n === 0 && !Db::name('blacklist_entry')->where('entry_id', $entryId)->find()) {
                $row['entry_id'] = $entryId;
                $row['addtime']  = $now;
                Db::name('blacklist_entry')->insert($row);
            }
        } catch (\Throwable $e2) {
        }
    }

    private static function push(int $entryId, int $type, string $content, ?string $endtime, bool $perm): array
    {
        $now  = date('Y-m-d H:i:s');
        $mark = self::MARK . $entryId;

        try {
            $row = Db::name('blacklist')->where(['type' => $type, 'content' => $content])->find();
        } catch (\Throwable $e) {
            return ['pushed' => false, 'revoked' => false, 'msg' => '读系统黑名单表失败'];
        }

        try {
            if (!$row) {
                Db::name('blacklist')->insert([
                    'type'    => $type,
                    'content' => $content,
                    'addtime' => $now,
                    'endtime' => $endtime,
                    'remark'  => $mark,
                ]);
                self::markPushed($entryId, $endtime, $perm);
                return ['pushed' => true, 'revoked' => false, 'msg' => ''];
            }

            $old  = self::dt($row['endtime'] ?? null);
            $id   = (int)$row['id'];

            if (self::isOurs((string)($row['remark'] ?? ''))) {
                Db::name('blacklist')->where('id', $id)->update(['endtime' => $endtime, 'remark' => $mark]);
                self::markPushed($entryId, $endtime, $perm);
                return ['pushed' => true, 'revoked' => false, 'msg' => ''];
            }

            if ($perm && $old !== null) {
                Db::name('blacklist')->where('id', $id)->update(['endtime' => null]);
                self::markPushed($entryId, null, true);
                return ['pushed' => true, 'revoked' => false, 'msg' => '站长自己拉黑过，已改成永久'];
            }
            if (!$perm && $endtime !== null && $old !== null && $endtime > $old) {
                Db::name('blacklist')->where('id', $id)->update(['endtime' => $endtime]);
                self::markPushed($entryId, $endtime, false);
                return ['pushed' => true, 'revoked' => false, 'msg' => '站长自己拉黑过，到期时间替他往后延了'];
            }

            self::markPushed($entryId, $old, $old === null);
            return ['pushed' => false, 'revoked' => false, 'msg' => '站长自己已经拉黑过这条，而且是永久的，不动它'];
        } catch (\Throwable $e) {
            return ['pushed' => false, 'revoked' => false, 'msg' => '写系统黑名单表失败'];
        }
    }

    private static function revoke(int $entryId, int $type, string $content, string $why): array
    {
        try {
            $row = Db::name('blacklist')->where(['type' => $type, 'content' => $content])->find();
        } catch (\Throwable $e) {
            return ['pushed' => false, 'revoked' => false, 'msg' => $why];
        }

        if (!$row) {
            self::unmarkPushed($entryId);
            return ['pushed' => false, 'revoked' => false, 'msg' => $why . '（本地本来就没下发）'];
        }

        if (!self::isOurs((string)($row['remark'] ?? ''))) {
            self::unmarkPushed($entryId);
            return ['pushed' => false, 'revoked' => false, 'msg' => $why . '，但那是站长自己拉黑的，不动'];
        }

        try {
            Db::name('blacklist')->where('id', (int)$row['id'])->delete();
        } catch (\Throwable $e) {
            return ['pushed' => false, 'revoked' => false, 'msg' => $why . '，撤下失败'];
        }

        self::unmarkPushed($entryId);
        Service::log('pull', $type, $content, 1, '平台撤销/过期，已从本站黑名单里撤下：' . $why);
        return ['pushed' => false, 'revoked' => true, 'msg' => $why . '，已从系统黑名单表撤下'];
    }

    public static function isOurs(string $remark): bool
    {
        return (bool)preg_match('/^' . preg_quote(self::MARK, '/') . '\d+$/', trim($remark));
    }

    public static function taskKey(): string
    {
        $k = self::state('task_key', '');
        if ($k !== '' && strlen($k) >= 16) return $k;

        $k = bin2hex(random_bytes(16));
        self::setState('task_key', $k);
        return $k;
    }

    public static function resetTaskKey(): string
    {
        $k = bin2hex(random_bytes(16));
        self::setState('task_key', $k);
        return $k;
    }

    public static function checkTaskKey(string $key): bool
    {
        $key = trim($key);
        if ($key === '') return false;
        $mine = self::state('task_key', '');
        if ($mine === '') return false;
        return hash_equals($mine, $key);
    }

    public static function pushEntryData(int $entryId, int $type, string $content, array $data): array
    {
        if ($entryId <= 0 || !self::enabled()) return ['pushed' => false, 'revoked' => false, 'msg' => ''];

        return self::apply([
            'entry_id'     => $entryId,
            'type'         => $type,
            'content'      => $content,
            'total_days'   => (int)($data['total_days'] ?? 0),
            'endtime'      => $data['endtime'] ?? null,
            'permanent'    => !empty($data['permanent']),
            'report_count' => (int)($data['report_count'] ?? 1),
            'status'       => 1,
        ]);
    }

    public static function stats(): array
    {
        $now      = time();
        $interval = Service::num('pull_interval', 60, 15, 86400);
        $payIv    = Service::num('pay_interval', 10, 3, 300);

        $out = [
            'enabled'      => self::enabled(),
            'mirror'       => 0,
            'pushed'       => 0,
            'skipped'      => 0,
            'blocking'     => 0,
            'total_active' => (int)self::state('total_active', '0'),
            'pull_at'      => (string)self::state('pull_at', ''),
            'last_run'     => '',
            'last_ago'     => -1,
            'last_msg'     => (string)self::state('last_msg', ''),
            'fail_at'      => '',
            'next_in'      => 0,
            'interval'     => $interval,
            'pay_interval' => $payIv,
            'pull'         => 0,
            'pull_failed'  => 0,
        ];

        try {
            $out['mirror']  = (int)Db::name('blacklist_entry')->count();
            $out['pushed']  = (int)Db::name('blacklist_entry')->where('pushed', 1)->count();
            $out['skipped'] = (int)Db::name('blacklist_entry')->where('pushed', 0)->whereNotNull('skip_msg')->count();
            $out['blocking'] = (int)Db::name('blacklist')->whereLike('remark', self::MARK . '%')->count();
        } catch (\Throwable $e) {
        }

        try {
            $since = date('Y-m-d H:i:s', $now - 86400);
            $out['pull'] = (int)Db::name('blacklist_log')
                ->where('event', 'pull')->where('addtime', '>=', $since)->count();
            $out['pull_failed'] = (int)Db::name('blacklist_log')
                ->where('event', 'pull')->where('status', 0)->where('addtime', '>=', $since)->count();
        } catch (\Throwable $e) {
        }

        $last = (int)self::state('lock', '0');
        if ($last > 0) {
            $out['last_run'] = date('Y-m-d H:i:s', $last);
            $out['last_ago'] = max(0, $now - $last);
            $out['next_in']  = max(0, $interval - ($now - $last));
        }
        $fail = (int)self::state('fail_at', '0');
        if ($fail > 0) $out['fail_at'] = date('Y-m-d H:i:s', $fail);

        return $out;
    }

    public static function clearPushed(): array
    {
        try {
            $n = Db::name('blacklist')->whereLike('remark', self::MARK . '%')->delete();

            Db::name('blacklist_entry')->where('pushed', 1)->update([
                'pushed' => 0, 'pushed_endtime' => null, 'pushed_permanent' => 0,
                'updatetime' => date('Y-m-d H:i:s'),
            ]);

            Service::log('pull', 0, '', 1, '手动清空：从系统黑名单表撤下 ' . $n . ' 条');
            return ['ok' => true, 'msg' => '从系统黑名单表撤下 ' . $n . ' 条（平台上的数据没动，下次同步还会再来）'];
        } catch (\Throwable $e) {
            return ['ok' => false, 'msg' => '清理失败：' . $e->getMessage()];
        }
    }

    public static function repush(int $limit = 500): array
    {
        $out = ['done' => 0, 'left' => 0, 'failed' => 0];
        $limit = max(1, min($limit, 1000));

        try {
            $rows = Db::name('blacklist_entry')
                ->where('status', 1)
                ->whereNull('skip_msg')
                ->order('id', 'asc')
                ->limit($limit)
                ->select()->toArray();

            $left = (int)Db::name('blacklist_entry')
                ->where('status', 1)->whereNull('skip_msg')->count() - count($rows);
        } catch (\Throwable $e) {
            return ['done' => 0, 'left' => 0, 'failed' => 0, 'msg' => '读镜像表失败'];
        }

        foreach ($rows as $r) {
            $end = (int)$r['permanent'] === 1 ? null : self::dt($r['endtime'] ?? null);
            $one = self::push((int)$r['entry_id'], (int)$r['type'] === 1 ? 1 : 0, (string)$r['content'], $end, (int)$r['permanent'] === 1);
            if ($one['pushed'] || $one['msg'] === '站长自己已经拉黑过这条，而且是永久的，不动它') $out['done']++;
        }

        $out['left'] = max(0, $left);

        return $out;
    }

    private static function brief(array $o): string
    {
        $s = '拉新 ' . (int)$o['pulled'] . ' 条（下发 ' . (int)$o['pushed'] . '）· 复核 ' . (int)$o['verified'] . ' 条';
        if ((int)$o['revoked'] > 0) $s .= ' · 撤下 ' . (int)$o['revoked'] . ' 条';
        if ((int)$o['failed'] > 0) $s .= ' · 有一次请求失败';
        return $s;
    }

    private static function markPushed(int $entryId, ?string $endtime, bool $perm): void
    {
        if ($entryId <= 0) return;
        try {
            Db::name('blacklist_entry')->where('entry_id', $entryId)->update([
                'pushed'           => 1,
                'pushed_endtime'   => $endtime,
                'pushed_permanent' => $perm ? 1 : 0,
                'updatetime'       => date('Y-m-d H:i:s'),
            ]);
        } catch (\Throwable $e) {
        }
    }

    private static function unmarkPushed(int $entryId): void
    {
        if ($entryId <= 0) return;
        try {
            Db::name('blacklist_entry')->where('entry_id', $entryId)->update([
                'pushed'           => 0,
                'pushed_endtime'   => null,
                'pushed_permanent' => 0,
                'updatetime'       => date('Y-m-d H:i:s'),
            ]);
        } catch (\Throwable $e) {
        }
    }

    public static function sysTask(): ?array
    {
        try {
            $row = Db::name('crontab')->where('task', self::TASK)->find();
            return $row ?: null;
        } catch (\Throwable $e) {
            return null;
        }
    }

    public static function sysTaskInfo(): array
    {
        $row = self::sysTask();

        $out = [
            'found'         => $row !== null,
            'task'          => self::TASK,
            'name'          => '公共黑名单同步',
            'plugin'        => 'blacklist',
            'enabled'       => 0,
            'interval'      => 0,
            'interval_text' => '',
            'last_run_time' => '',
            'last_status'   => -1,
            'last_error'    => '',
            'next_run_time' => '',
        ];

        if ($row) {
            foreach (['task', 'name', 'plugin', 'last_run_time', 'last_error', 'next_run_time'] as $k) {
                if (isset($row[$k]) && $row[$k] !== null) $out[$k] = (string)$row[$k];
            }
            $out['enabled']     = (int)($row['enabled'] ?? 0);
            $out['last_status'] = (int)($row['last_status'] ?? 0);
            $out['interval']    = (int)($row['interval'] ?? 0);
            $out['interval_text'] = self::intervalText((string)($row['frequency'] ?? ''), (int)($row['interval'] ?? 0));
        }

        return $out;
    }

    private static function intervalText(string $freq, int $n): string
    {
        $n = max(1, $n);

        switch ($freq) {
            case 'second': return '每 ' . $n . ' 秒一次';
            case 'minute': return '每 ' . $n . ' 分钟一次';
            case 'hour':   return '每 ' . $n . ' 小时一次';
            case 'day':    return '每 ' . $n . ' 天一次';
            case 'once':   return '只跑一次';
        }
        return '';
    }

    public static function ensureSysTask(): array
    {
        try {
            if (self::sysTask()) {
                return ['ok' => true, 'msg' => '系统计划任务里已经有这条了，没动它', 'task' => self::sysTaskInfo()];
            }

            Db::name('crontab')->insert([
                'task'        => self::TASK,
                'name'        => '公共黑名单同步',
                'description' => '把公共黑名单平台的黑名单拉到本站（写进系统自己的黑名单表，付款时由系统自动拦住），'
                               . '同时把本站的黑名单报给平台。由「公共黑名单」插件提供，60 秒一档。',
                'plugin'      => 'blacklist',
                'frequency'   => 'second',
                'interval'    => 60,
                'enabled'     => 1,
            ]);

            return ['ok' => true, 'msg' => '已经加进「系统管理 - 计划任务」了', 'task' => self::sysTaskInfo()];
        } catch (\Throwable $e) {
            return ['ok' => false, 'msg' => '写计划任务表失败：' . $e->getMessage(), 'task' => self::sysTaskInfo()];
        }
    }

    public static function state(string $k, string $default = ''): string
    {
        try {
            $v = Db::name('blacklist_state')->where('k', $k)->value('v');
            return ($v === null || $v === false) ? $default : (string)$v;
        } catch (\Throwable $e) {
            return $default;
        }
    }

    public static function states(array $keys): array
    {
        try {
            return (array)Db::name('blacklist_state')->whereIn('k', $keys)->column('v', 'k');
        } catch (\Throwable $e) {
            return [];
        }
    }

    public static function setState(string $k, string $v): void
    {
        $now = date('Y-m-d H:i:s');
        try {
            $n = Db::name('blacklist_state')->where('k', $k)->update(['v' => $v, 'updatetime' => $now]);
            if ($n === 0 && !Db::name('blacklist_state')->where('k', $k)->find()) {
                Db::name('blacklist_state')->insert(['k' => $k, 'v' => $v, 'updatetime' => $now]);
            }
        } catch (\Throwable $e) {
        }
    }

    private static function acquire(string $key, int $ttl): bool
    {
        $now   = time();
        $guard = $now - max(1, $ttl);
        $time  = date('Y-m-d H:i:s', $now);

        try {
            $prefix = config('database.connections.mysql.prefix', '');
            $table  = $prefix . 'blacklist_state';

            $sql = "INSERT INTO `{$table}` (`k`,`v`,`updatetime`) VALUES (?,?,?)
                    ON DUPLICATE KEY UPDATE
                      `updatetime` = IF(`v` < ?, NOW(), `updatetime`),
                      `v`          = IF(`v` < ?, VALUES(`v`), `v`)";

            $n = Db::execute($sql, [$key, (string)$now, $time, $guard, $guard]);
        } catch (\Throwable $e) {
            return false;
        }

        return $n > 0;
    }

    private static function release(string $key): void
    {
        try {
            Db::name('blacklist_state')->where('k', $key)->delete();
        } catch (\Throwable $e) {
        }
    }

    private static function stamp(string $key): void
    {
        self::setState($key, (string)time());
    }

    private static function dt($v): ?string
    {
        $v = trim((string)$v);
        if ($v === '' || str_starts_with($v, '0000-00-00')) return null;
        $ts = strtotime($v);
        if ($ts === false) return null;
        return date('Y-m-d H:i:s', $ts);
    }
}
