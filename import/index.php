<?php
$res = 0;
if (!$res && !empty($_SERVER["CONTEXT_DOCUMENT_ROOT"])) {
    $res = @include $_SERVER["CONTEXT_DOCUMENT_ROOT"]."/main.inc.php";
}
if (!$res && file_exists("../../main.inc.php")) $res = @include "../../main.inc.php";
if (!$res && file_exists("../../../main.inc.php")) $res = @include "../../../main.inc.php";
if (!$res) die("Include of main fails");

require_once DOL_DOCUMENT_ROOT.'/core/class/html.form.class.php';
require_once DOL_DOCUMENT_ROOT.'/core/lib/date.lib.php';
require_once DOL_DOCUMENT_ROOT.'/societe/class/societe.class.php';
require_once DOL_DOCUMENT_ROOT.'/compta/facture/class/facture.class.php';

require_once __DIR__.'/../class/SaftParser.class.php';
require_once __DIR__.'/../class/SaftImport.class.php';
require_once __DIR__.'/../lib/saft.lib.php';

$langs->loadLangs(['main', 'bills', 'companies', 'saft@saft']);
$form = new Form($db);

$action   = GETPOST('action', 'aZ09');
$tokenxml = GETPOST('tokenxml', 'alpha');

$apiUrlPreview = getDolGlobalString('SAFT_API_URL', '');
$apiToken      = getDolGlobalString('SAFT_API_TOKEN', '');
$verifyTls     = (bool) getDolGlobalInt('SAFT_VERIFY_TLS', 0);
$perPage       = max(1, (int) getDolGlobalInt('SAFT_PER_PAGE', 10));

$rateLimit = null;  // Sempre obtido da resposta da API
$apiMode = 'public';  // 'public' ou 'private'
if (!empty($apiToken)) {
    $apiMode = 'private';
}

llxHeader('', 'Importar SAF-T');
print load_fiche_titre('Importar faturas (SAF-T)');

print '<div class="fichecenter"><div class="card" style="padding:16px;">';

// Mostrar modo e limites
print '<div style="margin:12px 0; padding:8px; background:#f0f0f0; border-radius:4px;">';
print '<strong>Modo:</strong> '.($apiMode === 'private' ? '🔒 Privado' : '🔓 Público');
if ($rateLimit !== null && isset($rateLimit['limit'])) {
    print ' | <strong>Limites:</strong> '.$rateLimit['used'].'/'.$rateLimit['limit'].' consultas/dia';
} else {
    print ' | <strong>Limites:</strong> Aguardando resposta da API';
}
print ' | <strong>file size limit:</strong> max 1mb';
print '</div>';
print '<br>';

/* ============================================================
 * FASE 1 – UPLOAD
 * ============================================================ */
if ($action === 'upload') {
    if (!empty($_FILES['file']['tmp_name'])) {
        $fileSize = $_FILES['file']['size'];
        if ($fileSize > 1 * 1024 * 1024) {
            setEventMessages('file size limit max 1mb', null, 'errors');
        } else {
            $dir = DOL_DATA_ROOT.'/saft/import';
            dol_mkdir($dir);

            $tokenxml = dol_print_date(dol_now(), '%Y%m%d%H%M%S').'-'.random_int(1000,9999);
            $dest = $dir.'/saft_import_'.$tokenxml.'.xml';

            if (move_uploaded_file($_FILES['file']['tmp_name'], $dest)) {
                setEventMessages('Ficheiro recebido com sucesso.', null, 'mesgs');
                $action = 'preview';
            } else {
                setEventMessages('Erro ao guardar ficheiro.', null, 'errors');
            }
        }
    }
}

/* ============================================================
 * FASE 2 – PREVIEW
 * ============================================================ */
