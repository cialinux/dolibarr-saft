<?php
/* Copyright (C) 2026        Virgilio Filho              <virgilio.filho@cialinux.com>
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with this program.  If not, see <https://www.gnu.org/licenses/>.
 */

/**
 * \file    saft/lib/saft.lib.php
 * \ingroup saft
 * \brief   Library files with common functions for Saft
 */

/**
 * Prepare admin pages header
 *
 * @return array<array{string,string,string}>
 */
function saftAdminPrepareHead()
{
    global $langs, $conf;

    $langs->load("saft@saft");

    $h = 0;
    $head = array();

    $head[$h][0] = dol_buildpath("/saft/admin/setup.php", 1);
    $head[$h][1] = $langs->trans("Settings");
    $head[$h][2] = 'settings';
    $h++;

    $head[$h][0] = dol_buildpath("/saft/admin/about.php", 1);
    $head[$h][1] = $langs->trans("About");
    $head[$h][2] = 'about';
    $h++;

    complete_head_from_modules($conf, $langs, null, $head, $h, 'saft@saft');
    complete_head_from_modules($conf, $langs, null, $head, $h, 'saft@saft', 'remove');

    return $head;
}

/**
 * Retorna a configuracao fechada de ambiente do webservice SAF-T.
 * A URL fica encapsulada no codigo e o utilizador escolhe apenas o ambiente.
 *
 * @param string $env
 * @return array{env:string,label:string,api_url:string,verify_tls:bool}
 */
function saft_get_environment_config($env = '')
{
    $env = strtolower(trim((string) $env));
    if ($env !== 'production') {
        $env = 'dev';
    }

    if ($env === 'production') {
        return array(
            'env' => 'production',
            'label' => 'Ambiente production',
            'api_url' => 'https://saft-validator.cialinux.com/api/dolibarr/public/validate/preview',
            'verify_tls' => true,
        );
    }

    return array(
        'env' => 'dev',
        'label' => 'Ambiente dev',
        'api_url' => 'https://saft-validator.dev.cialinux.com/api/dolibarr/public/validate/preview',
        'verify_tls' => false,
    );
}

/**
 * Retorna a configuracao efetiva do ambiente selecionado no modulo.
 *
 * @return array{env:string,label:string,api_url:string,verify_tls:bool}
 */
function saft_get_runtime_api_config()
{
    $env = function_exists('getDolGlobalString') ? getDolGlobalString('SAFT_API_ENV', 'dev') : 'dev';
    return saft_get_environment_config($env);
}

/* ============================================================
 * Helpers do módulo (API preview)
 * ============================================================ */

/**
 * Retorna candidatos (sem porta e com :2000) a partir de uma URL completa.
 * Se a URL já tiver porta, retorna só ela.
 *
 * @param string $url
 * @return string[]
 */
function saft_build_api_candidates($url)
{
    $url = trim((string) $url);
    if ($url === '') return array();

    $p = @parse_url($url);
    if (!is_array($p) || empty($p['host'])) return array($url);

    // Se já tem porta, não inventa
    if (!empty($p['port'])) {
        return array($url);
    }

    $scheme = !empty($p['scheme']) ? $p['scheme'] : 'https';
    $host   = $p['host'];
    $path   = !empty($p['path']) ? $p['path'] : '/';
    $query  = !empty($p['query']) ? ('?'.$p['query']) : '';
    $frag   = !empty($p['fragment']) ? ('#'.$p['fragment']) : '';

    // 1) sem porta
    $u1 = $scheme.'://'.$host.$path.$query.$frag;

    // 2) com :2000
    $u2 = $scheme.'://'.$host.':2000'.$path.$query.$frag;

    return array($u1, $u2);
}

/**
 * Monta URL adicionando/mesclando query params (page/per_page etc).
 *
 * @param string $baseUrl
 * @param array $params
 * @return string
 */
function saft_url_with_params($baseUrl, $params)
{
    $p = @parse_url($baseUrl);
    if (!is_array($p) || empty($p['host'])) return $baseUrl;

    $scheme = !empty($p['scheme']) ? $p['scheme'] : 'https';
    $host   = $p['host'];
    $port   = !empty($p['port']) ? (':'.$p['port']) : '';
    $path   = !empty($p['path']) ? $p['path'] : '/';

    $q = array();
    if (!empty($p['query'])) {
        parse_str($p['query'], $q);
    }
    foreach ((array)$params as $k => $v) {
        $q[$k] = $v;
    }

    $query = http_build_query($q);
    $frag  = !empty($p['fragment']) ? ('#'.$p['fragment']) : '';

    return $scheme.'://'.$host.$port.$path.($query ? ('?'.$query) : '').$frag;
}

/**
 * Resolve endpoint automaticamente por modo:
 * - com token => endpoint privado
 * - sem token => endpoint público
 *
 * @param string $configuredUrl URL configurada no setup
 * @param string $apiToken token opcional
 * @param string $publicEndpoint endpoint público (ex: /api/dolibarr/public/validate/preview)
 * @param string $privateEndpoint endpoint privado (ex: /api/dolibarr/private/validate/preview)
 * @return string
 */
