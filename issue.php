<?php
/* Copyright (C) 2026  Cia Linux  <general@cialinux.com>
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 */

$res = 0;
if (!$res && !empty($_SERVER["CONTEXT_DOCUMENT_ROOT"])) {
    $res = @include $_SERVER["CONTEXT_DOCUMENT_ROOT"]."/main.inc.php";
}
if (!$res && file_exists("../main.inc.php")) $res = @include "../main.inc.php";
if (!$res && file_exists("../../main.inc.php")) $res = @include "../../main.inc.php";
if (!$res) die("Include of main fails");

require_once DOL_DOCUMENT_ROOT.'/compta/facture/class/facture.class.php';
require_once DOL_DOCUMENT_ROOT.'/core/lib/files.lib.php';
require_once __DIR__.'/lib/saft.lib.php';

saft_require_internal_user();
saft_require_right('facture', 'creer');

$langs->loadLangs(array('main', 'bills', 'companies', 'saft@saft'));

$action = GETPOST('action', 'aZ09');
saft_require_valid_post_token($action);
$factureId = GETPOSTINT('facture_id');
$apiRuntime = saft_get_runtime_api_config();
$apiUrlPreview = $apiRuntime['api_url'];
$apiToken = getDolGlobalString('SAFT_API_TOKEN', '');
$verifyTls = (bool) $apiRuntime['verify_tls'];
$clientDebug = (bool) getDolGlobalInt('SAFT_CLIENT_DEBUG', 0);
$capabilities = array();
$capabilityError = '';
$taxReasonCodes = saft_get_tax_reason_codes_from_request();

function saft_tax_reason_options()
{
    return array(
        'M01' => 'M01-Isenção art. 53.º do CIVA',
        'M07' => 'M07-Isenção art. 9.º do CIVA',
        'M40' => 'M40-IVA autoliquidação',
        'M99' => 'M99-Outro motivo fiscal',
    );
}

function saft_digits9($value)
{
    $digits = preg_replace('/\D+/', '', (string) $value);
    if (strlen($digits) > 9) $digits = substr($digits, -9);
    return $digits;
}

function saft_clean_tax_reason_code($value)
{
    return preg_replace('/[^A-Z0-9]/', '', strtoupper(trim((string) $value)));
}

function saft_get_tax_reason_codes_from_request()
{
    $raw = array();
    if (!empty($_POST['tax_reason_code']) && is_array($_POST['tax_reason_code'])) {
        $raw = $_POST['tax_reason_code'];
    } elseif (!empty($_GET['tax_reason_code']) && is_array($_GET['tax_reason_code'])) {
        $raw = $_GET['tax_reason_code'];
    }

    $codes = array();
    foreach ($raw as $lineId => $code) {
        $lineId = (int) $lineId;
        $code = saft_clean_tax_reason_code($code);
        if ($lineId > 0 && $code !== '') {
            $codes[$lineId] = $code;
        }
    }

    return $codes;
}

function saft_facture_line_id($line, $fallback)
{
    if (!empty($line->id)) return (int) $line->id;
    if (!empty($line->rowid)) return (int) $line->rowid;
    return (int) $fallback;
}

function saft_invoice_idempotency_key($invoiceId)
{
    return 'dolibarr:entity:'.$GLOBALS['conf']->entity.':facture:'.((int) $invoiceId);
}

function saft_invoice_is_emitted_from_note($invoiceId, $note)
{
    return strpos((string) $note, 'Idempotency-Key: '.saft_invoice_idempotency_key($invoiceId)) !== false;
}

