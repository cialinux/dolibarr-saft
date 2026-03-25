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
if (!$res && file_exists("../../main.inc.php")) $res = @include "../../main.inc.php";
if (!$res && file_exists("../../../main.inc.php")) $res = @include "../../../main.inc.php";
if (!$res) die("Include of main fails");

require_once DOL_DOCUMENT_ROOT.'/core/class/html.form.class.php';
require_once DOL_DOCUMENT_ROOT.'/core/lib/date.lib.php';
require_once __DIR__.'/../lib/saft.lib.php';
require_once __DIR__.'/../class/SaftImport.class.php';
require_once DOL_DOCUMENT_ROOT.'/societe/class/societe.class.php';
require_once DOL_DOCUMENT_ROOT.'/compta/facture/class/facture.class.php';

$langs->loadLangs(array('main', 'bills', 'companies', 'saft@saft'));
$form = new Form($db);

$action = GETPOST('action', 'aZ09');
$sessionId = GETPOST('session_id', 'alphanohtml');

$apiRuntime = saft_get_runtime_api_config();
$apiUrlPreview = $apiRuntime['api_url'];
$apiToken = getDolGlobalString('SAFT_API_TOKEN', '');
$verifyTls = (bool) $apiRuntime['verify_tls'];
$perPage = max(1, (int) getDolGlobalInt('SAFT_PER_PAGE', 10));
$apiMode = !empty($apiToken) ? 'private' : 'public';

