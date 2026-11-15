<?php

/**
 * Asset Watch - GLPI plugin
 *
 * @license GPL-3.0-or-later
 */

if (!defined('GLPI_ROOT')) {
    die("Sorry. You can't access this file directly");
}

/**
 * Many small network checks at once (curl multi): HTTP requests to GLPI Agents and TCP
 * connection tests. A few hundred machines are checked in about the time of one timeout.
 *
 * Agents are on the internal network: requests never go through the GLPI proxy, and the
 * agents' self-signed certificates are accepted (only "is it there" is asked).
 */
class PluginAssetwatchProber
{
    public const CONCURRENCY = 64;

    /**
     * @param array<string, array{url: string, tcp?: bool}> $requests key => request; tcp = only open
     *        the TCP connection (url "tcp://host:port")
     *
     * @return array<string, array{ok: bool, http_code: int, error: string}> ok: HTTP answer received
     *         (any code), or TCP connection opened
     */
    public static function run(array $requests, int $timeout): array
    {
        $results = [];
        if ($requests === [] || !function_exists('curl_multi_init')) {
            foreach (array_keys($requests) as $key) {
                $results[$key] = ['ok' => false, 'http_code' => 0, 'error' => 'curl not available'];
            }
            return $results;
        }

        $multi = curl_multi_init();
        $queue = $requests;
        $running_handles = [];
        $start = static function (string $key, array $request) use ($multi, $timeout, &$running_handles): void {
            $tcp = !empty($request['tcp']);
            $url = $tcp ? 'http://' . substr($request['url'], strlen('tcp://')) . '/' : $request['url'];
            $handle = curl_init($url);
            curl_setopt_array($handle, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_CONNECTTIMEOUT => $timeout,
                CURLOPT_TIMEOUT        => $timeout + 1,
                CURLOPT_NOSIGNAL       => true,
                CURLOPT_PROXY          => '',
                CURLOPT_NOPROXY        => '*',
                CURLOPT_SSL_VERIFYPEER => false,
                CURLOPT_SSL_VERIFYHOST => 0,
                CURLOPT_FOLLOWLOCATION => false,
                CURLOPT_USERAGENT      => 'GLPI-AssetWatch-Probe',
                CURLOPT_CONNECT_ONLY   => $tcp,
            ]);
            curl_multi_add_handle($multi, $handle);
            $running_handles[(int) $handle] = ['key' => $key, 'handle' => $handle, 'tcp' => $tcp];
        };

        while ($queue !== [] && count($running_handles) < self::CONCURRENCY) {
            $key = (string) array_key_first($queue);
            $start($key, $queue[$key]);
            unset($queue[$key]);
        }

        do {
            curl_multi_exec($multi, $active);
            while (($info = curl_multi_info_read($multi)) !== false) {
                $handle = $info['handle'];
                $entry = $running_handles[(int) $handle];
                $code = (int) curl_getinfo($handle, CURLINFO_RESPONSE_CODE);
                $ok = $entry['tcp'] ? $info['result'] === CURLE_OK : ($info['result'] === CURLE_OK && $code > 0);
                $results[$entry['key']] = [
                    'ok'        => $ok,
                    'http_code' => $code,
                    'error'     => $info['result'] === CURLE_OK ? '' : (string) curl_strerror($info['result']),
                ];
                curl_multi_remove_handle($multi, $handle);
                curl_close($handle);
                unset($running_handles[(int) $handle]);
                if ($queue !== []) {
                    $key = (string) array_key_first($queue);
                    $start($key, $queue[$key]);
                    unset($queue[$key]);
                    $active = 1;
                }
            }
            if ($active) {
                curl_multi_select($multi, 0.2);
            }
        } while ($active || $running_handles !== []);

        curl_multi_close($multi);
        foreach (array_keys($requests) as $key) {
            $results[$key] ??= ['ok' => false, 'http_code' => 0, 'error' => 'no result'];
        }
        return $results;
    }
}
