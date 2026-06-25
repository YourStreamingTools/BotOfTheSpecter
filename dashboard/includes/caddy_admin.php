<?php
// caddy_admin.php
// Helpers for the admin Caddy control page (dashboard/admin/caddy.php).
//
// Caddy runs on the web host as the origin web server. Its admin API is bound
// to localhost:2019 (Caddy default). The dashboard PHP runs on the same host,
// so these helpers talk to the admin API directly over loopback — no SSH.
//
// SECURITY: GET /config/ returns the *resolved* config, which includes the
// Cloudflare API token baked in from {env.CF_API_TOKEN}. Every config payload
// MUST pass through caddy_redact_secrets() before reaching a browser or an
// audit log. The path allowlist deliberately omits /stop (self-DoS: it would
// kill the web server that serves this dashboard).

if (!defined('CADDY_ADMIN_ENDPOINT')) {
    define('CADDY_ADMIN_ENDPOINT', 'http://localhost:2019');
}

if (!function_exists('caddy_is_sensitive_key')) {
    /**
     * True when an object key likely holds a secret and its value must be hidden.
     */
    function caddy_is_sensitive_key($key) {
        if (!is_string($key) || $key === '') {
            return false;
        }
        $k = strtolower($key);
        $needles = [
            'token', 'secret', 'password', 'passwd', 'api_key', 'apikey',
            'oauth', 'private_key', 'client_secret', 'authorization', 'cookie',
        ];
        foreach ($needles as $needle) {
            if (strpos($k, $needle) !== false) {
                return true;
            }
        }
        return false;
    }
}

if (!function_exists('caddy_redact_secrets')) {
    /**
     * Recursively replace any value under a sensitive key with [REDACTED].
     * The whole subtree under a sensitive key is replaced (handles a secret
     * stored as an array/object, not just a scalar). List arrays are preserved.
     */
    function caddy_redact_secrets($data) {
        if (!is_array($data)) {
            return $data;
        }
        $isList = (array_keys($data) === range(0, count($data) - 1));
        $out = [];
        foreach ($data as $key => $val) {
            if (!$isList && caddy_is_sensitive_key((string) $key)) {
                $out[$key] = '[REDACTED]';
            } else {
                $out[$key] = caddy_redact_secrets($val);
            }
        }
        return $out;
    }
}

if (!function_exists('caddy_path_allowed')) {
    /**
     * Allowlist for the Caddy admin API surface this page may touch.
     * /stop is intentionally absent — it stops the process that serves the
     * dashboard, leaving no API to recover with.
     */
    function caddy_path_allowed($path) {
        if (!is_string($path) || $path === '' || $path[0] !== '/') {
            return false;
        }
        $allowed = ['/config', '/id', '/adapt', '/load', '/reverse_proxy', '/pki'];
        foreach ($allowed as $prefix) {
            if ($path === $prefix || strpos($path, $prefix . '/') === 0) {
                return true;
            }
        }
        return false;
    }
}

if (!function_exists('caddy_admin_request')) {
    /**
     * Make a request to the Caddy admin API over loopback.
     *
     * @return array{ok:bool,status:int,body:mixed,error:?string}
     *         body is the decoded JSON (array) when the response parses as JSON,
     *         otherwise the raw string, otherwise null.
     */
    function caddy_admin_request($method, $path, $body = null, $contentType = 'application/json', $timeout = 10) {
        $method = strtoupper((string) $method);
        if (!in_array($method, ['GET', 'POST', 'PUT', 'PATCH', 'DELETE'], true)) {
            return ['ok' => false, 'status' => 0, 'body' => null, 'error' => 'Invalid method'];
        }
        if (!caddy_path_allowed($path)) {
            return ['ok' => false, 'status' => 0, 'body' => null, 'error' => 'Path not allowed'];
        }

        $ch = curl_init(CADDY_ADMIN_ENDPOINT . $path);
        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $method);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, (int) $timeout);
        if ($body !== null && $body !== '') {
            curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
            curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: ' . $contentType]);
        }
        $resp = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $errno = curl_errno($ch);
        $err = curl_error($ch);
        curl_close($ch);

        if ($errno) {
            return [
                'ok' => false,
                'status' => 0,
                'body' => null,
                'error' => 'Caddy admin API unreachable: ' . $err,
            ];
        }

        $decoded = null;
        if (is_string($resp) && $resp !== '') {
            $json = json_decode($resp, true);
            $decoded = (json_last_error() === JSON_ERROR_NONE) ? $json : $resp;
        }

        return [
            'ok' => ($status >= 200 && $status < 300),
            'status' => $status,
            'body' => $decoded,
            'error' => ($status >= 400 ? ('HTTP ' . $status) : null),
        ];
    }
}