if ($action === 'preview' && $tokenxml) {
    $file = DOL_DATA_ROOT.'/saft/import/saft_import_'.$tokenxml.'.xml';
    $invoices = SaftParser::loadCustomerInvoices($file);

    print load_fiche_titre('Fase 2: Pré-visualização');

    print '<form method="POST">';
    print '<input type="hidden" name="action" value="import">';
    print '<input type="hidden" name="tokenxml" value="'.$tokenxml.'">';
    print '<input type="hidden" name="token" value="'.newToken().'">';

    print '<table class="noborder centpercent">';
    print '<tr class="liste_titre"><th></th><th>Nº</th><th>Data</th><th>Cliente</th><th>Total</th></tr>';

    foreach ($invoices as $k => $inv) {
        print '<tr>';
        print '<td><input type="checkbox" name="selected[]" value="'.$k.'"></td>';
        print '<td>'.dol_escape_htmltag($inv['number']).'</td>';
        print '<td>'.$inv['date'].'</td>';
        print '<td>'.dol_escape_htmltag($inv['customer_name']).'</td>';
        print '<td class="right">'.price($inv['total']).'</td>';
        print '</tr>';
    }

    print '</table><br>';
    print '<input type="submit" class="button button-save" value="Importar">';
    print '</form>';
}

/* ============================================================
 * FASE 3 – IMPORT (COM RATE LIMITING)
 * ============================================================ */
if ($action === 'import') {
    print load_fiche_titre('Fase 3: Resultado da importação');

    $indexes = GETPOST('selected', 'array');

    if (empty($indexes)) {
        print '<div class="warning">Nenhuma fatura selecionada.</div>';
    } else {
        // 🔒 VERIFICAR RATE LIMIT ANTES DE IMPORTAR
        $consumeUrl = $apiUrlPreview;
        // Remover o path "/api/public/validate/preview" e adicionar "/api/public/consume-quota"
        $apiBaseUrl = preg_replace('#/api/public/validate/preview.*$#', '', $consumeUrl);
        $consumeQuotaUrl = $apiBaseUrl . '/api/public/consume-quota';
        
        $ch = curl_init();
        curl_setopt_array($ch, array(
            CURLOPT_URL => $consumeQuotaUrl,
            CURLOPT_POST => true,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 10,
            CURLOPT_HTTPHEADER => array('Content-Type: application/json'),
        ));
        
        if (!$verifyTls) {
            curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
            curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 0);
        }
        
        $response = curl_exec($ch);
        $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $headersRaw = (string) curl_getinfo($ch, CURLINFO_HEADER_SIZE);
        curl_close($ch);
        
        // Capturar headers de rate limit da resposta
        $rateLimit = null;
        if ($response) {
            preg_match('/X-RateLimit-Limit:\s*(\d+)/i', substr($response, 0, 1000), $m1);
            preg_match('/X-RateLimit-Used:\s*(\d+)/i', substr($response, 0, 1000), $m2);
            preg_match('/X-RateLimit-Remaining:\s*(\d+)/i', substr($response, 0, 1000), $m3);
            
            if (!empty($m1[1])) {
                $rateLimit = array(
                    'limit' => (int)$m1[1],
                    'used' => !empty($m2[1]) ? (int)$m2[1] : 0,
                    'remaining' => !empty($m3[1]) ? (int)$m3[1] : 0,
                );
            }
        }
        
        // Se quota foi excedida, bloquetar importação
        if ($httpCode === 429) {
            setEventMessages('❌ Limite de consultas excedido! Aguarde 24h ou use API privada.', null, 'errors');
            if ($rateLimit) {
                setEventMessages('📊 Usado: '.$rateLimit['used'].'/'.$rateLimit['limit'].' | Restante: '.$rateLimit['remaining'], null, 'warnings');
            }
        } elseif ($httpCode !== 200) {
            setEventMessages('⚠️ Erro ao verificar quota: HTTP '.$httpCode, null, 'warnings');
        } else {
            // Sucesso: quota consumida, prosseguir com importação
            setEventMessages('✅ Quota consumida. Iniciando importação de '.count($indexes).' fatura(s)...', null, 'mesgs');
        }
        
        // Mostrar status de rate limit
        if ($rateLimit) {
            print '<div style="margin:12px 0; padding:8px; background:#f0f0f0; border-radius:4px;">';
            print '<strong>📊 Limites:</strong> '.$rateLimit['used'].'/'.$rateLimit['limit'].' consultas/dia';
            print ' | <strong>Restantes:</strong> '.$rateLimit['remaining'];
            print '</div>';
        }
        
        // Se não conseguiu consumir quota, não prosseguir
        if ($httpCode !== 200) {
            print '<div style="margin:12px 0; padding:8px; background:#ffcccc; border-radius:4px;">';
            print '❌ Importação bloqueada: não foi possível consumir quota.';
            print '</div>';
        } else {
            // PROSSEGUIR COM A IMPORTAÇÃO
            print '<div style="margin:12px 0;">';
            
            $file = DOL_DATA_ROOT.'/saft/import/saft_import_'.$tokenxml.'.xml';
            $invoices = SaftParser::loadCustomerInvoices($file);

            $importer = new SaftImport($db);

            foreach ($indexes as $i) {
                $inv = $invoices[(int)$i];

                $db->begin();

            $socid = $importer->findOrCreateThirdpartyFromSaft($inv, $user);
            if ($socid <= 0) {
                $db->rollback();
                print '<div class="error">Erro cliente</div>';
                continue;
            }

            if (!empty($inv['hash']) && $importer->invoiceExistsByHash($inv['hash'])) {
                $db->rollback();
                print '<div class="warning">Duplicado (hash '.$inv['hash'].')</div>';
                continue;
            }

            $id = $importer->createCustomerInvoiceDraftFromSaft($socid, $inv, $user);
            if ($id <= 0) {
                $db->rollback();
                print '<div class="error">'.$importer->error.'</div>';
                continue;
            }

            $db->commit();
            print '<div class="ok">Fatura '.$inv['number'].' criada (ID '.$id.')</div>';
        }
            print '</div>';  // Fechar div "margin:12px 0;"
        }  // Fechar else (if $httpCode === 200)
    }  // Fechar else (if empty($indexes))
}  // Fechar if ($action === 'import')

