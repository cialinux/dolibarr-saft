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

require_once __DIR__.'/lib/saft.lib.php';

$langs->loadLangs(array('main', 'saft@saft'));

$action = GETPOST('action', 'aZ09');
$apiRuntime = saft_get_runtime_api_config();
$apiUrlPreview = $apiRuntime['api_url'];
$apiToken = getDolGlobalString('SAFT_API_TOKEN', '');
$verifyTls = (bool) $apiRuntime['verify_tls'];
$clientDebug = (bool) getDolGlobalInt('SAFT_CLIENT_DEBUG', 0);
$now = dol_now();
$year = GETPOSTINT('year') ?: (int) dol_print_date($now, '%Y');
$month = GETPOSTINT('month') ?: (int) dol_print_date($now, '%m');
$exportId = GETPOSTINT('export_id');

if ($action === 'download' && $exportId > 0 && !empty($apiToken)) {
    $download = saft_call_faturamento_saft_export_download($apiUrlPreview, $apiToken, $verifyTls, $exportId, 90);
    if (!empty($download['xml_bytes'])) {
        $filename = !empty($download['filename']) ? basename((string) $download['filename']) : ('SAFT_'.$exportId.'.xml');
        header('Content-Type: application/xml; charset=utf-8');
        header('Content-Disposition: attachment; filename="'.$filename.'"');
        header('Content-Length: '.strlen($download['xml_bytes']));
        print $download['xml_bytes'];
        exit;
    }
}

llxHeader('', 'Emitir SAF-T');
print load_fiche_titre('Emitir SAF-T mensal');
print '<div class="fichecenter"><div class="card" style="padding:16px;">';

$capabilities = array();
$capabilityError = '';
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
    print ' | <strong>SAF-T mensal:</strong> '.(!empty($capabilities['can_export_monthly_saft']) ? 'autorizado' : 'não autorizado');
    if (!empty($capabilities['quota'])) {
        print ' | <strong>Requests:</strong> '.(int) $capabilities['quota']['used'].'/'.(int) $capabilities['quota']['limit'];
        print ' | <strong>Restantes:</strong> '.(int) $capabilities['quota']['remaining'];
    }
    print '</div>';

    if (empty($capabilities['can_export_monthly_saft']) && !empty($capabilities['messages'])) {
        print '<div class="warning"><ul>';
        foreach ($capabilities['messages'] as $message) print '<li>'.dol_escape_htmltag($message).'</li>';
        print '</ul></div>';
    }
    if ($capabilityError !== '') {
        print '<div class="warning">'.dol_escape_htmltag($capabilityError).'</div>';
    }
}

if ($action === 'generate' && !empty($apiToken) && !empty($capabilities['can_export_monthly_saft'])) {
    if ($year < 2000 || $year > 2100 || $month < 1 || $month > 12) {
        setEventMessages('Ano/mês inválidos.', null, 'errors');
    } else {
        $result = saft_call_faturamento_saft_export_monthly($apiUrlPreview, $apiToken, $verifyTls, $year, $month, 120);
        if (empty($result['data']['success']) || empty($result['data']['export'])) {
            $msg = !empty($result['error']) ? $result['error'] : (!empty($result['data']['error']) ? $result['data']['error'] : 'Falha ao gerar SAF-T mensal no FaturaWeb.');
            if (!empty($result['data']['details']) && is_array($result['data']['details'])) {
                $msg .= ' '.implode(' ', array_slice($result['data']['details'], 0, 5));
            }
            setEventMessages($msg, null, 'errors');
            if ($clientDebug) {
                print '<details open><summary>Debug API</summary><pre>'.dol_escape_htmltag(json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)).'</pre></details>';
            }
        } else {
            $export = $result['data']['export'];
            setEventMessages('SAF-T mensal gerado com sucesso: '.(string) $export['file_name'], null, 'mesgs');
        }
    }
} elseif ($action === 'generate' && empty($capabilities['can_export_monthly_saft'])) {
    setEventMessages('Emissão bloqueada: o SAF-T Validator ainda não autorizou este token para exportar SAF-T mensal via Dolibarr.', null, 'errors');
}