function saft_facture_to_issue_payload($fact, $user, $taxReasonCodes = array())
{
    $thirdparty = $fact->thirdparty;
    $customerNif = saft_digits9(!empty($thirdparty->tva_intra) ? $thirdparty->tva_intra : '');
    if ($customerNif === '' && !empty($thirdparty->idprof1)) $customerNif = saft_digits9($thirdparty->idprof1);

    $issueDate = !empty($fact->date) ? dol_print_date($fact->date, '%Y-%m-%d') : dol_print_date(dol_now(), '%Y-%m-%d');
    $dueDate = !empty($fact->date_lim_reglement) ? dol_print_date($fact->date_lim_reglement, '%Y-%m-%d') : $issueDate;
    $invoiceType = 'FT';
    if (defined('Facture::TYPE_CREDIT_NOTE') && (int) $fact->type === Facture::TYPE_CREDIT_NOTE) $invoiceType = 'NC';

    $lines = array();
    $taxReasonOptions = saft_tax_reason_options();
    foreach ((array) $fact->lines as $idx => $line) {
        $vat = isset($line->tva_tx) ? (float) $line->tva_tx : 0;
        $lineId = saft_facture_line_id($line, $idx + 1);
        $taxReasonCode = !empty($taxReasonCodes[$lineId]) ? saft_clean_tax_reason_code($taxReasonCodes[$lineId]) : '';
        $lines[] = array(
            'external_line_id' => (string) $lineId,
            'item_type' => (!empty($line->product_type) && (int) $line->product_type === 0) ? 'P' : 'S',
            'catalog_item_id' => !empty($line->fk_product) ? (int) $line->fk_product : null,
            'description' => trim((string) (!empty($line->desc) ? $line->desc : (!empty($line->label) ? $line->label : 'Linha Dolibarr'))),
            'quantity' => isset($line->qty) && (float) $line->qty > 0 ? (float) $line->qty : 1,
            'unit_price' => isset($line->subprice) ? (float) $line->subprice : 0,
            'tax_rate' => $vat,
            'tax_reason_code' => ($vat == 0.0 && isset($taxReasonOptions[$taxReasonCode])) ? $taxReasonCode : '',
            'tax_reason_label' => ($vat == 0.0 && isset($taxReasonOptions[$taxReasonCode])) ? $taxReasonOptions[$taxReasonCode] : '',
        );
    }

    return array(
        'external' => array(
            'source' => 'dolibarr',
            'document_id' => (string) $fact->id,
            'document_ref' => (string) $fact->ref,
            'idempotency_key' => saft_invoice_idempotency_key($fact->id),
        ),
        'invoice' => array(
            'invoice_type' => $invoiceType,
            'issue_date' => $issueDate,
            'due_date' => $dueDate,
            'purchase_order_number' => !empty($fact->ref_client) ? (string) $fact->ref_client : '',
            'invoice_notes' => 'Pedido de emissão originado no Dolibarr por '.((int) $user->id),
            'customer_nif' => $customerNif,
            'customer_name' => !empty($thirdparty->name) ? (string) $thirdparty->name : 'Cliente Dolibarr',
            'customer_email' => !empty($thirdparty->email) ? (string) $thirdparty->email : null,
            'customer_address' => !empty($thirdparty->address) ? (string) $thirdparty->address : 'N/D',
            'customer_postal_code' => !empty($thirdparty->zip) ? (string) $thirdparty->zip : '0000-000',
            'customer_city' => !empty($thirdparty->town) ? (string) $thirdparty->town : 'N/D',
            'customer_country' => !empty($thirdparty->country_code) ? (string) $thirdparty->country_code : 'PT',
            'lines' => $lines,
        ),
    );
}

function saft_invalid_tax_reason_codes($taxReasonCodes)
{
    $options = saft_tax_reason_options();
    $invalid = array();
    foreach ((array) $taxReasonCodes as $lineId => $code) {
        $code = saft_clean_tax_reason_code($code);
        if ($code !== '' && empty($options[$code])) {
            $invalid[] = '#'.((int) $lineId).' '.$code;
        }
    }
    return $invalid;
}

function saft_payload_missing_zero_vat_tax_reasons($payload)
{
    $missing = array();
    foreach ((array) $payload['invoice']['lines'] as $line) {
        if (isset($line['tax_rate']) && (float) $line['tax_rate'] == 0.0 && empty($line['tax_reason_code'])) {
            $missing[] = '#'.(!empty($line['external_line_id']) ? $line['external_line_id'] : '?').' '.(!empty($line['description']) ? $line['description'] : 'Linha sem descrição');
        }
    }
    return $missing;
}

function saft_store_official_invoice_pdf($fact, $invoice, $apiUrlPreview, $apiToken, $verifyTls)
{
    $invoiceId = (int) (!empty($invoice['id']) ? $invoice['id'] : 0);
    if ($invoiceId <= 0) {
        return array('ok' => false, 'error' => 'ID da fatura oficial indisponível.');
    }

    $pdf = saft_call_faturamento_invoice_pdf($apiUrlPreview, $apiToken, $verifyTls, $invoiceId, 60);
    if (empty($pdf['pdf_bytes'])) {
        return array('ok' => false, 'error' => !empty($pdf['error']) ? $pdf['error'] : 'Falha ao obter PDF oficial.');
    }

    $ref = dol_sanitizeFileName((string) $fact->ref);
    if ($ref === '') {
        $ref = 'facture_'.$fact->id;
    }
    $dir = DOL_DATA_ROOT.'/facture/'.$ref;
    if (dol_mkdir($dir) < 0) {
        return array('ok' => false, 'error' => 'Falha ao criar diretório de documentos da fatura.');
    }

    $officialNumber = dol_sanitizeFileName((string) (!empty($invoice['invoice_number']) ? $invoice['invoice_number'] : ('saft_'.$invoiceId)));
    $filePath = $dir.'/'.$officialNumber.'_SAFT-Validator.pdf';
    $written = @file_put_contents($filePath, $pdf['pdf_bytes']);
    if ($written === false || $written <= 0) {
        return array('ok' => false, 'error' => 'Falha ao guardar PDF oficial nos documentos da fatura.');
    }

    return array('ok' => true, 'path' => $filePath);
}

