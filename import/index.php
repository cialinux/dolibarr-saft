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

// Buscar limites imediatamente ao carregar a página
if (empty($rateLimit)) {
    if (!empty($apiToken)) {
        // Modo privado: buscar dados do usuário
        $userInfo = saft_get_authenticated_user($apiUrlPreview, $apiToken, $verifyTls);
        if (!empty($userInfo['ok']) && !empty($userInfo['data'])) {
            $userData = $userInfo['data'];
            $dailyLimit = !empty($userData['daily_limit']) ? (int)$userData['daily_limit'] : 50;
            $usageToday = !empty($userData['usage_today']) ? (int)$userData['usage_today'] : 0;
            $rateLimit = array(
                'limit' => $dailyLimit,
                'used' => $usageToday,
                'remaining' => max(0, $dailyLimit - $usageToday),
            );
        }
    }
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
    print ' | <strong>Limites:</strong> Disponivel durante a importacao';
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
        $file = DOL_DATA_ROOT.'/saft/import/saft_import_'.$tokenxml.'.xml';
        $invoices = SaftParser::loadCustomerInvoices($file);
        $importer = new SaftImport($db);

        // Atualiza limites no modo privado sem consumir quota.
        if (!empty($apiToken)) {
            $userInfo = saft_get_authenticated_user($apiUrlPreview, $apiToken, $verifyTls);
            if (!empty($userInfo['ok']) && !empty($userInfo['data'])) {
                $userData = $userInfo['data'];
                $dailyLimit = !empty($userData['daily_limit']) ? (int) $userData['daily_limit'] : 50;
                $usageToday = !empty($userData['usage_today']) ? (int) $userData['usage_today'] : 0;
                $rateLimit = array(
                    'limit' => $dailyLimit,
                    'used' => $usageToday,
                    'remaining' => max(0, $dailyLimit - $usageToday),
                );
            }
        }

        $validIndexes = array();
        $duplicateIndexes = array();
        $invalidIndexes = array();
        foreach ($indexes as $i) {
            $idx = (int) $i;
            if (!isset($invoices[$idx])) {
                $invalidIndexes[] = $idx;
                continue;
            }

            $invCheck = $invoices[$idx];
            if (!empty($invCheck['hash']) && $importer->invoiceExistsByHash($invCheck['hash'])) {
                $duplicateIndexes[] = $idx;
                continue;
            }

            $validIndexes[] = $idx;
        }

        $selectedTotal = count($indexes);
        $eligibleTotal = count($validIndexes);
        $duplicateTotal = count($duplicateIndexes);
        $invalidTotal = count($invalidIndexes);
        $consumedQuota = 0;
        $importedTotal = 0;
        $skippedTotal = 0;
        $quotaStopped = false;

        if ($duplicateTotal > 0) {
            setEventMessages('Pre-checagem: '.$duplicateTotal.' fatura(s) já existem e serão ignoradas antes da importação.', null, 'warnings');
        }

        if ($invalidTotal > 0) {
            setEventMessages('Pre-checagem: '.$invalidTotal.' seleção(ões) inválida(s) foram descartadas.', null, 'warnings');
        }

        if (!empty($rateLimit) && isset($rateLimit['remaining'])) {
            $saldo = max(0, (int) $rateLimit['remaining']);
            $possivelImportar = min($eligibleTotal, $saldo);
            if ($eligibleTotal > $saldo) {
                setEventMessages('Você selecionou '.$selectedTotal.', mas seu saldo permite importar apenas '.$possivelImportar.'.', null, 'warnings');
            }
        }

        setEventMessages('Iniciando importacao de '.$selectedTotal.' fatura(s) selecionada(s).', null, 'mesgs');

        print '<div style="margin:12px 0;">';

        foreach ($validIndexes as $idx) {
            $inv = $invoices[$idx];
            $invoiceLabel = !empty($inv['number']) ? $inv['number'] : ('indice '.$idx);

            // Consome 1 unidade de quota por fatura marcada para importação.
            $quota = saft_consume_quota($apiUrlPreview, $apiToken, $verifyTls, 10);
            $rateLimit = !empty($quota['rate_limit']) ? $quota['rate_limit'] : $rateLimit;

            if (empty($quota['ok'])) {
                $quotaStopped = true;
                if (!empty($quota['auth_error'])) {
                    print '<div class="error">🔒 '.$quota['auth_error'].'</div>';
                } elseif (!empty($quota['rate_limit_error'])) {
                    print '<div class="error">❌ '.$quota['rate_limit_error'].'</div>';
                } else {
                    $statusText = !empty($quota['status']) ? ('HTTP '.((int) $quota['status'])) : 'sem codigo HTTP';
                    $errorText = !empty($quota['error']) ? $quota['error'] : ('Falha ao consumir quota ('.$statusText.').');
                    print '<div class="error">❌ '.$errorText.'</div>';
                }

                if (!empty($rateLimit)) {
                    print '<div class="warning">📊 Usado: '.$rateLimit['used'].'/'.$rateLimit['limit'].' | Restante: '.$rateLimit['remaining'].'</div>';
                }

                print '<div class="warning">Importacao interrompida ao atingir o limite de quota.</div>';
                break;
            }

            $consumedQuota++;

            $db->begin();

            $socid = $importer->findOrCreateThirdpartyFromSaft($inv, $user);
            if ($socid <= 0) {
                $db->rollback();
                $skippedTotal++;
                print '<div class="error">Erro ao obter cliente para a fatura '.$invoiceLabel.'.</div>';
                continue;
            }

            $id = $importer->createCustomerInvoiceDraftFromSaft($socid, $inv, $user);
            if ($id <= 0) {
                $db->rollback();
                $skippedTotal++;
                print '<div class="error">Erro ao importar fatura '.$invoiceLabel.': '.$importer->error.'</div>';
                continue;
            }

            $db->commit();
            $importedTotal++;
            print '<div class="ok">Fatura '.$invoiceLabel.' criada (ID '.$id.')</div>';
        }

        print '</div>';

        if (!empty($rateLimit)) {
            print '<div style="margin:12px 0; padding:8px; background:#f0f0f0; border-radius:4px;">';
            print '<strong>📊 Limites apos importacao:</strong> '.$rateLimit['used'].'/'.$rateLimit['limit'].' consultas/dia';
            print ' | <strong>Restantes:</strong> '.$rateLimit['remaining'];
            print '</div>';
        }

        $skippedTotal += $duplicateTotal + $invalidTotal;
        $summaryMsg = 'Resumo: selecionadas '.$selectedTotal.', elegiveis '.$eligibleTotal.', quota consumida '.$consumedQuota.', importadas '.$importedTotal.', ignoradas '.$skippedTotal.' (duplicadas '.$duplicateTotal.', invalidas '.$invalidTotal.').';
        if ($quotaStopped) {
            $summaryMsg .= ' Processo interrompido por limite de quota.';
        }
        setEventMessages($summaryMsg, null, $quotaStopped ? 'warnings' : 'mesgs');
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