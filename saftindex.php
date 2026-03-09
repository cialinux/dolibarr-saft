<?php
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

$action    = GETPOST('action', 'aZ09');
$page      = max(1, GETPOSTINT('page'));
$tokenXml  = GETPOST('tokenxml', 'alphanohtml');   // tem que persistir entre requests

$apiUrlPreview = getDolGlobalString('SAFT_API_URL', '');
$apiToken      = getDolGlobalString('SAFT_API_TOKEN', '');
$verifyTls     = (bool) getDolGlobalInt('SAFT_VERIFY_TLS', 0);
$perPage       = max(1, (int) getDolGlobalInt('SAFT_PER_PAGE', 10));
$clientDebug   = (bool) getDolGlobalInt('SAFT_CLIENT_DEBUG', 1);

/* ==================================================
   Helpers (mantém XML no disco por "sessão")
   ================================================== */
function saftTmpFile($token) {
    return DOL_DATA_ROOT.'/saft/saft_'.$token.'.xml';
}
function saftSaveXml($token, $content) {
    dol_mkdir(DOL_DATA_ROOT.'/saft');
    file_put_contents(saftTmpFile($token), $content);
}
function saftLoadXml($token) {
    return file_exists(saftTmpFile($token)) ? file_get_contents(saftTmpFile($token)) : null;
}

$error = null;
$data  = null;
$debug = null;
$rateLimit = null;  // Será preenchido pela resposta da API
$apiMode = !empty($apiToken) ? 'private' : 'public';  // Modo baseado na configuração

// Buscar limites imediatamente ao carregar a página (em vez de esperar validação)
if (empty($rateLimit)) {
    if (!empty($apiToken)) {
        // Modo privado: buscar dados do usuário
        $userInfo = saft_get_authenticated_user($apiUrlPreview, $apiToken, $verifyTls);
        if (!empty($userInfo['ok']) && !empty($userInfo['data'])) {
            $userData = $userInfo['data'];
            $dailyLimit = !empty($userData['daily_limit']) ? (int)$userData['daily_limit'] : 15;
            $usageToday = !empty($userData['usage_month']) ? (int)$userData['usage_month'] : (!empty($userData['usage_today']) ? (int)$userData['usage_today'] : 0);
            $rateLimit = array(
                'limit' => $dailyLimit,
                'used' => $usageToday,
                'remaining' => max(0, $dailyLimit - $usageToday),
            );
        }
    } else {
        // Modo público: consultar status sem consumo para exibir limites no menu.
        $tempCheck = saft_get_public_quota_status($apiUrlPreview, $verifyTls, 5);
        if (!empty($tempCheck['rate_limit'])) {
            $rateLimit = $tempCheck['rate_limit'];
        }
    }
}

