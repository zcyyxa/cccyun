<?php

namespace plugins\addons\blacklist\lib;

class Client
{
    private array $config;

    public function __construct(array $config)
    {
        $this->config = $config;
    }

    public function baseUrl(): string
    {
        return rtrim(trim((string)($this->config['api_url'] ?? '')), '/');
    }

    public function key(): string
    {
        return trim((string)($this->config['api_key'] ?? ''));
    }

    public function ready(): bool
    {
        return $this->baseUrl() !== '' && $this->key() !== '';
    }

    public function timeout(): float
    {
        $t = (float)($this->config['timeout'] ?? 3);
        if ($t <= 0) $t = 3;
        if ($t > 20) $t = 20;
        return $t;
    }

    public function check(array $items): array
    {
        return $this->post('check.php', [
            'items' => json_encode($items, JSON_UNESCAPED_UNICODE),
        ]);
    }

    public function report(array $data): array
    {
        return $this->post('report.php', $data);
    }

    public function status(array $data): array
    {
        return $this->post('status.php', $data);
    }

    public function list(array $data): array
    {
        return $this->post('list.php', $data);
    }

    private function post(string $path, array $data): array
    {
        if (!$this->ready()) {
            return ['ok' => false, 'code' => -1, 'msg' => '还没配置平台接口地址和密钥', 'data' => []];
        }

        $url     = $this->baseUrl() . '/' . $path;
        $timeout = $this->timeout();

        $data['key'] = $this->key();
        $body = http_build_query($data);

        $raw = $this->send($url, $body, $timeout, $err);
        if ($raw === null) {
            return ['ok' => false, 'code' => -1, 'msg' => $err !== '' ? $err : '平台连接失败', 'data' => []];
        }

        $json = json_decode($raw, true);
        if (!is_array($json)) {
            return ['ok' => false, 'code' => -1, 'msg' => '平台返回的不是 JSON：' . mb_substr($raw, 0, 120), 'data' => []];
        }

        $code = (int)($json['code'] ?? -1);
        return [
            'ok'   => $code === 0,
            'code' => $code,
            'msg'  => (string)($json['msg'] ?? ''),
            'data' => is_array($json['data'] ?? null) ? $json['data'] : [],
        ];
    }

    private function send(string $url, string $body, float $timeout, ?string &$err = null)
    {
        $err = '';

        if (function_exists('curl_init')) {
            $ch = curl_init($url);
            curl_setopt_array($ch, [
                CURLOPT_POST           => true,
                CURLOPT_POSTFIELDS     => $body,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_HEADER         => false,
                CURLOPT_CONNECTTIMEOUT => (int)ceil($timeout),
                CURLOPT_TIMEOUT        => (int)ceil($timeout),
                CURLOPT_SSL_VERIFYPEER => true,
                CURLOPT_SSL_VERIFYHOST => 2,
                CURLOPT_FOLLOWLOCATION => false,
                CURLOPT_HTTPHEADER     => [
                    'X-Api-Key: ' . $this->key(),
                    'Content-Type: application/x-www-form-urlencoded',
                ],
                CURLOPT_USERAGENT      => 'EasyPay-Blacklist/1.0',
            ]);

            $resp = curl_exec($ch);
            $errno = curl_errno($ch);
            $error = curl_error($ch);
            $http  = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);

            if ($errno) {
                $err = '平台连接失败：' . $error;
                return null;
            }
            if ($http !== 200) {
                $err = '平台返回 HTTP ' . $http;
                return null;
            }
            return (string)$resp;
        }

        $ctx = stream_context_create([
            'http' => [
                'method'        => 'POST',
                'header'        => "Content-Type: application/x-www-form-urlencoded\r\n"
                                 . "X-Api-Key: " . $this->key() . "\r\n",
                'content'       => $body,
                'timeout'       => $timeout,
                'ignore_errors' => true,
            ],
        ]);
        $resp = @file_get_contents($url, false, $ctx);
        if ($resp === false) {
            $err = '平台连接失败（file_get_contents）';
            return null;
        }
        return (string)$resp;
    }

    public function ping(): array
    {
        $probe = 'blacklist_probe_' . date('YmdHis');
        $res   = $this->check([['type' => 0, 'content' => $probe]]);
        return $res;
    }
}
