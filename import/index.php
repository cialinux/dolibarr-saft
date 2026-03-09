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
            $dailyLimit = !empty($userData['daily_limit']) ? (int)$userData['daily_limit'] : 15;
            $usageToday = !empty($userData['usage_month']) ? (int)$userData['usage_month'] : (!empty($userData['usage_today']) ? (int)$userData['usage_today'] : 0);
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
    $periodLabel = ($apiMode === 'private') ? 'consultas/mes' : 'consultas/dia';
    print ' | <strong>Limites:</strong> '.$rateLimit['used'].'/'.$rateLimit['limit'].' '.$periodLabel;
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
    $importerPreview = new SaftImport($db);

    // Mapa de duplicidade no preview: já existente na base e duplicada no próprio XML.
    $previewDuplicateStatus = array();
    $seenXmlHashes = array();
    foreach ($invoices as $k => $invTmp) {
        $hashTmp = trim((string)($invTmp['hash'] ?? ''));
        $statusTmp = '';

        if ($hashTmp !== '' && $importerPreview->invoiceExistsByHash($hashTmp)) {
            $statusTmp = 'duplicada na base';
        } elseif ($hashTmp !== '' && isset($seenXmlHashes[$hashTmp])) {
            $statusTmp = 'duplicada no XML';
        } elseif ($hashTmp !== '') {
            $seenXmlHashes[$hashTmp] = $k;
        }

        $previewDuplicateStatus[$k] = $statusTmp;
    }

    $previewTotal = count($invoices);
    $previewDupBase = 0;
    $previewDupXml = 0;
    foreach ($previewDuplicateStatus as $st) {
        if ($st === 'duplicada na base') $previewDupBase++;
        elseif ($st === 'duplicada no XML') $previewDupXml++;
    }
    $previewEligible = $previewTotal - $previewDupBase - $previewDupXml;

    print load_fiche_titre('Fase 2: Pré-visualização');

    print '<div style="margin:10px 0; padding:8px; background:#f0f0f0; border-radius:4px;">';
    print '<strong>Contadores:</strong> ';
    print 'Total: '.$previewTotal;
    print ' | Elegiveis: '.$previewEligible;
    print ' | Duplicadas na base: '.$previewDupBase;
    print ' | Duplicadas no XML: '.$previewDupXml;
    print '</div>';

    print '<form method="POST">';
    print '<input type="hidden" name="action" value="import">';
    print '<input type="hidden" name="tokenxml" value="'.$tokenxml.'">';
    print '<input type="hidden" name="token" value="'.newToken().'">';

    print '<table class="noborder centpercent">';
    print '<tr class="liste_titre"><th><input type="checkbox" id="saft-select-all"></th><th>Nº</th><th>Data</th><th>Cliente</th><th>Total</th><th>Status</th></tr>';

    foreach ($invoices as $k => $inv) {
        $dupStatus = !empty($previewDuplicateStatus[$k]) ? $previewDuplicateStatus[$k] : '';
        $canSelect = ($dupStatus === '');

        print '<tr>';
        if ($canSelect) {
            print '<td><input type="checkbox" class="saft-select-item" name="selected[]" value="'.$k.'"></td>';
        } else {
            print '<td><input type="checkbox" disabled></td>';
        }
        print '<td>'.dol_escape_htmltag($inv['number']).'</td>';
        print '<td>'.$inv['date'].'</td>';
        print '<td>'.dol_escape_htmltag($inv['customer_name']).'</td>';
        print '<td class="right">'.price($inv['total']).'</td>';
        print '<td>'.($canSelect ? 'ok' : dol_escape_htmltag($dupStatus)).'</td>';
        print '</tr>';
    }

    print '</table><br>';
    print '<input type="submit" class="button button-save" value="Importar">';
    print '</form>';

    print '<script>';
    print '  (function() {';
    print '    const master = document.getElementById("saft-select-all");';
    print '    const items = Array.prototype.slice.call(document.querySelectorAll(".saft-select-item"));';
    print '    if (!master || items.length === 0) return;';
    print '    master.addEventListener("change", function() {';
    print '      items.forEach(function(cb) { cb.checked = master.checked; });';
    print '    });';
    print '    items.forEach(function(cb) {';
    print '      cb.addEventListener("change", function() {';
    print '        const allChecked = items.every(function(x) { return x.checked; });';
    print '        master.checked = allChecked;';
    print '      });';
    print '    });';
    print '  })();';
    print '</script>';
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
        $seenSelectedHashes = array();
        foreach ($indexes as $i) {
            $idx = (int) $i;
            if (!isset($invoices[$idx])) {
                $invalidIndexes[] = $idx;
                continue;
            }

            $invCheck = $invoices[$idx];
            $hashCheck = trim((string)($invCheck['hash'] ?? ''));

            if ($hashCheck !== '' && isset($seenSelectedHashes[$hashCheck])) {
                $duplicateIndexes[] = $idx;
                continue;
            }

            if ($hashCheck !== '' && $importer->invoiceExistsByHash($hashCheck)) {
                $duplicateIndexes[] = $idx;
                continue;
            }

            if ($hashCheck !== '') {
                $seenSelectedHashes[$hashCheck] = $idx;
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
            $invoiceHash = trim((string)($inv['hash'] ?? ''));
            $customerAuditMap = array(
                'nome' => trim((string)($inv['customer_name'] ?? '')),
                'nif' => trim((string)($inv['customer_taxid'] ?? '')),
                'vat' => trim((string)($inv['customer_vat'] ?? '')),
                'endereco' => trim((string)($inv['customer_address'] ?? '')),
                'cidade' => trim((string)($inv['customer_city'] ?? '')),
                'codigo_postal' => trim((string)($inv['customer_zip'] ?? '')),
                'estado_regiao' => trim((string)($inv['customer_state'] ?? '')),
                'pais' => trim((string)($inv['customer_country'] ?? '')),
                'telefone' => trim((string)($inv['customer_phone'] ?? '')),
                'telemovel' => trim((string)($inv['customer_mobile'] ?? '')),
                'fax' => trim((string)($inv['customer_fax'] ?? '')),
                'email' => trim((string)($inv['customer_email'] ?? '')),
                'website' => trim((string)($inv['customer_website'] ?? '')),
                'contacto' => trim((string)($inv['customer_contact'] ?? '')),
            );

            // Revalidação defensiva para bloquear duplicados imediatamente.
            if ($invoiceHash !== '' && $importer->invoiceExistsByHash($invoiceHash)) {
                $skippedTotal++;
                print '<div class="warning">Duplicada detectada antes da importacao: '.$invoiceLabel.' (hash '.$invoiceHash.').</div>';
                continue;
            }

            // Consome 1 unidade de quota por fatura marcada para importação.
            $quota = saft_consume_quota($apiUrlPreview, $apiToken, $verifyTls, 10);
            $rateLimit = !empty($quota['rate_limit']) ? $quota['rate_limit'] : $rateLimit;

            if (empty($quota['ok'])) {
                $quotaStopped = true;
                if (empty($apiToken)) {
                    print '<div class="error">🔒 Registre-se gratuitamente para conseguir importar faturas. API publica nao importa faturas.</div>';
                } elseif (!empty($quota['auth_error'])) {
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

            $customerStatus = 'unknown';
            $socid = $importer->findOrCreateThirdpartyFromSaft($inv, $user, $customerStatus);
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

            $auditPairs = array();
            $auditPairs[] = 'cliente_status='.$customerStatus;
            foreach ($customerAuditMap as $fieldName => $fieldValue) {
                if ($fieldValue !== '') {
                    $auditPairs[] = $fieldName.'='.$fieldValue;
                }
            }

            if (!empty($auditPairs)) {
                print '<div style="margin:2px 0 10px 20px; color:#555;">';
                print '<strong>Auditoria cliente XML:</strong> '.dol_escape_htmltag(implode(' | ', $auditPairs));
                print '</div>';
            } else {
                print '<div style="margin:2px 0 10px 20px; color:#777;">';
                print '<strong>Auditoria cliente XML:</strong> nenhum campo de cliente preenchido no XML.';
                print '</div>';
            }
        }

        print '</div>';

        if (!empty($rateLimit)) {
            $periodLabel = ($apiMode === 'private') ? 'consultas/mes' : 'consultas/dia';
            print '<div style="margin:12px 0; padding:8px; background:#f0f0f0; border-radius:4px;">';
            print '<strong>📊 Limites apos importacao:</strong> '.$rateLimit['used'].'/'.$rateLimit['limit'].' '.$periodLabel;
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