function saft_recent_customer_invoices($db, $limit = 10)
{
    global $conf;

    $rows = array();
    $limit = max(1, min(50, (int) $limit));
    $sql = "SELECT f.rowid, f.ref, f.datef, f.date_lim_reglement, f.total_ht, f.total_ttc, f.fk_statut, f.note_private, s.nom as customer_name";
    $sql .= " FROM ".MAIN_DB_PREFIX."facture as f";
    $sql .= " LEFT JOIN ".MAIN_DB_PREFIX."societe as s ON s.rowid = f.fk_soc";
    $sql .= " WHERE f.entity IN (".getEntity('facture').")";
    $sql .= " ORDER BY f.rowid DESC";
    $sql .= " LIMIT ".$limit;

    $resql = $db->query($sql);
    if (!$resql) {
        return $rows;
    }

    while ($obj = $db->fetch_object($resql)) {
        $rows[] = $obj;
    }
    return $rows;
}

llxHeader('', 'Emitir fatura SAF-T via API');
print load_fiche_titre('Emitir fatura fiscal via SAF-T Validator');
print '<div class="fichecenter"><div class="card" style="padding:16px;">';

if (empty($apiToken)) {
    print '<div class="warning">Configure um SAFT API Token válido no setup do módulo.</div>';
} else {
    $cap = saft_call_faturamento_capabilities($apiUrlPreview, $apiToken, $verifyTls, 15);
    $capabilities = !empty($cap['data']['capabilities']) && is_array($cap['data']['capabilities']) ? $cap['data']['capabilities'] : array();
    if (empty($capabilities)) {
        $capabilityError = !empty($cap['error']) ? (string) $cap['error'] : 'Endpoint de faturamento via Dolibarr indisponível ou sem resposta válida.';
        if (!empty($cap['status'])) {
            $capabilityError .= ' HTTP '.$cap['status'].'.';
        }
    }
    print '<div style="margin:12px 0; padding:8px; background:#f0f0f0; border-radius:4px;">';
    print '<strong>Ambiente:</strong> '.dol_escape_htmltag($apiRuntime['label']);
    print ' | <strong>Faturamento:</strong> '.(!empty($capabilities['can_issue_invoices']) ? 'autorizado' : 'não autorizado');
    if (!empty($capabilities['quota'])) {
        print ' | <strong>Requests:</strong> '.(int) $capabilities['quota']['used'].'/'.(int) $capabilities['quota']['limit'];
        print ' | <strong>Restantes:</strong> '.(int) $capabilities['quota']['remaining'];
    }
    print '</div>';
    if (empty($capabilities['can_issue_invoices']) && !empty($capabilities['messages'])) {
        print '<div class="warning"><ul>';
        foreach ($capabilities['messages'] as $message) print '<li>'.dol_escape_htmltag($message).'</li>';
        print '</ul></div>';
    }
    if ($capabilityError !== '') {
        print '<div class="warning">'.dol_escape_htmltag($capabilityError).'</div>';
    }
}