function saft_resolve_mode_endpoint_url($configuredUrl, $apiToken, $publicEndpoint, $privateEndpoint)
{
    $configuredUrl = trim((string) $configuredUrl);
    if ($configuredUrl === '') return '';

    $p = @parse_url($configuredUrl);
    if (!is_array($p) || empty($p['host'])) return $configuredUrl;

    $scheme = !empty($p['scheme']) ? $p['scheme'] : 'https';
    $host   = $p['host'];
    $port   = !empty($p['port']) ? (':'.$p['port']) : '';

    $endpoint = !empty($apiToken) ? $privateEndpoint : $publicEndpoint;
    return $scheme.'://'.$host.$port.$endpoint;
}

/**
 * Consome quota (rate limit) na API correta de forma automática:
 * - com token => /api/dolibarr/private/consume-quota + X-API-Key
 * - sem token => /api/dolibarr/public/consume-quota
 *
 * @param string $configuredPreviewUrl
 * @param string $apiToken
 * @param bool $verifyTls
 * @param int $timeout
 * @return array {ok, status, rate_limit?, auth_error?, rate_limit_error?, error?, attempts[]}
 */
function saft_consume_quota($configuredPreviewUrl, $apiToken, $verifyTls = false, $timeout = 10)
{
    $quotaUrl = saft_resolve_mode_endpoint_url(
        $configuredPreviewUrl,
        $apiToken,
        '/api/dolibarr/public/consume-quota',
        '/api/dolibarr/private/consume-quota'
    );

    if ($quotaUrl === '') {
        return array(
            'ok' => false,
            'status' => 0,
            'error' => 'Missing API configuration.',
            'attempts' => array(),
            'rate_limit' => null,
        );
    }

    $attempts = array();
    $rateLimitInfo = null;
    $authError = null;
    $rateLimitError = null;
    $lastStatus = 0;

    $candidates = saft_build_api_candidates($quotaUrl);
    foreach ($candidates as $url) {
        $headers = array('Accept: application/json', 'Content-Type: application/json');
        if (!empty($apiToken)) {
            $headers[] = 'X-API-Key: '.$apiToken;
        }

        $ch = curl_init();
        curl_setopt_array($ch, array(
            CURLOPT_URL => $url,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => '{}',
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HEADER => true,
            CURLOPT_TIMEOUT => (int) $timeout,
            CURLOPT_HTTPHEADER => $headers,
        ));

        if (!$verifyTls) {
            curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
            curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 0);
        }

        $resp = curl_exec($ch);
        $curlErr = curl_error($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $lastStatus = $status;
        $hdrSize = (int) curl_getinfo($ch, CURLINFO_HEADER_SIZE);
        $ct = (string) curl_getinfo($ch, CURLINFO_CONTENT_TYPE);
        $finalUrl = (string) curl_getinfo($ch, CURLINFO_EFFECTIVE_URL);
        curl_close($ch);

        $headersRaw = '';
        $body = '';
        if (is_string($resp)) {
            $headersRaw = substr($resp, 0, $hdrSize);
            $body = substr($resp, $hdrSize);
        }

        preg_match('/X-RateLimit-Limit:\s*(\d+)/i', $headersRaw, $m1);
        preg_match('/X-RateLimit-Used:\s*(\d+)/i', $headersRaw, $m2);
        preg_match('/X-RateLimit-Remaining:\s*(\d+)/i', $headersRaw, $m3);
        if (!empty($m1[1])) {
            $rateLimitInfo = array(
                'limit' => (int) $m1[1],
                'used' => !empty($m2[1]) ? (int) $m2[1] : 0,
                'remaining' => !empty($m3[1]) ? (int) $m3[1] : 0,
            );
        }

        $attempts[] = array(
            'url' => $url,
            'final_url' => $finalUrl,
            'status' => $status,
            'content_type' => $ct,
            'curl_error' => $curlErr ? $curlErr : null,
            'headers_head_800' => substr((string) $headersRaw, 0, 800),
            'body_head_1200' => substr((string) $body, 0, 1200),
        );

        if ($curlErr) {
            $attempts[]['error_human'] = 'Falha de conectividade ao webservice SAF-T. Verifique URL, DNS, rede e certificado.';
            continue;
        }

        if ($status === 401 || $status === 403) {
            $authError = 'Token API inválido ou expirado. Verifique no setup do módulo.';
            continue;
        }

        if ($status === 429) {
            $decoded = is_string($body) ? json_decode($body, true) : null;
            if (is_array($decoded) && !empty($decoded['error'])) {
                $rateLimitError = $decoded['error'];
            } else {
                $rateLimitError = 'Limite de consultas diárias excedido. Tente novamente depois de 24h.';
            }
            return array(
                'ok' => false,
                'status' => 429,
                'attempts' => $attempts,
                'rate_limit' => $rateLimitInfo,
                'rate_limit_error' => $rateLimitError,
                'auth_error' => $authError,
            );
        }

        if ($status === 503) {
            $decoded = is_string($body) ? json_decode($body, true) : null;
            if (is_array($decoded) && !empty($decoded['code']) && $decoded['code'] === 'module_runtime_disabled') {
                return array(
                    'ok' => false,
                    'status' => 503,
                    'attempts' => $attempts,
                    'rate_limit' => $rateLimitInfo,
                    'error' => 'Integração temporariamente desativada no webservice (modo proteção).',
                    'auth_error' => $authError,
                    'rate_limit_error' => null,
                );
            }

            return array(
                'ok' => false,
                'status' => 503,
                'attempts' => $attempts,
                'rate_limit' => $rateLimitInfo,
                'error' => 'Webservice SAF-T indisponível no momento (HTTP 503).',
                'auth_error' => $authError,
                'rate_limit_error' => null,
            );
        }

        if ($status === 200) {
            return array(
                'ok' => true,
                'status' => 200,
                'attempts' => $attempts,
                'rate_limit' => $rateLimitInfo,
                'rate_limit_error' => null,
                'auth_error' => $authError,
            );
        }
    }

    return array(
        'ok' => false,
        'status' => $lastStatus,
        'attempts' => $attempts,
        'rate_limit' => $rateLimitInfo,
        'rate_limit_error' => $rateLimitError,
        'auth_error' => $authError,
        'error' => 'Falha de conectividade ao webservice SAF-T. Não foi possível consumir quota.',
    );
}

