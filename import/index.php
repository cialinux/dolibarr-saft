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

$langs->loadLangs(['main', 'bills', 'companies', 'saft@saft']);
$form = new Form($db);

$action   = GETPOST('action', 'aZ09');
$tokenxml = GETPOST('tokenxml', 'alpha');

llxHeader('', 'Importar SAF-T');
print load_fiche_titre('Importar faturas (SAF-T)');

print '<div class="fichecenter"><div class="card" style="padding:16px;">';

/* ============================================================
 * FASE 1 – UPLOAD
 * ============================================================ */
if ($action === 'upload') {
    if (!empty($_FILES['file']['tmp_name'])) {
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
 * FASE 3 – IMPORT
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
    }
}

/* ============================================================
 * FORM UPLOAD
 * ============================================================ */
print '<hr><form method="POST" enctype="multipart/form-data">';
print '<input type="hidden" name="action" value="upload">';
print '<input type="hidden" name="token" value="'.newToken().'">';
print '<input type="file" name="file" required> ';
print '<input type="submit" class="button" value="Enviar XML">';
print '</form>';

print '</div></div>';

llxFooter();
$db->close();