if ($action === 'issue' && !empty($apiToken) && !empty($capabilities['can_issue_invoices'])) {
    $fact = new Facture($db);
    if ($factureId <= 0 || $fact->fetch($factureId) <= 0) {
        setEventMessages('Fatura Dolibarr não encontrada.', null, 'errors');
    } elseif ($fact->fetch_thirdparty() <= 0) {
        setEventMessages('Cliente da fatura Dolibarr não encontrado.', null, 'errors');
    } elseif (saft_invoice_is_emitted_from_note($fact->id, $fact->note_private)) {
        setEventMessages('Esta fatura Dolibarr já foi emitida no SAF-T Validator. Escolha outro ID de fatura.', null, 'errors');
    } else {
        $fact->fetch_lines();
        $payload = saft_facture_to_issue_payload($fact, $user, $taxReasonCodes);
        $invalidTaxReasons = saft_invalid_tax_reason_codes($taxReasonCodes);
        $missingTaxReasons = saft_payload_missing_zero_vat_tax_reasons($payload);
        if ($payload['invoice']['invoice_type'] !== 'FT') {
            setEventMessages('Nesta primeira fase, a emissão via Dolibarr suporta apenas faturas standard (FT).', null, 'errors');
        } elseif (empty($payload['invoice']['customer_nif'])) {
            setEventMessages('Cliente sem NIF válido. Corrija o terceiro no Dolibarr antes de emitir.', null, 'errors');
        } elseif (empty($payload['invoice']['lines'])) {
            setEventMessages('Fatura sem linhas para emitir.', null, 'errors');
        } elseif (!empty($invalidTaxReasons)) {
            setEventMessages('Motivo CIVA inválido nas linhas: '.implode(', ', $invalidTaxReasons), null, 'errors');
        } elseif (!empty($missingTaxReasons)) {
            setEventMessages('Selecione o motivo CIVA para cada linha sem IVA antes de emitir: '.implode(', ', $missingTaxReasons), null, 'errors');
        } else {
            $issue = saft_call_faturamento_issue_invoice($apiUrlPreview, $apiToken, $verifyTls, $payload, 70);
            if (empty($issue['data']['success']) || empty($issue['data']['invoice'])) {
                $msg = !empty($issue['error']) ? $issue['error'] : 'Falha ao emitir fatura no SAF-T Validator.';
                setEventMessages($msg, null, 'errors');
                if ($clientDebug) {
                    print '<details open><summary>Debug API</summary><pre>'.dol_escape_htmltag(json_encode($issue, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)).'</pre></details>';
                }
            } else {
                $invoice = $issue['data']['invoice'];
                $pdfStore = saft_store_official_invoice_pdf($fact, $invoice, $apiUrlPreview, $apiToken, $verifyTls);
                $note = trim((string) $fact->note_private);
                $idempotencyLine = "Idempotency-Key: ".$payload['external']['idempotency_key'];
                if (strpos($note, $idempotencyLine) === false) {
                    if ($note !== '') $note .= "\n\n";
                    $note .= "SAF-T Validator emission\n";
                    $note .= "External invoice ID: ".(int) $invoice['id']."\n";
                    $note .= "Invoice number: ".(string) $invoice['invoice_number']."\n";
                    $note .= "Series: ".(string) $invoice['invoice_series']."\n";
                    $note .= "Hash: ".(string) $invoice['integrity_hash']."\n";
                    $note .= $idempotencyLine."\n";
                    if (!empty($pdfStore['ok']) && !empty($pdfStore['path'])) {
                        $note .= "Official PDF: ".basename($pdfStore['path'])."\n";
                    }
                    $sql = "UPDATE ".MAIN_DB_PREFIX."facture SET note_private = '".$db->escape($note)."' WHERE rowid = ".((int) $fact->id);
                    $db->query($sql);
                }
                setEventMessages((!empty($issue['data']['idempotent']) ? 'Fatura já emitida anteriormente: ' : 'Fatura emitida com sucesso: ').$invoice['invoice_number'], null, 'mesgs');
                if (empty($pdfStore['ok'])) {
                    setEventMessages('A fatura foi emitida, mas o PDF oficial não foi guardado no Dolibarr: '.(!empty($pdfStore['error']) ? $pdfStore['error'] : 'erro desconhecido'), null, 'warnings');
                }
            }
        }
    }
} elseif ($action === 'issue' && empty($capabilities['can_issue_invoices'])) {
    setEventMessages('Emissão bloqueada: o SAF-T Validator ainda não autorizou este token para emissão fiscal via Dolibarr.', null, 'errors');
}

$formFact = null;
$formFactAlreadyEmitted = false;
if ($factureId > 0) {
    $formFact = new Facture($db);
    if ($formFact->fetch($factureId) > 0) {
        $formFactAlreadyEmitted = saft_invoice_is_emitted_from_note($formFact->id, $formFact->note_private);
        $formFact->fetch_thirdparty();
        if (!$formFactAlreadyEmitted) {
            $formFact->fetch_lines();
        }
    } else {
        $formFact = null;
    }
}

print '<form method="POST" style="margin-top:16px;">';
print '<input type="hidden" name="token" value="'.newToken().'">';
print '<input type="hidden" name="action" value="issue">';
print '<label for="facture_id">ID da fatura Dolibarr</label><br>';
print '<input type="number" min="1" name="facture_id" id="facture_id" value="'.((int) $factureId).'" required style="max-width:160px;"> ';