/**
 * Consulta status de quota pública sem consumir (GET /api/dolibarr/public/status).
 *
 * @param string $configuredPreviewUrl
 * @param bool $verifyTls
 * @param int $timeout
 * @return array {ok, status, rate_limit?, error?, attempts[]}
 */
function saft_get_public_quota_status($configuredPreviewUrl, $verifyTls = false, $timeout = 10)
{
    $statusUrl = saft_resolve_mode_endpoint_url(
        $configuredPreviewUrl,
        '',
        '/api/dolibarr/public/status',
        '/api/dolibarr/private/me'
    );

    if ($statusUrl === '') {
        return array(
            'ok' => false,
            'status' => 0,
            'error' => 'Missing API configuration.',
            'attempts' => array(),
            'rate_limit' => null,
        );
    }

    $attempts = array();
    $rateLimitInfo = null;
    $lastStatus = 0;
    $lastError = null;

    $candidates = saft_build_api_candidates($statusUrl);
    foreach ($candidates as $url) {
        $headers = array('Accept: application/json');

        $ch = curl_init();
        curl_setopt_array($ch, array(
            CURLOPT_URL => $url,
            CURLOPT_HTTPGET => true,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HEADER => true,
            CURLOPT_TIMEOUT => (int) $timeout,
            CURLOPT_HTTPHEADER => $headers,
        ));

        if (!$verifyTls) {
            curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
            curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 0);
        }

        $resp = curl_exec($ch);
        $curlErr = curl_error($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $lastStatus = $status;
        $hdrSize = (int) curl_getinfo($ch, CURLINFO_HEADER_SIZE);
        $ct = (string) curl_getinfo($ch, CURLINFO_CONTENT_TYPE);
        $finalUrl = (string) curl_getinfo($ch, CURLINFO_EFFECTIVE_URL);
        curl_close($ch);

        $headersRaw = '';
        $body = '';
        if (is_string($resp)) {
            $headersRaw = substr($resp, 0, $hdrSize);
            $body = substr($resp, $hdrSize);
        }

        preg_match('/X-RateLimit-Limit:\s*(\d+)/i', $headersRaw, $m1);
        preg_match('/X-RateLimit-Used:\s*(\d+)/i', $headersRaw, $m2);
        preg_match('/X-RateLimit-Remaining:\s*(\d+)/i', $headersRaw, $m3);
        if (!empty($m1[1])) {
            $rateLimitInfo = array(
                'limit' => (int) $m1[1],
                'used' => !empty($m2[1]) ? (int) $m2[1] : 0,
                'remaining' => !empty($m3[1]) ? (int) $m3[1] : 0,
            );
        }

        // Fallback para cenários sem headers (usar body JSON do /status)
        if ($rateLimitInfo === null && is_string($body) && stripos($ct, 'application/json') !== false) {
            $decoded = json_decode($body, true);
            if (is_array($decoded) && isset($decoded['limit']) && isset($decoded['used']) && isset($decoded['remaining'])) {
                $rateLimitInfo = array(
                    'limit' => (int) $decoded['limit'],
                    'used' => (int) $decoded['used'],
                    'remaining' => (int) $decoded['remaining'],
                );
            }
        }

        $attempts[] = array(
            'url' => $url,
            'final_url' => $finalUrl,
            'status' => $status,
            'content_type' => $ct,
            'curl_error' => $curlErr ? $curlErr : null,
            'headers_head_800' => substr((string) $headersRaw, 0, 800),
            'body_head_1200' => substr((string) $body, 0, 1200),
        );

        if ($curlErr) {
            $lastError = 'Erro de conexão: '.$curlErr;
            continue;
        }

        if ($status === 200 && $rateLimitInfo !== null) {
            return array(
                'ok' => true,
                'status' => 200,
                'attempts' => $attempts,
                'rate_limit' => $rateLimitInfo,
                'error' => null,
            );
        }
    }

    return array(
        'ok' => false,
        'status' => $lastStatus,
        'attempts' => $attempts,
        'rate_limit' => $rateLimitInfo,
        'error' => $lastError ? $lastError : 'Não foi possível consultar status da quota pública.',
    );
}

