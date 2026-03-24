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
require_once __DIR__.'/../lib/saft.lib.php';

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
            $commit = saft_call_sessions_commit(
                $sessionId,
                $indexes,
                array(
                    'api_url' => $apiUrlPreview,
                    'api_token' => $apiToken,
                    'verify_tls' => $verifyTls,
                    'timeout' => 120,
                    'skip_duplicates' => true,
                    'user_id' => !empty($user->id) ? (int) $user->id : null,
                )
            );

            if (!empty($commit['data']) && !empty($commit['data']['ok'])) {
                $created = !empty($commit['data']['created']) && is_array($commit['data']['created']) ? $commit['data']['created'] : array();
                $failed = !empty($commit['data']['failed']) && is_array($commit['data']['failed']) ? $commit['data']['failed'] : array();

                print '<div class="ok">Importação concluída com sucesso.</div>';
                print '<div style="margin:10px 0; padding:8px; background:#f0f0f0; border-radius:4px;">';
                print '<strong>Resumo:</strong> ';
                print 'Clientes criados: '.(int) (!empty($created['customers']) ? $created['customers'] : 0);
                print ' | Faturas criadas: '.(int) (!empty($created['invoices']) ? $created['invoices'] : 0);
                print ' | Linhas criadas: '.(int) (!empty($created['lines']) ? $created['lines'] : 0);
                print ' | Falhas: '.count($failed);
                print '</div>';

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
            } else {
                $msg = !empty($commit['error']) ? $commit['error'] : 'Falha ao executar commit da importação.';
                setEventMessages($msg, null, 'errors');
                $action = 'preview';
            }
        }
    }
}

if (($action === 'preview' || $sessionId !== '') && $sessionId !== '') {
    $preview = saft_call_sessions_get(
        $sessionId,
        1,
        1000,
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
        $dedup = !empty($preview['data']['dedup']) && is_array($preview['data']['dedup']) ? $preview['data']['dedup'] : array();

        print load_fiche_titre('Fase 2: Pré-visualização');
        print '<div style="margin:10px 0; padding:8px; background:#f0f0f0; border-radius:4px;">';
        print '<strong>Contadores:</strong> ';
        print 'Total: '.(int) count($rows);
        print ' | Elegíveis: '.(int) (!empty($dedup['new']) ? $dedup['new'] : 0);
        print ' | Duplicadas: '.(int) (!empty($dedup['duplicates']) ? $dedup['duplicates'] : 0);
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
            print '<td>'.($isDup ? 'duplicada' : 'ok').'</td>';
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
