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
require_once __DIR__.'/lib/saft.lib.php';

$langs->loadLangs(array('main', 'bills', 'companies', 'saft@saft'));

$action = GETPOST('action', 'aZ09');
$factureId = GETPOSTINT('facture_id');
$apiRuntime = saft_get_runtime_api_config();
$apiUrlPreview = $apiRuntime['api_url'];
$apiToken = getDolGlobalString('SAFT_API_TOKEN', '');
$verifyTls = (bool) $apiRuntime['verify_tls'];
$clientDebug = (bool) getDolGlobalInt('SAFT_CLIENT_DEBUG', 0);
$capabilities = array();
$capabilityError = '';

function saft_digits9($value)
{
    $digits = preg_replace('/\D+/', '', (string) $value);
    if (strlen($digits) > 9) $digits = substr($digits, -9);
    return $digits;
}

function saft_facture_to_issue_payload($fact, $user)
{
    $thirdparty = $fact->thirdparty;
    $customerNif = saft_digits9(!empty($thirdparty->tva_intra) ? $thirdparty->tva_intra : '');
    if ($customerNif === '' && !empty($thirdparty->idprof1)) $customerNif = saft_digits9($thirdparty->idprof1);

    $issueDate = !empty($fact->date) ? dol_print_date($fact->date, '%Y-%m-%d') : dol_print_date(dol_now(), '%Y-%m-%d');
    $dueDate = !empty($fact->date_lim_reglement) ? dol_print_date($fact->date_lim_reglement, '%Y-%m-%d') : $issueDate;
    $invoiceType = 'FT';
    if (defined('Facture::TYPE_CREDIT_NOTE') && (int) $fact->type === Facture::TYPE_CREDIT_NOTE) $invoiceType = 'NC';

    $lines = array();
    foreach ((array) $fact->lines as $line) {
        $vat = isset($line->tva_tx) ? (float) $line->tva_tx : 0;
        $lines[] = array(
            'item_type' => (!empty($line->product_type) && (int) $line->product_type === 0) ? 'P' : 'S',
            'catalog_item_id' => !empty($line->fk_product) ? (int) $line->fk_product : null,
            'description' => trim((string) (!empty($line->desc) ? $line->desc : (!empty($line->label) ? $line->label : 'Linha Dolibarr'))),
            'quantity' => isset($line->qty) && (float) $line->qty > 0 ? (float) $line->qty : 1,
            'unit_price' => isset($line->subprice) ? (float) $line->subprice : 0,
            'tax_rate' => $vat,
            'tax_reason_code' => '',
        );
    }

    return array(
        'external' => array(
            'source' => 'dolibarr',
            'document_id' => (string) $fact->id,
            'document_ref' => (string) $fact->ref,
            'idempotency_key' => 'dolibarr:entity:'.$GLOBALS['conf']->entity.':facture:'.$fact->id,
        ),
        'invoice' => array(
            'invoice_type' => $invoiceType,
            'issue_date' => $issueDate,
            'due_date' => $dueDate,
            'purchase_order_number' => !empty($fact->ref_client) ? (string) $fact->ref_client : '',
            'invoice_notes' => 'Pedido de emissão originado no Dolibarr por '.$user->login,
            'customer_nif' => $customerNif,
            'customer_name' => !empty($thirdparty->name) ? (string) $thirdparty->name : 'Cliente Dolibarr',
            'customer_email' => !empty($thirdparty->email) ? (string) $thirdparty->email : '',
            'customer_address' => !empty($thirdparty->address) ? (string) $thirdparty->address : 'N/D',
            'customer_postal_code' => !empty($thirdparty->zip) ? (string) $thirdparty->zip : '0000-000',
            'customer_city' => !empty($thirdparty->town) ? (string) $thirdparty->town : 'N/D',
            'customer_country' => !empty($thirdparty->country_code) ? (string) $thirdparty->country_code : 'PT',
            'lines' => $lines,
        ),
    );
}