/**
 * Busca informações do usuário autenticado via /api/dolibarr/private/me
 * 
 * @param string $configuredUrl URL configurada no setup (ex: https://saft-validator.dev.cialinux.com/api/dolibarr/public/validate/preview)
 * @param string $apiToken Token API
 * @param bool $verifyTls Se deve verificar certificado SSL
 * @return array {ok: bool, data?: array, error?: string}
 */
function saft_get_authenticated_user($configuredUrl, $apiToken, $verifyTls = false)
{
    if (empty($apiToken)) {
        return array('ok' => false, 'error' => 'Token vazio');
    }

    // Resolver URL do endpoint /api/dolibarr/private/me
    $meUrl = saft_resolve_mode_endpoint_url(
        $configuredUrl,
        $apiToken,
        '',  // não usado para privado
        '/api/dolibarr/private/me'
    );

    if (empty($meUrl)) {
        return array('ok' => false, 'error' => 'URL base inválida');
    }

    $candidates = saft_build_api_candidates($meUrl);
    $lastError = 'Não foi possível conectar à API';

    foreach ($candidates as $url) {
        $ch = curl_init();
        curl_setopt_array($ch, array(
            CURLOPT_URL => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 10,
            CURLOPT_HTTPHEADER => array(
                'X-API-Key: ' . $apiToken,
                'Accept: application/json',
            ),
        ));

        if (!$verifyTls) {
            curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
            curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 0);
        }

        $response = curl_exec($ch);
        $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlErr = curl_error($ch);
        curl_close($ch);

        if ($curlErr) {
            $lastError = 'Falha de conectividade ao webservice SAF-T: ' . $curlErr;
            continue;
        }

        if ($httpCode === 200 && is_string($response)) {
            $data = json_decode($response, true);
            if (is_array($data)) {
                return array(
                    'ok' => true,
                    'data' => $data,
                );
            }
        }

        if ($httpCode === 401 || $httpCode === 403) {
            return array('ok' => false, 'error' => 'Token inválido ou expirado');
        }

        if ($httpCode === 503 && is_string($response)) {
            $decoded = json_decode($response, true);
            if (is_array($decoded) && !empty($decoded['code']) && $decoded['code'] === 'module_runtime_disabled') {
                return array('ok' => false, 'error' => 'Integração temporariamente desativada no webservice (modo proteção).');
            }
            return array('ok' => false, 'error' => 'Webservice SAF-T indisponível no momento (HTTP 503).');
        }

        $lastError = 'Resposta inesperada do servidor (HTTP '.$httpCode.')';
    }

    return array('ok' => false, 'error' => $lastError);
}

/**
 * Valida um token API chamando o endpoint do saft-validator
 * 
 * @param string $apiToken Token a validar
 * @param string $baseUrl URL base da API (ex: https://saft-validator.dev.cialinux.com)
 * @param bool $verifyTls Se deve verificar certificado SSL
 * @return array {valid: bool, user_data?: array, error?: string}
 */
function saft_validate_api_token($apiToken, $baseUrl, $verifyTls = false)
{
    $result = saft_get_authenticated_user($baseUrl, $apiToken, $verifyTls);
    
    if (!empty($result['ok'])) {
        return array(
            'valid' => true,
            'user_data' => $result['data'],
        );
    }
    
    return array(
        'valid' => false,
        'error' => !empty($result['error']) ? $result['error'] : 'Erro desconhecido',
    );
}

/**
 * Chama a API /validate/preview enviando multipart file=@xmlfile
 *
 * @param string $xmlFilePath  Caminho do XML gravado no disco
 * @param int $page
 * @param int $perPage
 * @param array $opts ['api_url' => 'https://.../api/dolibarr/public/validate/preview', 'verify_tls' => bool, 'timeout' => int, 'api_token' => 'optional_token']
 * @return array { data?, status, used_url?, attempts[], curl_error?, rate_limit: {limit, used, remaining}, auth_error? }
 */
