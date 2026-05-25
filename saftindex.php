<?php
/* Copyright (C) 2026  Cia Linux  <general@cialinux.com>
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

$res = 0;
if (!$res && !empty($_SERVER["CONTEXT_DOCUMENT_ROOT"])) {
    $res = @include $_SERVER["CONTEXT_DOCUMENT_ROOT"]."/main.inc.php";
}
if (!$res && file_exists("../main.inc.php")) $res = @include "../main.inc.php";
if (!$res && file_exists("../../main.inc.php")) $res = @include "../../main.inc.php";
if (!$res) die("Include of main fails");

require_once DOL_DOCUMENT_ROOT.'/core/class/html.formfile.class.php';
require_once __DIR__.'/lib/saft.lib.php';

$langs->loadLangs(array("saft@saft"));

$action = GETPOST('action', 'aZ09');
$page = max(1, GETPOSTINT('page'));
$sessionId = GETPOST('session_id', 'alphanohtml');

$apiRuntime = saft_get_runtime_api_config();
$apiUrlPreview = $apiRuntime['api_url'];
$apiToken = getDolGlobalString('SAFT_API_TOKEN', '');
$verifyTls = (bool) $apiRuntime['verify_tls'];
$perPage = max(1, (int) getDolGlobalInt('SAFT_PER_PAGE', 10));
$clientDebug = (bool) getDolGlobalInt('SAFT_CLIENT_DEBUG', 1);

$error = null;
$data = null;
$debug = null;
$rateLimit = null;
$apiMode = !empty($apiToken) ? 'private' : 'public';

if (!empty($apiToken)) {
    $userInfo = saft_get_authenticated_user($apiUrlPreview, $apiToken, $verifyTls);
    if (!empty($userInfo['ok']) && !empty($userInfo['data'])) {
        $userData = $userInfo['data'];
        $dailyLimit = !empty($userData['daily_limit']) ? (int) $userData['daily_limit'] : 15;
        $usageToday = !empty($userData['usage_month']) ? (int) $userData['usage_month'] : (!empty($userData['usage_today']) ? (int) $userData['usage_today'] : 0);
        $rateLimit = array(
            'limit' => $dailyLimit,
            'used' => $usageToday,
            'remaining' => max(0, $dailyLimit - $usageToday),
        );
    }
} else {
    $tempCheck = saft_get_public_quota_status($apiUrlPreview, $verifyTls, 5);
    if (!empty($tempCheck['rate_limit'])) {
        $rateLimit = $tempCheck['rate_limit'];
    }
}

if ($action === 'validate') {
    if (!empty($_FILES['file']['tmp_name'])) {
        if (!empty($_FILES['file']['size']) && (int) $_FILES['file']['size'] > 1 * 1024 * 1024) {
            $error = 'file size limit max 1mb';
        } else {
            $uploadTokenNif = !empty($apiToken) ? (string) $user->login : '';
            $create = saft_call_sessions_create(
                $_FILES['file']['tmp_name'],
                array(
                    'api_url' => $apiUrlPreview,
                    'api_token' => $apiToken,
                    'verify_tls' => $verifyTls,
                    'timeout' => 60,
                    'purpose' => 'validate',
                    'user_nif' => $uploadTokenNif,
                )
            );

            if (empty($create['data']) || empty($create['data']['ok']) || empty($create['data']['session_id'])) {
                $error = !empty($create['error']) ? $create['error'] : 'Falha ao iniciar sessão de validação no webservice SAF-T.';
                $debug = json_encode($create, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
            } else {
                $sessionId = (string) $create['data']['session_id'];
                $page = 1;
                if (!empty($create['data']['rate_limit']) && is_array($create['data']['rate_limit'])) {
                    $rateLimit = $create['data']['rate_limit'];
                }
                $debug = json_encode($create, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
            }
        }
    }

    if (!$error && $sessionId !== '') {
        $apiPerPage = min(100, max(1, $perPage)); // Cap at API limit of 100
        $get = saft_call_sessions_get(
            $sessionId,
            $page,
            $apiPerPage,
            array(
                'api_url' => $apiUrlPreview,
                'api_token' => $apiToken,
                'verify_tls' => $verifyTls,
                'timeout' => 30,
            )
        );

        if (empty($get['data']) || empty($get['data']['ok'])) {
            $error = !empty($get['error']) ? $get['error'] : 'Falha ao carregar sessão de validação.';
        } else {
            $payload = $get['data'];
            $totalInvoices = (int) (!empty($payload['total_invoices']) ? $payload['total_invoices'] : 0);
            $pages = max(1, (int) ceil($totalInvoices / max(1, $perPage)));

            $data = array(
                'valid' => true,
                'errors' => array(),
                'invoice_count' => $totalInvoices,
                'invoice_views' => !empty($payload['invoices']) && is_array($payload['invoices']) ? $payload['invoices'] : array(),
                'page' => (int) $page,
                'per_page' => (int) $perPage,
                'pages' => $pages,
            );
        }

        if (!empty($debug)) {
            $debug = $debug."\n\n".json_encode($get, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
        } else {
            $debug = json_encode($get, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
        }
    }
}

llxHeader("", $langs->trans("SaftArea"), '', '', 0, 0, '', '', '', 'mod-saft');
print load_fiche_titre($langs->trans("SaftArea"), '', 'saft.png@saft');

$csrfToken = newToken();

print '<div class="fichecenter">';
print '  <div class="fichehalfleft">';

print '    <div class="card" style="padding:16px;">';
print '      <h2>Validador SAF-T (Portugal)</h2>';
print '      <div class="opacitymedium">'.dol_escape_htmltag($apiRuntime['label']).'</div>';
print '      <div style="margin:12px 0; padding:8px; background:#f0f0f0; border-radius:4px;">';
print '        <strong>Modo:</strong> '.($apiMode === 'private' ? '🔒 Privado' : '🔓 Público');
if ($rateLimit) {
    $periodLabel = ($apiMode === 'private') ? 'consultas/mes' : 'consultas/dia';
    print '        | <strong>Limites:</strong> '.$rateLimit['used'].'/'.$rateLimit['limit'].' '.$periodLabel;
    print '        | <strong>Restantes:</strong> '.$rateLimit['remaining'];
} else {
    print '        | <strong>Limites:</strong> Aguardando resposta da API';
}
print '        | <strong>file size limit:</strong> max 1mb';
print '      </div>';
if (!empty($apiToken)) {
    print '      <div style="margin:12px 0;">';
    print '        <a class="button" href="'.dol_buildpath('/custom/saft/issue.php?mainmenu=saft&leftmenu=saft_issue', 1).'">Emitir fatura via API</a>';
    print '      </div>';
}

print '      <form method="POST" enctype="multipart/form-data" id="saft-upload-form">';
print '        <input type="hidden" name="token" value="'.$csrfToken.'">';
print '        <input type="hidden" name="action" value="validate">';
print '        <input type="hidden" name="page" value="1">';
print '        <div style="margin:8px 0;"><label><input type="file" name="file" accept=".xml" required id="saft-file-input"> Seleccionar XML</label></div>';
print '        <div id="saft-file-error" style="color:red; display:none; margin:8px 0;"></div>';
print '        <input type="submit" class="button" value="Validar" id="saft-submit-btn">';
print '      </form>';

if (!empty($sessionId)) {
    print '  <div class="opacitymedium">Sessão API: '.dol_escape_htmltag($sessionId).'</div>';
}

print '    </div>';
print '    <script>';
print '      const MAX_UPLOAD_BYTES = 1 * 1024 * 1024;';
print '      const fileInput = document.getElementById("saft-file-input");';
print '      const errorDiv = document.getElementById("saft-file-error");';
print '      const submitBtn = document.getElementById("saft-submit-btn");';
print '      const form = document.getElementById("saft-upload-form");';
print '      fileInput.addEventListener("change", function() {';
print '        errorDiv.style.display = "none";';
print '        errorDiv.textContent = "";';
print '        submitBtn.disabled = false;';
print '        if (this.files && this.files[0] && this.files[0].size > MAX_UPLOAD_BYTES) {';
print '          errorDiv.textContent = "file size limit max 1mb";';
print '          errorDiv.style.display = "block";';
print '          submitBtn.disabled = true;';
print '        }';
print '      });';
print '      form.addEventListener("submit", function(e) {';
print '        if (fileInput.files && fileInput.files[0] && fileInput.files[0].size > MAX_UPLOAD_BYTES) {';
print '          e.preventDefault();';
print '          errorDiv.textContent = "file size limit max 1mb";';
print '          errorDiv.style.display = "block";';
print '        }';
print '      });';
print '    </script>';

if ($error) {
    print '  <div class="error">'.dol_escape_htmltag($error).'</div>';
}

print '  </div>';
print '</div>';

if ($data && !empty($data['invoice_views'])) {
    if (!empty($data['pages']) && (int) $data['pages'] > 1) {
        print '<div class="pagination" style="margin:10px 0;">';
        for ($i = 1; $i <= (int) $data['pages']; $i++) {
            print '<form method="POST" style="display:inline-block;margin-right:6px;">';
            print '  <input type="hidden" name="token" value="'.$csrfToken.'">';
            print '  <input type="hidden" name="action" value="validate">';
            print '  <input type="hidden" name="session_id" value="'.dol_escape_htmltag($sessionId).'">';
            print '  <input type="hidden" name="page" value="'.$i.'">';
            print '  <button '.($i == $page ? 'disabled' : '').'>'.$i.'</button>';
            print '</form>';
        }
        print '</div>';
    }

    print '<div style="max-width:820px; margin:20px auto;">';
    foreach ($data['invoice_views'] as $iv) {
        // Badge de hash status é renderizado dentro do template (invoice_from_xml.tpl.php)
        include __DIR__.'/tpl/invoice_from_xml.tpl.php';
    }

    if (!empty($data['pages']) && (int) $data['pages'] > 1) {
        print '<div class="pagination" style="margin:10px 0;">';
        for ($i = 1; $i <= (int) $data['pages']; $i++) {
            print '<form method="POST" style="display:inline-block;margin-right:6px;">';
            print '  <input type="hidden" name="token" value="'.$csrfToken.'">';
            print '  <input type="hidden" name="action" value="validate">';
            print '  <input type="hidden" name="session_id" value="'.dol_escape_htmltag($sessionId).'">';
            print '  <input type="hidden" name="page" value="'.$i.'">';
            print '  <button '.($i == $page ? 'disabled' : '').'>'.$i.'</button>';
            print '</form>';
        }
        print '</div>';
    }

    if (!empty($clientDebug) && !empty($debug)) {
        print '<div style="margin-top:10px;">Debug:</div><br>';
        print '<details>';
        print '<summary style="cursor:pointer;font-weight:bold;">Debug técnico (clique para abrir)</summary>';
        print '<pre style="max-height:260px; overflow:auto; white-space:pre-wrap; word-break:break-all; font-size:11px;">'.dol_escape_htmltag($debug).'</pre>';
        print '</details>';
    }
    print '</div>';
}

llxFooter();
$db->close();