function saft_recent_customer_invoices($db, $limit = 10)
{
    global $conf;

    $rows = array();
    $limit = max(1, min(50, (int) $limit));
    $sql = "SELECT f.rowid, f.ref, f.datef, f.date_lim_reglement, f.total_ht, f.total_ttc, f.fk_statut, s.nom as customer_name";
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
print '<p class="opacitymedium">O Dolibarr envia o pedido. Numeração, hash, ATCUD, PDF e SAF-T são controlados pelo SAF-T Validator.</p>';

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
    } else {
        $fact->fetch_lines();
        $payload = saft_facture_to_issue_payload($fact, $user);
        if ($payload['invoice']['invoice_type'] !== 'FT') {
            setEventMessages('Nesta primeira fase, a emissão via Dolibarr suporta apenas faturas standard (FT).', null, 'errors');
        } elseif (empty($payload['invoice']['customer_nif'])) {
            setEventMessages('Cliente sem NIF válido. Corrija o terceiro no Dolibarr antes de emitir.', null, 'errors');
        } elseif (empty($payload['invoice']['lines'])) {
            setEventMessages('Fatura sem linhas para emitir.', null, 'errors');
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
                $note = trim((string) $fact->note_private);
                if ($note !== '') $note .= "\n\n";
                $note .= "SAF-T Validator emission\n";
                $note .= "External invoice ID: ".(int) $invoice['id']."\n";
                $note .= "Invoice number: ".(string) $invoice['invoice_number']."\n";
                $note .= "Series: ".(string) $invoice['invoice_series']."\n";
                $note .= "Hash: ".(string) $invoice['integrity_hash']."\n";
                $note .= "Idempotency-Key: ".$payload['external']['idempotency_key']."\n";
                $sql = "UPDATE ".MAIN_DB_PREFIX."facture SET note_private = '".$db->escape($note)."' WHERE rowid = ".((int) $fact->id);
                $db->query($sql);
                setEventMessages((!empty($issue['data']['idempotent']) ? 'Fatura já emitida anteriormente: ' : 'Fatura emitida com sucesso: ').$invoice['invoice_number'], null, 'mesgs');
            }
        }
    }
} elseif ($action === 'issue' && empty($capabilities['can_issue_invoices'])) {
    setEventMessages('Emissão bloqueada: o SAF-T Validator ainda não autorizou este token para emissão fiscal via Dolibarr.', null, 'errors');
}

print '<form method="POST" style="margin-top:16px;">';
print '<input type="hidden" name="token" value="'.newToken().'">';
print '<input type="hidden" name="action" value="issue">';
print '<label for="facture_id">ID da fatura Dolibarr</label><br>';
print '<input type="number" min="1" name="facture_id" id="facture_id" value="'.((int) $factureId).'" required style="max-width:160px;"> ';
print '<input type="submit" class="button button-save" value="Emitir no SAF-T Validator"'.(empty($capabilities['can_issue_invoices']) ? ' disabled' : '').'>';
print '</form>';
print '<p class="opacitymedium" style="margin-top:12px;">Repetir a chamada para a mesma fatura devolve a mesma fatura oficial por idempotência.</p>';

$recentInvoices = saft_recent_customer_invoices($db, 12);
if (!empty($recentInvoices)) {
    print '<br><table class="noborder centpercent">';
    print '<tr class="liste_titre"><td>ID</td><td>Ref.</td><td>Cliente</td><td class="center">Data</td><td class="right">Total</td><td class="center">Estado</td><td></td></tr>';
    foreach ($recentInvoices as $row) {
        $statusLabel = ((int) $row->fk_statut === 0) ? 'Rascunho' : (((int) $row->fk_statut === 1) ? 'Pendente' : 'Fechada');
        $issueUrl = dol_buildpath('/custom/saft/issue.php?mainmenu=saft&leftmenu=saft_issue&facture_id='.(int) $row->rowid, 1);
        $cardUrl = DOL_URL_ROOT.'/compta/facture/card.php?id='.(int) $row->rowid;
        print '<tr class="oddeven">';
        print '<td>'.(int) $row->rowid.'</td>';
        print '<td><a href="'.$cardUrl.'">'.dol_escape_htmltag($row->ref).'</a></td>';
        print '<td>'.dol_escape_htmltag($row->customer_name).'</td>';
        print '<td class="center">'.dol_print_date($db->jdate($row->datef), 'day').'</td>';
        print '<td class="right">'.price($row->total_ttc).'</td>';
        print '<td class="center">'.dol_escape_htmltag($statusLabel).'</td>';
        print '<td class="right"><a class="button button-small" href="'.$issueUrl.'">Selecionar</a></td>';
        print '</tr>';
    }
    print '</table>';
}

print '</div></div>';

llxFooter();
$db->close();