function saft_call_preview_api($xmlFilePath, $page, $perPage, $opts = array())
{
    $apiUrl    = !empty($opts['api_url']) ? $opts['api_url'] : '';
    $apiToken  = !empty($opts['api_token']) ? $opts['api_token'] : '';
    $verifyTls = !empty($opts['verify_tls']) ? true : false;
    $timeout   = !empty($opts['timeout']) ? (int)$opts['timeout'] : 45;

    $attempts = array();
    $data = null;
    $used = null;
    $rateLimitInfo = null;  // SEMPRE null - só preenchido se API retornar headers
    $apiAuthError = null;
    $rateLimitError = null;  // Novo: erro de rate limit (quota excedida)
    $serviceError = null;

    $apiUrl = saft_resolve_mode_endpoint_url(
        $apiUrl,
        $apiToken,
        '/api/dolibarr/public/validate/preview',
        '/api/dolibarr/private/validate/preview'
    );

    if (empty($apiUrl)) {
        return array(
            'data' => null,
            'used_url' => null,
            'attempts' => array(),
            'error' => 'Missing API configuration.',
            'rate_limit' => null,
        );
    }

    if (!is_readable($xmlFilePath)) {
        return array(
            'data' => null,
            'used_url' => null,
            'attempts' => array(),
            'error' => 'XML file not readable: '.$xmlFilePath,
            'rate_limit' => null,
        );
    }

    $candidates = saft_build_api_candidates($apiUrl);

    foreach ($candidates as $base) {
        $url = saft_url_with_params($base, array(
            'page' => (int)$page,
            'per_page' => (int)$perPage,
        ));

        $ch = curl_init();
        $post = array(
            'file' => curl_file_create($xmlFilePath, 'application/xml', basename($xmlFilePath)),
        );

        $headers = array('Content-Type: multipart/form-data');
        
        // Adicionar token se configurado (modo privado)
        if (!empty($apiToken)) {
            $headers[] = 'X-API-Key: ' . $apiToken;
        }

        curl_setopt_array($ch, array(
            CURLOPT_URL => $url,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $post,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HEADER => true,                  // para capturar headers e status
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS => 3,
            CURLOPT_TIMEOUT => $timeout,
            CURLOPT_HTTPHEADER => $headers,
        ));

        if (!$verifyTls) {
            curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
            curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 0);
        }

        $resp = curl_exec($ch);
        $curlErr = curl_error($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $ct = (string) curl_getinfo($ch, CURLINFO_CONTENT_TYPE);
        $finalUrl = (string) curl_getinfo($ch, CURLINFO_EFFECTIVE_URL);
        $hdrSize = (int) curl_getinfo($ch, CURLINFO_HEADER_SIZE);
        curl_close($ch);

        $headersRaw = '';
        $body = '';
        if (is_string($resp)) {
            $headersRaw = substr($resp, 0, $hdrSize);
            $body = substr($resp, $hdrSize);
        }

        // Extrair headers de rate limit - SÓ se a API enviar
        preg_match('/X-RateLimit-Limit:\s*(\d+)/i', $headersRaw, $m1);
        preg_match('/X-RateLimit-Used:\s*(\d+)/i', $headersRaw, $m2);
        preg_match('/X-RateLimit-Remaining:\s*(\d+)/i', $headersRaw, $m3);
        
        // DEBUG: Log dos headers para troubleshooting
        $debugRateHeaders = array(
            'X-RateLimit-Limit' => !empty($m1[1]) ? $m1[1] : 'NOT FOUND',
            'X-RateLimit-Used' => !empty($m2[1]) ? $m2[1] : 'NOT FOUND',
            'X-RateLimit-Remaining' => !empty($m3[1]) ? $m3[1] : 'NOT FOUND',
        );
        
        // APENAS preencher se API retornar os headers
        if (!empty($m1[1])) {
            $rateLimitInfo = array(
                'limit' => (int)$m1[1],
                'used' => !empty($m2[1]) ? (int)$m2[1] : 0,
                'remaining' => !empty($m3[1]) ? (int)$m3[1] : 0,
            );
        }

        $bodyHead = is_string($body) ? substr($body, 0, 1200) : '';
        $attempts[] = array(
            'url' => $url,
            'final_url' => $finalUrl,
            'status' => $status,
            'content_type' => $ct,
            'curl_error' => $curlErr ? $curlErr : null,
            'headers_head_800' => substr((string)$headersRaw, 0, 800),
            'body_head_1200' => $bodyHead,
            'debug_rate_headers' => $debugRateHeaders,  // DEBUG
        );

        // Verificar erro de autenticação
        if ($status === 401 || $status === 403) {
            $apiAuthError = 'Token API inválido ou expirado. Verifique no setup do módulo.';
            continue;  // tentar próxima candidata
        }

        if ($status === 503) {
            $decoded503 = is_string($body) ? json_decode($body, true) : null;
            if (is_array($decoded503) && !empty($decoded503['code']) && $decoded503['code'] === 'module_runtime_disabled') {
                $serviceError = 'Integração temporariamente desativada no webservice (modo proteção).';
            } else {
                $serviceError = 'Webservice SAF-T indisponível no momento (HTTP 503).';
            }
            continue;
        }

        // Verificar erro de rate limit (quota excedida)
        if ($status === 429) {
            $quotaPeriodText = !empty($apiToken) ? 'mensais' : 'diárias';
            if (is_string($body)) {
                $decoded = json_decode($body, true);
                if (is_array($decoded) && !empty($decoded['error'])) {
                    $rateLimitError = $decoded['error'];
                } else {
                    $rateLimitError = 'Limite de consultas '.$quotaPeriodText.' excedido.';
                }
            } else {
                $rateLimitError = 'Limite de consultas '.$quotaPeriodText.' excedido.';
            }
            continue;  // tentar próxima candidata
        }

        // sucesso JSON
        if ($status === 200 && stripos($ct, 'application/json') !== false && is_string($body)) {
            $decoded = json_decode($body, true);
            if (is_array($decoded)) {
                $data = $decoded;
                $used = $url;
                break;
            }
        }
    }

    return array(
        'data' => $data,
        'used_url' => $used,
        'verify_tls' => $verifyTls,
        'attempts' => $attempts,
        'rate_limit' => $rateLimitInfo,
        'auth_error' => $apiAuthError,
        'rate_limit_error' => $rateLimitError,
        'error' => $serviceError ? $serviceError : 'Falha de conectividade ao webservice SAF-T.',
    );
}