if ($action === 'download' && $exportId > 0 && !empty($apiToken)) {
    $download = saft_call_faturamento_saft_export_download($apiUrlPreview, $apiToken, $verifyTls, $exportId, 90);
    setEventMessages(!empty($download['error']) ? $download['error'] : 'Falha ao descarregar SAF-T mensal.', null, 'errors');
}

print '<form method="POST" style="margin-top:16px;">';
print '<input type="hidden" name="token" value="'.newToken().'">';
print '<input type="hidden" name="action" value="generate">';
print '<label for="month">Mês</label><br>';
print '<select name="month" id="month" class="flat minwidth100">';
for ($m = 1; $m <= 12; $m++) {
    print '<option value="'.$m.'"'.($month === $m ? ' selected' : '').'>'.sprintf('%02d', $m).'</option>';
}
print '</select> ';
print '<label for="year" style="margin-left:12px;">Ano</label> ';
print '<select name="year" id="year" class="flat minwidth100">';
$currentYear = (int) dol_print_date($now, '%Y');
for ($y = $currentYear + 1; $y >= $currentYear - 5; $y--) {
    print '<option value="'.$y.'"'.($year === $y ? ' selected' : '').'>'.$y.'</option>';
}
print '</select> ';
print '<input type="submit" class="button button-save" value="Gerar SAF-T"'.(empty($capabilities['can_export_monthly_saft']) ? ' disabled' : '').'>';
print '</form>';
print '<p class="opacitymedium" style="margin-top:12px;">O ficheiro mensal é gerado e guardado pelo FaturaWeb. O Dolibarr apenas solicita a geração e descarrega o XML oficial via API.</p>';

$exportsResult = !empty($apiToken) && !empty($capabilities['can_export_monthly_saft'])
    ? saft_call_faturamento_saft_exports($apiUrlPreview, $apiToken, $verifyTls, 30)
    : array('data' => array('exports' => array()));
$exports = !empty($exportsResult['data']['exports']) && is_array($exportsResult['data']['exports']) ? $exportsResult['data']['exports'] : array();
if (!empty($apiToken) && !empty($capabilities['can_export_monthly_saft']) && empty($exports) && !empty($exportsResult['error'])) {
    print '<div class="warning">'.dol_escape_htmltag($exportsResult['error']).'</div>';
}

if (!empty($exports)) {
    print '<br><table class="noborder centpercent">';
    print '<tr class="liste_titre"><td>ID</td><td>Período</td><td>Ficheiro</td><td class="center">Faturas</td><td class="right">Total</td><td class="center">Criado em</td><td></td></tr>';
    foreach ($exports as $row) {
        $id = (int) (!empty($row['id']) ? $row['id'] : 0);
        $createdAt = !empty($row['created_at']) ? (string) $row['created_at'] : '';
        $downloadUrl = dol_buildpath('/custom/saft/export.php?mainmenu=saft&leftmenu=saft_export&action=download&export_id='.$id, 1);
        print '<tr class="oddeven">';
        print '<td>'.$id.'</td>';
        print '<td>'.sprintf('%04d-%02d', (int) $row['year'], (int) $row['month']).'</td>';
        print '<td>'.dol_escape_htmltag((string) $row['file_name']).'</td>';
        print '<td class="center">'.(int) $row['invoice_count'].'</td>';
        print '<td class="right">'.price(((int) $row['total_credit_cents']) / 100).'</td>';
        print '<td class="center">'.dol_escape_htmltag($createdAt).'</td>';
        print '<td class="right"><a class="button button-small" href="'.$downloadUrl.'">Descarregar XML</a></td>';
        print '</tr>';
    }
    print '</table>';
} else {
    print '<br><div class="opacitymedium">Nenhum SAF-T mensal gerado ainda.</div>';
}

print '</div></div>';

llxFooter();
$db->close();