if ($action === 'validate') {

    // 1) Se veio ficheiro novo, grava e gera tokenXml novo
    if (!empty($_FILES['file']['tmp_name'])) {
        $xml = file_get_contents($_FILES['file']['tmp_name']);
        $tokenXml = dol_print_date(dol_now(), '%Y%m%d%H%M%S').'-'.random_int(1000,9999);
        saftSaveXml($tokenXml, $xml);
    } else {
        // 2) Se não veio ficheiro, tenta recuperar o XML do tokenXml
        $xml = saftLoadXml($tokenXml);
        if (!$xml) $error = 'Token expirado. Envie novamente o ficheiro.';
    }

    // 3) Se temos XML, chama API
    if (!$error && $xml) {
        // Validar tamanho do ficheiro (1MB máximo)
        $fileSize = filesize(saftTmpFile($tokenXml));
        if ($fileSize > 1 * 1024 * 1024) {
            $error = 'file size limit max 1mb';
        } else {
            $res = saft_call_preview_api(
                saftTmpFile($tokenXml),
                $page,
                $perPage,
                [
                    'api_url'    => $apiUrlPreview,
                    'api_token'  => $apiToken,
                    'verify_tls' => $verifyTls,
                    'timeout'    => 60,
                ]
            );

            // SEMPRE capturar limites da resposta (se disponível)
            if (!empty($res['rate_limit'])) {
                $rateLimit = $res['rate_limit'];
            }

            // Se temos erro de autenticação do token
            if (!empty($res['auth_error'])) {
                $error = '🔒 ' . $res['auth_error'] . '<br><br>Verifique o token configurado no <a href="admin/setup.php">setup do módulo</a>.';
                // Token inválido - não processar nada
            } elseif (!empty($res['rate_limit_error'])) {
                $error = '❌ ' . $res['rate_limit_error'];
                // Quota excedida - não processar nada
            } elseif (empty($res['data'])) {
                $error = 'Erro ao validar SAF-T.';
            } else {
                $data = $res['data'];
            }
            $debug = json_encode($res, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
        }
    }
}

/* ==================================================
   VIEW (UM ÚNICO llxHeader + CSS do módulo)
   ================================================== */
llxHeader("", $langs->trans("SaftArea"), '', '', 0, 0, '', '', '', 'mod-saft');

print load_fiche_titre($langs->trans("SaftArea"), '', 'saft.png@saft');

$csrfToken = newToken();

/* === TOPO (FORM) === */
print '<div class="fichecenter">';
print '  <div class="fichehalfleft">';

print '    <div class="card" style="padding:16px;">';
print '      <h2>Validador SAF-T (Portugal)</h2>';
print '      <div class="opacitymedium">API: '.dol_escape_htmltag($apiUrlPreview).'</div>';
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

print '      <form method="POST" enctype="multipart/form-data" id="saft-upload-form">';
print '        <input type="hidden" name="token" value="'.$csrfToken.'">';
print '        <input type="hidden" name="action" value="validate">';
print '        <input type="hidden" name="page" value="1">'; // novo upload volta para a página 1
print '        <div style="margin:8px 0;"><label><input type="file" name="file" accept=".xml" required id="saft-file-input"> Seleccionar XML</label></div>';
print '        <div id="saft-file-error" style="color:red; display:none; margin:8px 0;"></div>';
print '        <input type="submit" class="button" value="Validar" id="saft-submit-btn">';
print '      </form>';

if (!empty($tokenXml)) {
    print '  <div class="opacitymedium">Sessão: '.dol_escape_htmltag($tokenXml).'</div>';
}

print '    </div>';
print '    <script>';
print '      const MAX_UPLOAD_BYTES = 1 * 1024 * 1024;';
print '      const fileInput = document.getElementById("saft-file-input");';
print '      const errorDiv = document.getElementById("saft-file-error");';
print '      const submitBtn = document.getElementById("saft-submit-btn");';
print '      const form = document.getElementById("saft-upload-form");';
print '      ';
print '      fileInput.addEventListener("change", function() {';
print '        errorDiv.style.display = "none";';
print '        errorDiv.textContent = "";';
print '        submitBtn.disabled = false;';
print '        ';
print '        if (this.files && this.files[0]) {';
print '          const file = this.files[0];';
print '          if (file.size > MAX_UPLOAD_BYTES) {';
print '            errorDiv.textContent = "file size limit max 1mb";';
print '            errorDiv.style.display = "block";';
print '            submitBtn.disabled = true;';
print '          }';
print '        }';
print '      });';
print '      ';
print '      form.addEventListener("submit", function(e) {';
print '        if (fileInput.files && fileInput.files[0]) {';
print '          if (fileInput.files[0].size > MAX_UPLOAD_BYTES) {';
print '            e.preventDefault();';
print '            errorDiv.textContent = "file size limit max 1mb";';
print '            errorDiv.style.display = "block";';
print '          }';
print '        }';
print '      });';
print '    </script>';

if ($error) {
    print '  <div class="error">'.dol_escape_htmltag($error).'</div>';
}

print '  </div>'; // fichehalfleft
print '</div>';   // fichecenter


/* ==================================================
   RESULTADOS (PAGINAÇÃO + FATURAS)
   ================================================== */
if ($data && !empty($data['invoice_views'])) {

    // Paginação (topo)
    if (!empty($data['pages']) && (int) $data['pages'] > 1) {
        print '<div class="pagination" style="margin:10px 0;">';
        for ($i = 1; $i <= (int) $data['pages']; $i++) {
            print '<form method="POST" style="display:inline-block;margin-right:6px;">';
            print '  <input type="hidden" name="token" value="'.$csrfToken.'">';
            print '  <input type="hidden" name="action" value="validate">';
            print '  <input type="hidden" name="tokenxml" value="'.dol_escape_htmltag($tokenXml).'">';
            print '  <input type="hidden" name="page" value="'.$i.'">';
            print '  <button '.($i == $page ? 'disabled' : '').'>'.$i.'</button>';
            print '</form>';
        }
        print '</div>';
    }

    // Faturas (largura total)
//    original
    //    print '<div class="fichecenter" style="margin-top:20px";>';
//    print '  <div class="fichefull">';
print '<div style="
    max-width:820px;
    margin:20px auto;
">';

    foreach ($data['invoice_views'] as $iv) {
        include __DIR__.'/tpl/invoice_from_xml.tpl.php';
    }

//    print '  </div>';
 //   print '</div>';

    // Paginação (fundo)
    if (!empty($data['pages']) && (int) $data['pages'] > 1) {
        print '<div class="pagination" style="margin:10px 0;">';
        for ($i = 1; $i <= (int) $data['pages']; $i++) {
            print '<form method="POST" style="display:inline-block;margin-right:6px;">';
            print '  <input type="hidden" name="token" value="'.$csrfToken.'">';
            print '  <input type="hidden" name="action" value="validate">';
            print '  <input type="hidden" name="tokenxml" value="'.dol_escape_htmltag($tokenXml).'">';
            print '  <input type="hidden" name="page" value="'.$i.'">';
            print '  <button '.($i == $page ? 'disabled' : '').'>'.$i.'</button>';
            print '</form>';
        }
        print '</div>';
    }

// Debug opcional (se quiseres ligar pelo setup)
    if (!empty($clientDebug) && !empty($debug)) {
        print '<div style="margin-top:10px;">Debug:</div>';
        print '<br>';
print '<details>';
print '<summary style="cursor:pointer;font-weight:bold;">Debug técnico (clique para abrir)</summary>';
print '<pre style="
    max-height:260px;
    overflow:auto;
    white-space:pre-wrap;
    word-break:break-all;
    font-size:11px;
">'.dol_escape_htmltag($debug).'</pre>';
print '</details>';

    }
 print '  </div>';
}

llxFooter();
$db->close();