/**
 * Build full URL for a fixed endpoint path using the configured preview URL base.
 *
 * @param string $configuredPreviewUrl
 * @param string $endpointPath
 * @return string
 */
function saft_build_endpoint_url($configuredPreviewUrl, $endpointPath)
{
    $configuredPreviewUrl = trim((string) $configuredPreviewUrl);
    $endpointPath = '/'.ltrim((string) $endpointPath, '/');

    if ($configuredPreviewUrl === '') {
        return '';
    }

    $p = @parse_url($configuredPreviewUrl);
    if (!is_array($p) || empty($p['host'])) {
        return '';
    }

    $scheme = !empty($p['scheme']) ? $p['scheme'] : 'https';
    $host = $p['host'];
    $port = !empty($p['port']) ? (':'.$p['port']) : '';

    return $scheme.'://'.$host.$port.$endpointPath;
}

/**
 * Call Phase 2 create session endpoint.
 *
 * @param string $xmlFilePath
 * @param array $opts ['api_url','api_token','verify_tls','timeout','user_nif']
 * @return array
 */
function saft_call_sessions_create($xmlFilePath, $opts = array())
{
    $apiUrl = !empty($opts['api_url']) ? (string) $opts['api_url'] : '';
    $apiToken = !empty($opts['api_token']) ? (string) $opts['api_token'] : '';
    $verifyTls = !empty($opts['verify_tls']) ? true : false;
    $timeout = !empty($opts['timeout']) ? (int) $opts['timeout'] : 60;
    $userNif = !empty($opts['user_nif']) ? trim((string) $opts['user_nif']) : '';

    if (!is_readable($xmlFilePath)) {
        return array('data' => null, 'status' => 0, 'error' => 'XML file not readable: '.$xmlFilePath, 'attempts' => array());
    }

    $url = saft_build_endpoint_url($apiUrl, '/api/dolibarr/sessions');
    if ($url === '') {
        return array('data' => null, 'status' => 0, 'error' => 'Missing API configuration.', 'attempts' => array());
    }
    if ($userNif !== '') {
        $url = saft_url_with_params($url, array('nif' => $userNif));
    }

    $attempts = array();
    $lastStatus = 0;

    foreach (saft_build_api_candidates($url) as $candidateUrl) {
        $ch = curl_init();
        $headers = array('Accept: application/json', 'Content-Type: multipart/form-data');
        if ($apiToken !== '') {
            $headers[] = 'X-API-Key: '.$apiToken;
        }

        curl_setopt_array($ch, array(
            CURLOPT_URL => $candidateUrl,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => array(
                'file' => curl_file_create($xmlFilePath, 'application/xml', basename($xmlFilePath)),
            ),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HEADER => true,
            CURLOPT_TIMEOUT => $timeout,
            CURLOPT_HTTPHEADER => $headers,
        ));

        if (!$verifyTls) {
            curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
            curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 0);
        }

        $resp = curl_exec($ch);
        $curlErr = curl_error($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $lastStatus = $status;
        $ct = (string) curl_getinfo($ch, CURLINFO_CONTENT_TYPE);
        $hdrSize = (int) curl_getinfo($ch, CURLINFO_HEADER_SIZE);
        $finalUrl = (string) curl_getinfo($ch, CURLINFO_EFFECTIVE_URL);
        curl_close($ch);

        $headersRaw = '';
        $body = '';
        if (is_string($resp)) {
            $headersRaw = substr($resp, 0, $hdrSize);
            $body = substr($resp, $hdrSize);
        }

        $attempts[] = array(
            'url' => $candidateUrl,
            'final_url' => $finalUrl,
            'status' => $status,
            'content_type' => $ct,
            'curl_error' => $curlErr ? $curlErr : null,
            'headers_head_800' => substr((string) $headersRaw, 0, 800),
            'body_head_1200' => substr((string) $body, 0, 1200),
        );

        if ($curlErr) {
            continue;
        }

        $decoded = is_string($body) ? json_decode($body, true) : null;
        if (is_array($decoded) && ($status === 201 || $status === 200) && !empty($decoded['ok'])) {
            return array('data' => $decoded, 'status' => $status, 'error' => null, 'attempts' => $attempts);
        }

        if (is_array($decoded) && !empty($decoded['error'])) {
            return array('data' => $decoded, 'status' => $status, 'error' => (string) $decoded['error'], 'attempts' => $attempts);
        }
    }

    return array('data' => null, 'status' => $lastStatus, 'error' => 'Falha de conectividade ao webservice SAF-T.', 'attempts' => $attempts);
}

/**
 * Call Phase 2 get session endpoint.
 *
 * @param string $sessionId
 * @param int $page
 * @param int $perPage
 * @param array $opts
 * @return array
 */
