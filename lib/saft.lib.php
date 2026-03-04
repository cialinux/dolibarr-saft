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
 * Chama a API /validate/preview enviando multipart file=@xmlfile
 *
 * @param string $xmlFilePath  Caminho do XML gravado no disco
 * @param int $page
 * @param int $perPage
 * @param array $opts ['api_url' => 'https://.../api/public/validate/preview', 'verify_tls' => bool, 'timeout' => int, 'api_token' => 'optional_token']
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
    $rateLimitInfo = array('limit' => 5, 'used' => 0, 'remaining' => 5);
    $apiAuthError = null;

    if (empty($apiUrl)) {
        return array(
            'data' => null,
            'used_url' => null,
            'attempts' => array(),
            'error' => 'Missing api_url (SAFT_API_URL)',
            'rate_limit' => $rateLimitInfo,
        );
    }

    if (!is_readable($xmlFilePath)) {
        return array(
            'data' => null,
            'used_url' => null,
            'attempts' => array(),
            'error' => 'XML file not readable: '.$xmlFilePath,
            'rate_limit' => $rateLimitInfo,
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

        // Extrair headers de rate limit
        preg_match('/X-RateLimit-Limit:\s*(\d+)/i', $headersRaw, $m1);
        preg_match('/X-RateLimit-Used:\s*(\d+)/i', $headersRaw, $m2);
        preg_match('/X-RateLimit-Remaining:\s*(\d+)/i', $headersRaw, $m3);
        
        if (!empty($m1[1])) {
            $rateLimitInfo['limit'] = (int)$m1[1];
            $rateLimitInfo['used'] = !empty($m2[1]) ? (int)$m2[1] : 0;
            $rateLimitInfo['remaining'] = !empty($m3[1]) ? (int)$m3[1] : 0;
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
        );

        // Verificar erro de autenticação
        if ($status === 401 || $status === 403) {
            $apiAuthError = 'Token API inválido ou expirado. Verifique no setup do módulo.';
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
    );