if ($formFactAlreadyEmitted) {
    print '<div class="warning" style="margin-top:12px;">Esta fatura Dolibarr já foi emitida no SAF-T Validator. Escolha outro ID de fatura.</div>';
} elseif ($formFact) {
    print '<br><br><table class="noborder centpercent">';
    print '<tr class="liste_titre"><td>Linha</td><td>Descrição</td><td class="right">IVA</td><td>Motivo CIVA</td></tr>';
    foreach ((array) $formFact->lines as $idx => $line) {
        $lineId = saft_facture_line_id($line, $idx + 1);
        $vat = isset($line->tva_tx) ? (float) $line->tva_tx : 0;
        $description = trim((string) (!empty($line->desc) ? $line->desc : (!empty($line->label) ? $line->label : 'Linha Dolibarr')));
        $selectedCode = !empty($taxReasonCodes[$lineId]) ? saft_clean_tax_reason_code($taxReasonCodes[$lineId]) : '';
        print '<tr class="oddeven">';
        print '<td class="nowrap">#'.((int) $lineId).'</td>';
        print '<td>'.dol_escape_htmltag($description).'</td>';
        print '<td class="right">'.price($vat).'%</td>';
        print '<td>';
        if ($vat == 0.0) {
            print '<select name="tax_reason_code['.((int) $lineId).']" class="flat minwidth300">';
            print '<option value="">-- selecionar motivo desta linha --</option>';
            foreach (saft_tax_reason_options() as $code => $label) {
                print '<option value="'.dol_escape_htmltag($code).'"'.($selectedCode === $code ? ' selected' : '').'>'.dol_escape_htmltag($label).'</option>';
            }
            print '</select>';
        } else {
            print '<span class="opacitymedium">Não aplicável</span>';
        }
        print '</td>';
        print '</tr>';
    }
    print '</table>';
}
print '<br>';
print '<input type="submit" class="button button-save" value="Emitir no SAF-T Validator"'.((empty($capabilities['can_issue_invoices']) || $formFactAlreadyEmitted) ? ' disabled' : '').'>';
print '</form>';
print '<p class="opacitymedium" style="margin-top:12px;">Email do cliente é opcional para emissão. O motivo CIVA é definido individualmente em cada linha com IVA 0%. Repetir a chamada para a mesma fatura devolve a mesma fatura oficial por idempotência.</p>';

$recentInvoices = saft_recent_customer_invoices($db, 12);
if (!empty($recentInvoices)) {
    print '<br><table class="noborder centpercent">';
    print '<tr class="liste_titre"><td>ID</td><td>Ref.</td><td>Cliente</td><td class="center">Data</td><td class="right">Total</td><td class="center">Estado</td><td class="center">Emitida</td><td></td></tr>';
    foreach ($recentInvoices as $row) {
        $statusLabel = ((int) $row->fk_statut === 0) ? 'Rascunho' : (((int) $row->fk_statut === 1) ? 'Pendente' : 'Fechada');
        $isEmitted = saft_invoice_is_emitted_from_note($row->rowid, $row->note_private);
        $issueUrl = dol_buildpath('/custom/saft/issue.php?mainmenu=saft&leftmenu=saft_issue&facture_id='.(int) $row->rowid, 1);
        $cardUrl = DOL_URL_ROOT.'/compta/facture/card.php?id='.(int) $row->rowid;
        print '<tr class="oddeven"'.($isEmitted ? ' style="background:#f5f5f5; color:#777;"' : '').'>';
        print '<td>'.(int) $row->rowid.'</td>';
        print '<td><a href="'.$cardUrl.'">'.dol_escape_htmltag($row->ref).'</a></td>';
        print '<td>'.dol_escape_htmltag($row->customer_name).'</td>';
        print '<td class="center">'.dol_print_date($db->jdate($row->datef), 'day').'</td>';
        print '<td class="right">'.price($row->total_ttc).'</td>';
        print '<td class="center">'.dol_escape_htmltag($statusLabel).'</td>';
        print '<td class="center">'.($isEmitted ? '<span class="badge badge-status4">Sim</span>' : '<span class="badge badge-status0">Não</span>').'</td>';
        if ($isEmitted) {
            print '<td class="right"><span class="button button-small disabled opacitymedium">Emitida</span></td>';
        } else {
            print '<td class="right"><a class="button button-small" href="'.$issueUrl.'">Selecionar</a></td>';
        }
        print '</tr>';
    }
    print '</table>';
}

print '</div></div>';

llxFooter();
$db->close();