function saft_call_sessions_get($sessionId, $page, $perPage, $opts = array())
{
    $apiUrl = !empty($opts['api_url']) ? (string) $opts['api_url'] : '';
    $apiToken = !empty($opts['api_token']) ? (string) $opts['api_token'] : '';
    $verifyTls = !empty($opts['verify_tls']) ? true : false;
    $timeout = !empty($opts['timeout']) ? (int) $opts['timeout'] : 30;

    $sessionId = trim((string) $sessionId);
    if ($sessionId === '') {
        return array('data' => null, 'status' => 0, 'error' => 'Session id vazio.', 'attempts' => array());
    }

    $url = saft_build_endpoint_url($apiUrl, '/api/dolibarr/sessions/'.$sessionId);
    if ($url === '') {
        return array('data' => null, 'status' => 0, 'error' => 'Missing API configuration.', 'attempts' => array());
    }
    $url = saft_url_with_params($url, array('page' => (int) $page, 'per_page' => (int) $perPage));

    $attempts = array();
    $lastStatus = 0;

    foreach (saft_build_api_candidates($url) as $candidateUrl) {
        $ch = curl_init();
        $headers = array('Accept: application/json');
        if ($apiToken !== '') {
            $headers[] = 'X-API-Key: '.$apiToken;
        }

        curl_setopt_array($ch, array(
            CURLOPT_URL => $candidateUrl,
            CURLOPT_HTTPGET => true,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HEADER => true,
            CURLOPT_TIMEOUT => $timeout,
            CURLOPT_HTTPHEADER => $headers,
        ));

        if (!$verifyTls) {
            curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
            curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 0);
        }

        $resp = curl_exec($ch);
        $curlErr = curl_error($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $lastStatus = $status;
        $ct = (string) curl_getinfo($ch, CURLINFO_CONTENT_TYPE);
        $hdrSize = (int) curl_getinfo($ch, CURLINFO_HEADER_SIZE);
        $finalUrl = (string) curl_getinfo($ch, CURLINFO_EFFECTIVE_URL);
        curl_close($ch);

        $headersRaw = '';
        $body = '';
        if (is_string($resp)) {
            $headersRaw = substr($resp, 0, $hdrSize);
            $body = substr($resp, $hdrSize);
        }

        $attempts[] = array(
            'url' => $candidateUrl,
            'final_url' => $finalUrl,
            'status' => $status,
            'content_type' => $ct,
            'curl_error' => $curlErr ? $curlErr : null,
            'headers_head_800' => substr((string) $headersRaw, 0, 800),
            'body_head_1200' => substr((string) $body, 0, 1200),
        );

        if ($curlErr) {
            continue;
        }

        $decoded = is_string($body) ? json_decode($body, true) : null;
        if (is_array($decoded) && $status === 200 && !empty($decoded['ok'])) {
            return array('data' => $decoded, 'status' => $status, 'error' => null, 'attempts' => $attempts);
        }

        if (is_array($decoded) && !empty($decoded['error'])) {
            return array('data' => $decoded, 'status' => $status, 'error' => (string) $decoded['error'], 'attempts' => $attempts);
        }
    }

    return array('data' => null, 'status' => $lastStatus, 'error' => 'Falha de conectividade ao webservice SAF-T.', 'attempts' => $attempts);
}

/**
 * Call Phase 2 commit endpoint.
 *
 * @param string $sessionId
 * @param array $selectedIndexes
 * @param array $opts
 * @return array
 */
function saft_call_sessions_commit($sessionId, $selectedIndexes, $opts = array())
{
    $apiUrl = !empty($opts['api_url']) ? (string) $opts['api_url'] : '';
    $apiToken = !empty($opts['api_token']) ? (string) $opts['api_token'] : '';
    $verifyTls = !empty($opts['verify_tls']) ? true : false;
    $timeout = !empty($opts['timeout']) ? (int) $opts['timeout'] : 60;
    $skipDuplicates = array_key_exists('skip_duplicates', $opts) ? (bool) $opts['skip_duplicates'] : true;
    $userId = !empty($opts['user_id']) ? (int) $opts['user_id'] : null;

    $sessionId = trim((string) $sessionId);
    if ($sessionId === '') {
        return array('data' => null, 'status' => 0, 'error' => 'Session id vazio.', 'attempts' => array());
    }

    $url = saft_build_endpoint_url($apiUrl, '/api/dolibarr/sessions/'.$sessionId.'/commit');
    if ($url === '') {
        return array('data' => null, 'status' => 0, 'error' => 'Missing API configuration.', 'attempts' => array());
    }

    $payload = array(
        'selected_indices' => array_values(array_map('intval', (array) $selectedIndexes)),
        'skip_duplicates' => $skipDuplicates,
        'user_id' => $userId,
    );

    $attempts = array();
    $lastStatus = 0;

    foreach (saft_build_api_candidates($url) as $candidateUrl) {
        $ch = curl_init();
        $jsonPayload = json_encode($payload);
        $headers = array('Accept: application/json', 'Content-Type: application/json');
        if ($apiToken !== '') {
            $headers[] = 'X-API-Key: '.$apiToken;
        }

        curl_setopt_array($ch, array(
            CURLOPT_URL => $candidateUrl,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $jsonPayload,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HEADER => true,
            CURLOPT_TIMEOUT => $timeout,
            CURLOPT_HTTPHEADER => $headers,
        ));

        if (!$verifyTls) {
            curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
            curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 0);
        }

        $resp = curl_exec($ch);
        $curlErr = curl_error($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $lastStatus = $status;
        $ct = (string) curl_getinfo($ch, CURLINFO_CONTENT_TYPE);
        $hdrSize = (int) curl_getinfo($ch, CURLINFO_HEADER_SIZE);
        $finalUrl = (string) curl_getinfo($ch, CURLINFO_EFFECTIVE_URL);
        curl_close($ch);

        $headersRaw = '';
        $body = '';
        if (is_string($resp)) {
            $headersRaw = substr($resp, 0, $hdrSize);
            $body = substr($resp, $hdrSize);
        }

        $attempts[] = array(
            'url' => $candidateUrl,
            'final_url' => $finalUrl,
            'status' => $status,
            'content_type' => $ct,
            'curl_error' => $curlErr ? $curlErr : null,
            'headers_head_800' => substr((string) $headersRaw, 0, 800),
            'body_head_1200' => substr((string) $body, 0, 1200),
        );

        if ($curlErr) {
            continue;
        }

        $decoded = is_string($body) ? json_decode($body, true) : null;
        if (is_array($decoded) && $status === 200 && !empty($decoded['ok'])) {
            return array('data' => $decoded, 'status' => $status, 'error' => null, 'attempts' => $attempts);
        }

        if (is_array($decoded) && !empty($decoded['error'])) {
            return array('data' => $decoded, 'status' => $status, 'error' => (string) $decoded['error'], 'attempts' => $attempts);
        }
    }

    return array('data' => null, 'status' => $lastStatus, 'error' => 'Falha de conectividade ao webservice SAF-T.', 'attempts' => $attempts);
}