/* ============================================================
 * FORM UPLOAD
 * ============================================================ */
print '<hr>';
print '<form method="POST" enctype="multipart/form-data" id="saft-import-form">';
print '<input type="hidden" name="action" value="upload">';
print '<input type="hidden" name="token" value="'.newToken().'">';
print '<div style="margin:8px 0;"><label><input type="file" name="file" accept=".xml" required id="saft-import-input"> Seleccionar XML</label></div>';
print '<div id="saft-import-error" style="color:red; display:none; margin:8px 0;"></div>';
print '<input type="submit" class="button" value="Enviar XML" id="saft-import-submit">';
print '</form>';

print '<script>';
print '  const MAX_UPLOAD_BYTES = 1 * 1024 * 1024;';
print '  const fileInput = document.getElementById("saft-import-input");';
print '  const errorDiv = document.getElementById("saft-import-error");';
print '  const submitBtn = document.getElementById("saft-import-submit");';
print '  const form = document.getElementById("saft-import-form");';
print '  ';
print '  fileInput.addEventListener("change", function() {';
print '    errorDiv.style.display = "none";';
print '    errorDiv.textContent = "";';
print '    submitBtn.disabled = false;';
print '    ';
print '    if (this.files && this.files[0]) {';
print '      const file = this.files[0];';
print '      if (file.size > MAX_UPLOAD_BYTES) {';
print '        errorDiv.textContent = "file size limit max 1mb";';
print '        errorDiv.style.display = "block";';
print '        submitBtn.disabled = true;';
print '      }';
print '    }';
print '  });';
print '  ';
print '  form.addEventListener("submit", function(e) {';
print '    if (fileInput.files && fileInput.files[0]) {';
print '      if (fileInput.files[0].size > MAX_UPLOAD_BYTES) {';
print '        e.preventDefault();';
print '        errorDiv.textContent = "file size limit max 1mb";';
print '        errorDiv.style.display = "block";';
print '      }';
print '    }';
print '  });';
print '</script>';

print '</div></div>';

llxFooter();
$db->close();