if (!function_exists('caddy_collect_handler_flags')) {
    /**
     * Walk a route's handle chain (descending into Caddy's `subroute` wrapper,
     * which a Caddyfile always produces) and flag the meaningful handler kinds.
     * Infra handlers (encode, headers, vars, rewrite, …) are ignored on purpose.
     */
    function caddy_collect_handler_flags($handleList, array &$flags) {
        if (!is_array($handleList)) {
            return;
        }
        foreach ($handleList as $h) {
            if (!is_array($h)) {
                continue;
            }
            $name = $h['handler'] ?? null;
            if ($name === 'subroute') {
                $routes = (isset($h['routes']) && is_array($h['routes'])) ? $h['routes'] : [];
                foreach ($routes as $r) {
                    if (isset($r['handle'])) {
                        caddy_collect_handler_flags($r['handle'], $flags);
                    }
                }
                continue;
            }
            if ($name === 'reverse_proxy') {
                // php_fastcgi adapts to a reverse_proxy with a fastcgi transport.
                $proto = $h['transport']['protocol'] ?? '';
                $flags[$proto === 'fastcgi' ? 'php' : 'reverse_proxy'] = true;
            } elseif ($name === 'static_response') {
                // `redir` adapts to a static_response that sets a Location header.
                $hasLocation = false;
                if (isset($h['headers']) && is_array($h['headers'])) {
                    foreach (array_keys($h['headers']) as $hk) {
                        if (strcasecmp((string) $hk, 'Location') === 0) {
                            $hasLocation = true;
                            break;
                        }
                    }
                }
                $flags[$hasLocation ? 'redirect' : 'response'] = true;
            } elseif ($name === 'file_server') {
                $flags['static'] = true;
            }
        }
    }
}

if (!function_exists('caddy_route_type')) {
    /**
     * Classify one top-level route into a stable type code the UI maps to a
     * label. Priority reflects what a site "is": a PHP app that also has a
     * file_server fallback is a PHP app, not a static host.
     *
     * @return string one of: php, reverse_proxy, redirect, static, response, other
     */
    function caddy_route_type($route) {
        $flags = [];
        if (is_array($route) && isset($route['handle'])) {
            caddy_collect_handler_flags($route['handle'], $flags);
        }
        if (!empty($flags['php'])) {
            return 'php';
        }
        if (!empty($flags['reverse_proxy'])) {
            return 'reverse_proxy';
        }
        if (!empty($flags['redirect'])) {
            return 'redirect';
        }
        if (!empty($flags['static'])) {
            return 'static';
        }
        if (!empty($flags['response'])) {
            return 'response';
        }
        return 'other';
    }
}

if (!function_exists('caddy_parse_sites')) {
    /**
     * One row per top-level route (≈ one Caddyfile site block): the hostnames it
     * matches and a friendly type code. A Caddyfile collapses every block into a
     * single HTTP server, so grouping by route — not by server — is what gives a
     * readable, per-site breakdown.
     *
     * @return array<int,array{server:string,listen:array,hosts:array,type:string}>
     */
    function caddy_parse_sites($config) {
        $rows = [];
        if (!is_array($config)) {
            return $rows;
        }
        $servers = $config['apps']['http']['servers'] ?? null;
        if (!is_array($servers)) {
            return $rows;
        }
        foreach ($servers as $name => $srv) {
            $listen = (isset($srv['listen']) && is_array($srv['listen'])) ? $srv['listen'] : [];
            $routes = (isset($srv['routes']) && is_array($srv['routes'])) ? $srv['routes'] : [];
            foreach ($routes as $route) {
                $hosts = [];
                if (isset($route['match']) && is_array($route['match'])) {
                    foreach ($route['match'] as $m) {
                        if (isset($m['host']) && is_array($m['host'])) {
                            foreach ($m['host'] as $h) {
                                $hosts[] = (string) $h;
                            }
                        }
                    }
                }
                $rows[] = [
                    'server' => (string) $name,
                    'listen' => array_values(array_unique($listen)),
                    'hosts' => array_values(array_unique($hosts)),
                    'type' => caddy_route_type($route),
                ];
            }
        }
        return $rows;
    }
}

if (!function_exists('caddy_summarize_tls')) {
    /**
     * Summarise TLS automation WITHOUT ever extracting the DNS provider token.
     *
     * @return array{acme_email:?string,dns_provider:?string,policies:int}
     */
    function caddy_summarize_tls($config) {
        $summary = ['acme_email' => null, 'dns_provider' => null, 'policies' => 0];
        if (!is_array($config)) {
            return $summary;
        }
        $automation = $config['apps']['tls']['automation'] ?? null;
        if (!is_array($automation)) {
            return $summary;
        }
        $policies = (isset($automation['policies']) && is_array($automation['policies'])) ? $automation['policies'] : [];
        $summary['policies'] = count($policies);
        foreach ($policies as $pol) {
            $issuers = (isset($pol['issuers']) && is_array($pol['issuers'])) ? $pol['issuers'] : [];
            foreach ($issuers as $iss) {
                if ($summary['acme_email'] === null && isset($iss['email']) && is_string($iss['email'])) {
                    $summary['acme_email'] = $iss['email'];
                }
                if ($summary['dns_provider'] === null && isset($iss['challenges']['dns']['provider']['name'])) {
                    $summary['dns_provider'] = (string) $iss['challenges']['dns']['provider']['name'];
                }
            }
        }
        return $summary;
    }
}