/**
 * Call Phase 2 delete session endpoint.
 *
 * @param string $sessionId
 * @param array $opts
 * @return array
 */
function saft_call_sessions_delete($sessionId, $opts = array())
{
    $apiUrl = !empty($opts['api_url']) ? (string) $opts['api_url'] : '';
    $apiToken = !empty($opts['api_token']) ? (string) $opts['api_token'] : '';
    $verifyTls = !empty($opts['verify_tls']) ? true : false;
    $timeout = !empty($opts['timeout']) ? (int) $opts['timeout'] : 20;

    $sessionId = trim((string) $sessionId);
    if ($sessionId === '') {
        return array('data' => null, 'status' => 0, 'error' => 'Session id vazio.', 'attempts' => array());
    }

    $url = saft_build_endpoint_url($apiUrl, '/api/dolibarr/sessions/'.$sessionId);
    if ($url === '') {
        return array('data' => null, 'status' => 0, 'error' => 'Missing API configuration.', 'attempts' => array());
    }

    $attempts = array();
    $lastStatus = 0;

    foreach (saft_build_api_candidates($url) as $candidateUrl) {
        $ch = curl_init();
        $headers = array('Accept: application/json');
        if ($apiToken !== '') {
            $headers[] = 'X-API-Key: '.$apiToken;
        }

        curl_setopt_array($ch, array(
            CURLOPT_URL => $candidateUrl,
            CURLOPT_CUSTOMREQUEST => 'DELETE',
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HEADER => true,
            CURLOPT_TIMEOUT => $timeout,
            CURLOPT_HTTPHEADER => $headers,
        ));

        if (!$verifyTls) {
            curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
            curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 0);
        }

        $resp = curl_exec($ch);
        $curlErr = curl_error($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $lastStatus = $status;
        $ct = (string) curl_getinfo($ch, CURLINFO_CONTENT_TYPE);
        $hdrSize = (int) curl_getinfo($ch, CURLINFO_HEADER_SIZE);
        $finalUrl = (string) curl_getinfo($ch, CURLINFO_EFFECTIVE_URL);
        curl_close($ch);

        $headersRaw = '';
        $body = '';
        if (is_string($resp)) {
            $headersRaw = substr($resp, 0, $hdrSize);
            $body = substr($resp, $hdrSize);
        }

        $attempts[] = array(
            'url' => $candidateUrl,
            'final_url' => $finalUrl,
            'status' => $status,
            'content_type' => $ct,
            'curl_error' => $curlErr ? $curlErr : null,
            'headers_head_800' => substr((string) $headersRaw, 0, 800),
            'body_head_1200' => substr((string) $body, 0, 1200),
        );

        if ($curlErr) {
            continue;
        }

        $decoded = is_string($body) ? json_decode($body, true) : null;
        if ($status === 200 && is_array($decoded) && !empty($decoded['ok'])) {
            return array('data' => $decoded, 'status' => $status, 'error' => null, 'attempts' => $attempts);
        }

        if ($status === 404) {
            return array('data' => $decoded, 'status' => $status, 'error' => null, 'attempts' => $attempts);
        }

        if (is_array($decoded) && !empty($decoded['error'])) {
            return array('data' => $decoded, 'status' => $status, 'error' => (string) $decoded['error'], 'attempts' => $attempts);
        }
    }

    return array('data' => null, 'status' => $lastStatus, 'error' => 'Falha de conectividade ao webservice SAF-T.', 'attempts' => $attempts);
}
