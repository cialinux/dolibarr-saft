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
        $res = saft_call_preview_api(
            saftTmpFile($tokenXml),
            $page,
            $perPage,
            [
                'api_url'    => $apiUrlPreview,
                'verify_tls' => $verifyTls,
                'timeout'    => 60,
            ]
        );

        if (empty($res['data'])) {
            $error = 'Erro ao validar SAF-T.';
        } else {
            $data = $res['data'];
        }
        $debug = json_encode($res, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
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

print '      <form method="POST" enctype="multipart/form-data">';
print '        <input type="hidden" name="token" value="'.$csrfToken.'">';
print '        <input type="hidden" name="action" value="validate">';
print '        <input type="hidden" name="page" value="1">'; // novo upload volta para a página 1
print '        <input type="file" name="file" accept=".xml" required> ';
print '        <input type="submit" class="button" value="Validar">';
print '      </form>';

if (!empty($tokenXml)) {
    print '  <div class="opacitymedium">Sessão: '.dol_escape_htmltag($tokenXml).'</div>';
}

print '    </div>';

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