$rateLimit = null;
if (!empty($apiToken)) {
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

llxHeader('', 'Importar SAF-T');
print load_fiche_titre('Importar faturas (SAF-T)');

print '<div class="fichecenter"><div class="card" style="padding:16px;">';
print '<div style="margin:12px 0; padding:8px; background:#f0f0f0; border-radius:4px;">';
print '<strong>Modo:</strong> '.($apiMode === 'private' ? '🔒 Privado' : '🔓 Público');
print ' | <strong>Ambiente:</strong> '.dol_escape_htmltag($apiRuntime['label']);
if ($rateLimit !== null && isset($rateLimit['limit'])) {
    print ' | <strong>Limites:</strong> '.$rateLimit['used'].'/'.$rateLimit['limit'].' consultas/mes';
    print ' | <strong>Restantes:</strong> '.$rateLimit['remaining'];
} else {
    print ' | <strong>Limites:</strong> Disponivel durante a importacao';
}
print ' | <strong>file size limit:</strong> max 1mb';
print '</div><br>';

if ($action === 'upload') {
    if (empty($_FILES['file']['tmp_name'])) {
        setEventMessages('Nenhum ficheiro enviado.', null, 'errors');
    } elseif (!empty($_FILES['file']['size']) && (int) $_FILES['file']['size'] > 1 * 1024 * 1024) {
        setEventMessages('file size limit max 1mb', null, 'errors');
    } else {
        $create = saft_call_sessions_create(
            $_FILES['file']['tmp_name'],
            array(
                'api_url' => $apiUrlPreview,
                'api_token' => $apiToken,
                'verify_tls' => $verifyTls,
                'timeout' => 60,
                'purpose' => 'import',
                'user_nif' => !empty($apiToken) ? (string) $user->login : '',
            )
        );

        if (!empty($create['data']) && !empty($create['data']['ok']) && !empty($create['data']['session_id'])) {
            $sessionId = (string) $create['data']['session_id'];
            $action = 'preview';
            setEventMessages('Sessão de importação iniciada com sucesso.', null, 'mesgs');
        } else {
            $msg = !empty($create['error']) ? $create['error'] : 'Falha ao iniciar sessão de importação.';
            setEventMessages($msg, null, 'errors');
        }
    }
}

if ($action === 'import') {
    if ($sessionId === '') {
        setEventMessages('Sessão inválida. Reenvie o XML.', null, 'errors');
    } elseif (empty($apiToken)) {
        setEventMessages('Registre-se gratuitamente para conseguir importar faturas. API publica nao importa faturas.', null, 'errors');
    } else {
        $indexes = GETPOST('selected', 'array');
        if (empty($indexes) || !is_array($indexes)) {
            setEventMessages('Nenhuma fatura selecionada.', null, 'warnings');
        } else {
            $previewForImport = saft_call_sessions_get(
                $sessionId,
                1,
                100,
                array(
                    'api_url' => $apiUrlPreview,
                    'api_token' => $apiToken,
                    'verify_tls' => $verifyTls,
                    'timeout' => 40,
                )
            );

            if (empty($previewForImport['data']) || empty($previewForImport['data']['ok'])) {
                $msg = !empty($previewForImport['error']) ? $previewForImport['error'] : 'Falha ao carregar sessão para importação.';
                setEventMessages($msg, null, 'errors');
                $action = 'preview';
            } else {
                $rowsImport = !empty($previewForImport['data']['invoices']) && is_array($previewForImport['data']['invoices'])
                    ? $previewForImport['data']['invoices']
                    : array();

                $importer = new SaftImport($db);
                $created = array('customers' => 0, 'invoices' => 0, 'lines' => 0);
                $failed = array();
                $importedSuccess = array();
                $seenXmlKeys = array();

                foreach ($indexes as $idxRaw) {
                    $idx = (int) $idxRaw;
                    if (!isset($rowsImport[$idx]) || !is_array($rowsImport[$idx])) {
                        $failed[] = array('invoice_no' => 'UNKNOWN', 'error' => 'Índice inválido na seleção.');
                        continue;
                    }

                    $inv = $rowsImport[$idx];
                    $invoiceNo = !empty($inv['invoice']['invoice_no']) ? (string) $inv['invoice']['invoice_no'] : 'UNKNOWN';

                    if (!empty($inv['duplicated'])) {
                        $failed[] = array(
                            'invoice_no' => $invoiceNo,
                            'error' => !empty($inv['duplicate_reason']) ? (string) $inv['duplicate_reason'] : 'Duplicada ou hash inválido/incompatível.',
                        );
                        continue;
                    }

                    $hash = !empty($inv['hash']) ? trim((string) $inv['hash']) : '';
                    if ($hash === '' && !empty($inv['invoice']['hash'])) {
                        $hash = trim((string) $inv['invoice']['hash']);
                    }

                    $customerNif = !empty($inv['customer']['nif']) ? preg_replace('/\s+/', '', (string) $inv['customer']['nif']) : '';
                    $xmlKey = $hash !== '' ? ('H:'.$hash) : ('N:'.$customerNif.'|I:'.$invoiceNo);
                    if (isset($seenXmlKeys[$xmlKey])) {
                        $failed[] = array('invoice_no' => $invoiceNo, 'error' => 'Duplicada no XML (repetida no ficheiro).');
                        continue;
                    }
                    $seenXmlKeys[$xmlKey] = true;

                    if ($hash !== '' && $importer->invoiceExistsByHash($hash)) {
                        $failed[] = array('invoice_no' => $invoiceNo, 'error' => 'Duplicada no ERP (hash já importado).');
                        continue;
                    }

                    $quotaImport = saft_consume_quota($apiUrlPreview, $apiToken, $verifyTls, 10);
                    if (empty($quotaImport['ok'])) {
                        $qerr = !empty($quotaImport['rate_limit_error'])
                            ? $quotaImport['rate_limit_error']
                            : (!empty($quotaImport['auth_error']) ? $quotaImport['auth_error'] : 'Falha ao contabilizar quota da importação.');
                        $failed[] = array('invoice_no' => $invoiceNo, 'error' => $qerr);
                        continue;
                    }

                    $customerCountry = !empty($inv['customer']['country']) ? strtoupper(trim((string) $inv['customer']['country'])) : '';
                    $customerVat = $customerNif;
                    if ($customerCountry !== '' && $customerNif !== '') {
                        $customerVat = $customerCountry.$customerNif;
                    }

                    $lines = array();
                    if (!empty($inv['lines']) && is_array($inv['lines'])) {
                        foreach ($inv['lines'] as $ln) {
                            if (!is_array($ln)) {
                                continue;
                            }
                            $qty = isset($ln['qty']) ? (float) $ln['qty'] : 1;
                            if ($qty <= 0) {
                                $qty = 1;
                            }
                            $lines[] = array(
                                'desc' => !empty($ln['description']) ? (string) $ln['description'] : (!empty($ln['product_desc']) ? (string) $ln['product_desc'] : 'Linha SAF-T'),
                                'qty' => $qty,
                                'unit_price_ht' => isset($ln['unit_price']) ? (float) $ln['unit_price'] : 0,
                                'vat_rate' => isset($ln['tax_pct']) ? (float) $ln['tax_pct'] : 0,
                            );
                        }
                    }

                    $legacyInv = array(
                        'number' => $invoiceNo,
                        'date' => !empty($inv['invoice']['invoice_date']) ? (string) $inv['invoice']['invoice_date'] : '',
                        'total' => isset($inv['totals']['gross']) ? (float) $inv['totals']['gross'] : 0,
                        'customer_name' => !empty($inv['customer']['company_name']) ? (string) $inv['customer']['company_name'] : 'Cliente SAF-T',
                        'customer_taxid' => $customerNif,
                        'customer_country' => $customerCountry,
                        'customer_vat' => $customerVat,
                        'customer_address' => !empty($inv['customer']['addr_detail']) ? (string) $inv['customer']['addr_detail'] : '',
                        'customer_city' => !empty($inv['customer']['city']) ? (string) $inv['customer']['city'] : '',
                        'customer_zip' => !empty($inv['customer']['postal']) ? (string) $inv['customer']['postal'] : '',
                        'customer_state' => '',
                        'customer_phone' => '',
                        'customer_mobile' => '',
                        'customer_fax' => '',
                        'customer_email' => !empty($inv['customer']['email']) ? (string) $inv['customer']['email'] : '',
                        'customer_website' => '',
                        'customer_contact' => !empty($inv['customer']['contact']) ? (string) $inv['customer']['contact'] : '',
                        'tax_exemption_reason' => '',
                        'tax_exemption_code' => '',
                        'hash' => $hash,
                        'hash_control' => !empty($inv['invoice']['hash_control']) ? (string) $inv['invoice']['hash_control'] : '',
                        'source_id' => !empty($inv['invoice']['source_id']) ? (string) $inv['invoice']['source_id'] : '',
                        'system_entry_date' => !empty($inv['invoice']['system_entry_date']) ? (string) $inv['invoice']['system_entry_date'] : '',
                        'lines' => $lines,
                    );

                    $customerStatus = 'unknown';
                    $socid = $importer->findOrCreateThirdpartyFromSaft($legacyInv, $user, $customerStatus);
                    if ($socid <= 0) {
                        $failed[] = array(
                            'invoice_no' => $invoiceNo,
                            'error' => !empty($importer->error) ? $importer->error : 'Falha ao encontrar/criar cliente.',
                        );
                        continue;
                    }

                    $factureId = $importer->createCustomerInvoiceDraftFromSaft($socid, $legacyInv, $user);
                    if ($factureId <= 0) {
                        $failed[] = array(
                            'invoice_no' => $invoiceNo,
                            'error' => !empty($importer->error) ? $importer->error : 'Falha ao criar fatura no ERP.',
                        );
                        continue;
                    }

                    if ($customerStatus === 'novo') {
                        $created['customers']++;
                    }
                    $created['invoices']++;
                    $created['lines'] += !empty($legacyInv['lines']) ? count($legacyInv['lines']) : 1;
                    $importedSuccess[] = array(
                        'invoice_no' => $invoiceNo,
                        'customer_name' => !empty($inv['customer']['company_name']) ? (string) $inv['customer']['company_name'] : 'Cliente SAF-T',
                        'customer_status' => $customerStatus,
                        'hash_status' => !empty($inv['hash_status']) ? (string) $inv['hash_status'] : 'valid',
                        'invoice_status' => !empty($inv['invoice_status']) ? (string) $inv['invoice_status'] : 'fatura valida',
                    );
                }

                if ($created['invoices'] > 0 && empty($failed)) {
                    print '<div class="ok">Importação concluída com sucesso.</div>';
                } elseif ($created['invoices'] > 0) {
                    print '<div class="warning">Importação concluída parcialmente.</div>';
                } else {
                    print '<div class="error">Nenhuma fatura foi importada.</div>';
                }

                print '<div style="margin:10px 0; padding:8px; background:#f0f0f0; border-radius:4px;">';
                print '<strong>Resumo:</strong> ';
                print 'Clientes criados: '.(int) $created['customers'];
                print ' | Faturas criadas: '.(int) $created['invoices'];
                print ' | Linhas criadas: '.(int) $created['lines'];
                print ' | Falhas: '.count($failed);
                print '</div>';

                if (!empty($importedSuccess)) {
                    print '<div style="margin:10px 0; padding:10px 12px; background:#d4edda; color:#1b5e20; border:1px solid #b8dfc5; border-radius:4px;">';
                    print '<strong>Faturas validadas e importadas com sucesso:</strong>';
                    print '<ul style="margin:8px 0 0 18px;">';
                    foreach ($importedSuccess as $row) {
                        $invoiceNo = !empty($row['invoice_no']) ? $row['invoice_no'] : 'UNKNOWN';
                        $customerName = !empty($row['customer_name']) ? $row['customer_name'] : 'Cliente SAF-T';
                        $customerStatusText = (!empty($row['customer_status']) && $row['customer_status'] === 'novo') ? 'cliente criado' : 'cliente associado';
                        $invoiceStatusText = !empty($row['invoice_status']) ? $row['invoice_status'] : 'fatura valida';
                        print '<li><strong>'.dol_escape_htmltag($invoiceNo).'</strong> — '.dol_escape_htmltag($invoiceStatusText).' e importada com sucesso para '.dol_escape_htmltag($customerName).' ('.dol_escape_htmltag($customerStatusText).').</li>';
                    }
                    print '</ul>';
                    print '</div>';
                }

                if (!empty($failed)) {
                    print '<div class="warning">Algumas faturas não foram importadas:</div>';
                    print '<ul>';
                    foreach ($failed as $f) {
                        $invNo = !empty($f['invoice_no']) ? $f['invoice_no'] : 'UNKNOWN';
                        $err = !empty($f['error']) ? $f['error'] : 'Erro desconhecido';
                        print '<li>'.dol_escape_htmltag($invNo).' - '.dol_escape_htmltag($err).'</li>';
                    }
                    print '</ul>';
                }

                saft_call_sessions_delete(
                    $sessionId,
                    array(
                        'api_url' => $apiUrlPreview,
                        'api_token' => $apiToken,
                        'verify_tls' => $verifyTls,
                        'timeout' => 10,
                    )
                );
                $sessionId = '';
                $action = '';
            }
        }
    }
}

if (($action === 'preview' || $sessionId !== '') && $sessionId !== '') {
    $preview = saft_call_sessions_get(
        $sessionId,
        1,
        100,
        array(
            'api_url' => $apiUrlPreview,
            'api_token' => $apiToken,
            'verify_tls' => $verifyTls,
            'timeout' => 40,
        )
    );

    if (empty($preview['data']) || empty($preview['data']['ok'])) {
        $msg = !empty($preview['error']) ? $preview['error'] : 'Falha ao carregar sessão de importação.';
        setEventMessages($msg, null, 'errors');
    } else {
        $rows = !empty($preview['data']['invoices']) && is_array($preview['data']['invoices']) ? $preview['data']['invoices'] : array();
        $importerPreview = new SaftImport($db);

        // XML-internal duplicate detection is handled by the API (row['duplicated'] flag).
        // Only ERP dedup (hash already imported into Dolibarr) is checked locally.
        foreach ($rows as &$row) {
            $hash = !empty($row['hash']) ? trim((string) $row['hash']) : '';
            if ($hash === '' && !empty($row['invoice']['hash'])) {
                $hash = trim((string) $row['invoice']['hash']);
            }

            if ($hash !== '' && $importerPreview->invoiceExistsByHash($hash)) {
                $row['duplicated'] = true;
                if (!empty($row['duplicate_reason'])) {
                    $row['duplicate_reason'] .= ' + ERP (hash já importado)';
                } else {
                    $row['duplicate_reason'] = 'duplicada no ERP (hash já importado)';
                }
            }
        }
        unset($row);

        $dedupTotal = count($rows);
        $dedupDuplicates = 0;
        foreach ($rows as $it) {
            if (!empty($it['duplicated'])) {
                $dedupDuplicates++;
            }
        }
        $dedupNew = max(0, $dedupTotal - $dedupDuplicates);

        print load_fiche_titre('Fase 2: Pré-visualização');
        print '<div style="margin:10px 0; padding:8px; background:#f0f0f0; border-radius:4px;">';
        print '<strong>Contadores:</strong> ';
        print 'Total: '.(int) $dedupTotal;
        print ' | Elegíveis: '.(int) $dedupNew;
        print ' | Duplicadas: '.(int) $dedupDuplicates;
        print '</div>';

        print '<form method="POST">';
        print '<input type="hidden" name="action" value="import">';
        print '<input type="hidden" name="session_id" value="'.dol_escape_htmltag($sessionId).'">';
        print '<input type="hidden" name="token" value="'.newToken().'">';

        print '<table class="noborder centpercent">';
        print '<tr class="liste_titre"><th><input type="checkbox" id="saft-select-all"></th><th>Nº</th><th>Data</th><th>Cliente</th><th>Total</th><th>Status</th></tr>';

        foreach ($rows as $k => $inv) {
            $invoiceNo = !empty($inv['invoice']['invoice_no']) ? $inv['invoice']['invoice_no'] : 'N/D';
            $invoiceDate = !empty($inv['invoice']['invoice_date']) ? $inv['invoice']['invoice_date'] : 'N/D';
            $customerName = !empty($inv['customer']['company_name']) ? $inv['customer']['company_name'] : 'N/D';
            $totalGross = !empty($inv['totals']['gross']) ? $inv['totals']['gross'] : 0;
            $isDup = !empty($inv['duplicated']);
            $dupReason = !empty($inv['duplicate_reason']) ? (string) $inv['duplicate_reason'] : ($isDup ? 'duplicada' : 'ok');

            // Hash status badge
            $hashStatus = !empty($inv['hash_status']) ? (string) $inv['hash_status'] : (!empty($inv['hash_valid']) ? 'valid' : 'invalid');
            $hashBadges = [
                'valid'                => ['bg' => '#d4edda', 'color' => '#1a7a1a', 'icon' => '✓', 'label' => 'Hash válido'],
                'hash_missing'         => ['bg' => '#f8d7da', 'color' => '#721c24', 'icon' => '✗', 'label' => 'Hash em falta'],
                'atcud_missing'        => ['bg' => '#f8d7da', 'color' => '#721c24', 'icon' => '✗', 'label' => 'ATCUD em falta'],
                'hash_control_invalid' => ['bg' => '#fff3cd', 'color' => '#856404', 'icon' => '⚠', 'label' => 'HashControl inválido'],
                'hash_too_short'       => ['bg' => '#fff3cd', 'color' => '#856404', 'icon' => '⚠', 'label' => 'Hash inválido'],
                'hash_format_invalid'  => ['bg' => '#f8d7da', 'color' => '#721c24', 'icon' => '✗', 'label' => 'Hash inválido'],
                'hash_chain_prev_missing' => ['bg' => '#fff3cd', 'color' => '#856404', 'icon' => '⚠', 'label' => 'Cadeia hash inválida'],
                'hash_chain_order_invalid'=> ['bg' => '#f8d7da', 'color' => '#721c24', 'icon' => '✗', 'label' => 'Ordem de série inválida'],
                'hash_chain_gap_suspect'  => ['bg' => '#fff3cd', 'color' => '#856404', 'icon' => '⚠', 'label' => 'Sequência inválida'],
                'hash_chain_duplicate_seq'=> ['bg' => '#f8d7da', 'color' => '#721c24', 'icon' => '✗', 'label' => 'Numeração repetida'],
                'date_invalid'         => ['bg' => '#f8d7da', 'color' => '#721c24', 'icon' => '✗', 'label' => 'Data inválida'],
                'invalid'              => ['bg' => '#f8d7da', 'color' => '#721c24', 'icon' => '✗', 'label' => 'Hash inválido'],
            ];
            $hb = isset($hashBadges[$hashStatus]) ? $hashBadges[$hashStatus] : $hashBadges['invalid'];
            $hashBadgeHtml = '<span style="display:inline-block;padding:2px 8px;background:'.dol_escape_htmltag($hb['bg']).';color:'.dol_escape_htmltag($hb['color']).';border-radius:10px;font-size:11px;font-weight:bold;">'.dol_escape_htmltag($hb['icon']).' '.dol_escape_htmltag($hb['label']).'</span>';

            // Status cell: hash badge + dedup reason if applicable
            $statusHtml = $hashBadgeHtml;
            if ($isDup && $dupReason !== 'ok') {
                $statusHtml .= '<br><span style="font-size:11px;color:#555;">'.dol_escape_htmltag($dupReason).'</span>';
            }

            print '<tr>';
            if (!$isDup) {
                print '<td><input type="checkbox" class="saft-select-item" name="selected[]" value="'.$k.'"></td>';
            } else {
                print '<td><input type="checkbox" disabled></td>';
            }
            print '<td>'.dol_escape_htmltag($invoiceNo).'</td>';
            print '<td>'.dol_escape_htmltag($invoiceDate).'</td>';
            print '<td>'.dol_escape_htmltag($customerName).'</td>';
            print '<td class="right">'.price((float) $totalGross).'</td>';
            print '<td>'.$statusHtml.'</td>';
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
}

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
print '  fileInput.addEventListener("change", function() {';
print '    errorDiv.style.display = "none";';
print '    errorDiv.textContent = "";';
print '    submitBtn.disabled = false;';
print '    if (this.files && this.files[0] && this.files[0].size > MAX_UPLOAD_BYTES) {';
print '      errorDiv.textContent = "file size limit max 1mb";';
print '      errorDiv.style.display = "block";';
print '      submitBtn.disabled = true;';
print '    }';
print '  });';
print '  form.addEventListener("submit", function(e) {';
print '    if (fileInput.files && fileInput.files[0] && fileInput.files[0].size > MAX_UPLOAD_BYTES) {';
print '      e.preventDefault();';
print '      errorDiv.textContent = "file size limit max 1mb";';
print '      errorDiv.style.display = "block";';
print '    }';
print '  });';
print '</script>';

print '</div></div>';

llxFooter();
$db->close();
