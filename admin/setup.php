<?php
/* Copyright (C) 2004-2017  Laurent Destailleur     <eldy@users.sourceforge.net>
 * Copyright (C) 2024       Frédéric France         <frederic.france@free.fr>
 * Copyright (C) 2026       Cia Linux          <general@cialinux.com>
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

/**
 * \file    saft/admin/setup.php
 * \ingroup saft
 * \brief   Saft setup page.
 */

// Load Dolibarr environment
$res = 0;
// Try main.inc.php into web root known defined into CONTEXT_DOCUMENT_ROOT (not always defined)
if (!$res && !empty($_SERVER["CONTEXT_DOCUMENT_ROOT"])) {
        $res = @include $_SERVER["CONTEXT_DOCUMENT_ROOT"]."/main.inc.php";
}
// Try main.inc.php into web root detected using web root calculated from SCRIPT_FILENAME
$tmp = empty($_SERVER['SCRIPT_FILENAME']) ? '' : $_SERVER['SCRIPT_FILENAME'];
$tmp2 = realpath(__FILE__);
$i = strlen($tmp) - 1;
$j = strlen($tmp2) - 1;
while ($i > 0 && $j > 0 && isset($tmp[$i]) && isset($tmp2[$j]) && $tmp[$i] == $tmp2[$j]) {
        $i--;
        $j--;
}
if (!$res && $i > 0 && file_exists(substr($tmp, 0, ($i + 1))."/main.inc.php")) {
        $res = @include substr($tmp, 0, ($i + 1))."/main.inc.php";
}
if (!$res && $i > 0 && file_exists(dirname(substr($tmp, 0, ($i + 1)))."/main.inc.php")) {
        $res = @include dirname(substr($tmp, 0, ($i + 1)))."/main.inc.php";
}
// Try main.inc.php using relative path
if (!$res && file_exists("../../main.inc.php")) {
        $res = @include "../../main.inc.php";
}
if (!$res && file_exists("../../../main.inc.php")) {
        $res = @include "../../../main.inc.php";
}
if (!$res) {
        die("Include of main fails");
}

// Libraries
require_once DOL_DOCUMENT_ROOT."/core/lib/admin.lib.php";
require_once '../lib/saft.lib.php';
//require_once "../class/myclass.class.php";

/**
 * @var Conf $conf
 * @var DoliDB $db
 * @var HookManager $hookmanager
 * @var Translate $langs
 * @var User $user
 */

// Translations
$langs->loadLangs(array("admin", "saft@saft"));

// Initialize a technical object to manage hooks of page. Note that conf->hooks_modules contains an array of hook context
/** @var HookManager $hookmanager */
$hookmanager->initHooks(array('saftsetup', 'globalsetup'));

// Parameters
$action = GETPOST('action', 'aZ09');
$backtopage = GETPOST('backtopage', 'alpha');
$modulepart = GETPOST('modulepart', 'aZ09');    // Used by actions_setmoduleoptions.inc.php

$value = GETPOST('value', 'alpha');
$label = GETPOST('label', 'alpha');
$scandir = GETPOST('scan_dir', 'alpha');
$type = 'myobject';

$error = 0;
$setupnotempty = 0;

// Set this to 1 to use the factory to manage constants. Warning, the generated module will be compatible with version v15+ only
$useFormSetup = 1;

if (!class_exists('FormSetup')) {
        require_once DOL_DOCUMENT_ROOT.'/core/class/html.formsetup.class.php';
}
$formSetup = new FormSetup($db);

// Access control
if (!$user->admin) {
        accessforbidden();
}


/*
 * ==========================================================
 *  ✅ FASE 1: parâmetros reais do módulo SAFT Validator Client
 *  (Removemos os campos demo do ModuleBuilder para evitar 500)
 * ==========================================================
 */

// Quantas faturas por página no preview (client)
$item = $formSetup->newItem('SAFT_PER_PAGE');
$item->nameText = 'Faturas por página (preview)';
$item->helpText = 'Quantidade de faturas por página na UI do módulo (paginação).';
$item->defaultFieldValue = '10';
$item->fieldAttr['placeholder'] = '10';
$item->cssClass = 'minwidth100';

// Debug do client (log/diagnóstico dentro do Dolibarr)
$item = $formSetup->newItem('SAFT_CLIENT_DEBUG')->setAsYesNo();
$item->nameText = 'Client debug (logs)';
$item->helpText = 'Mostra informações de debug da chamada à API (útil para diagnosticar 404/porta 2000/SSL/rate limits).<br><strong>Nota:</strong> Desativado por padrão. Ativar apenas quando necessário para troubleshooting.';
$item->defaultFieldValue = '0';  // Desabilitado por padrão por segurança

// Token/API Key para usar a API privada (opcional - se não configurado, usa API pública)
$item = $formSetup->newItem('SAFT_API_TOKEN');
$item->nameText = 'SAFT API Token (OPCIONAL)';
$item->helpText = '⚠️ DEIXE VAZIO para usar a API PÚBLICA (3 consultas/dia por IP).<br>'.
                  'Ou insira um token privado gerado no FaturaWeb.com para importação, emissão de faturas e SAF-T mensal.';
$item->fieldAttr['placeholder'] = 'Deixe vazio para modo público';
$item->fieldAttr['type'] = 'password';
$item->cssClass = 'minwidth500';

// End of definition of parameters
$setupnotempty += count($formSetup->items);

function saft_setup_access_badge($allowed, $labelAllowed = 'Autorizado', $labelDenied = 'Não autorizado')
{
        if ($allowed) {
                return '<span class="badge badge-status4">'.$labelAllowed.'</span>';
        }
        return '<span class="badge badge-status8">'.$labelDenied.'</span>';
}

function saft_setup_access_row($moduleName, $allowed, $detail)
{
        print '<tr>';
        print '<td width="30%"><strong>'.dol_escape_htmltag($moduleName).'</strong></td>';
        print '<td width="18%">'.saft_setup_access_badge($allowed).'</td>';
        print '<td>'.dol_escape_htmltag($detail).'</td>';
        print '</tr>';
}


/*
 * ==========================================================
 *  O resto abaixo é boilerplate do ModuleBuilder.
 *  Pode ficar como está (não afeta a fase 1).
 * ==========================================================
 */

$dirmodels = array_merge(array('/'), (array) $conf->modules_parts['models']);

$moduledir = 'saft';
$myTmpObjects = array();
// TODO Scan list of objects to fill this array
$myTmpObjects['myobject'] = array('label' => 'MyObject', 'includerefgeneration' => 0, 'includedocgeneration' => 0, 'class' => 'MyObject');

$tmpobjectkey = GETPOST('object', 'aZ09');
if ($tmpobjectkey && !array_key_exists($tmpobjectkey, $myTmpObjects)) {
        accessforbidden('Bad value for object. Hack attempt ?');
}


/*
 * Actions
 */

if ($action === 'update' && !empty($user->admin) && GETPOSTISSET('SAFT_API_ENV')) {
        $envConfig = saft_get_environment_config(GETPOST('SAFT_API_ENV', 'alpha'));

        $resEnv = dolibarr_set_const($db, 'SAFT_API_ENV', $envConfig['env'], 'chaine', 0, '', $conf->entity);
        $resUrl = dolibarr_set_const($db, 'SAFT_API_URL', $envConfig['api_url'], 'chaine', 0, '', $conf->entity);
        $resTls = dolibarr_set_const($db, 'SAFT_VERIFY_TLS', $envConfig['verify_tls'] ? '1' : '0', 'yesno', 0, '', $conf->entity);

        if (!($resEnv > 0 && $resUrl > 0 && $resTls > 0)) {
                setEventMessages('Falha ao atualizar o ambiente SAF-T.', null, 'errors');
        }

}

// For retrocompatibility Dolibarr < 15.0
if (versioncompare(explode('.', DOL_VERSION), array(15)) < 0 && $action == 'update' && !empty($user->admin)) {
        $formSetup->saveConfFromPost();
}

include DOL_DOCUMENT_ROOT.'/core/actions_setmoduleoptions.inc.php';

if ($action == 'updateMask') {
        $maskconst = GETPOST('maskconst', 'aZ09');
        $maskvalue = GETPOST('maskvalue', 'alpha');

        if ($maskconst && preg_match('/_MASK$/', $maskconst)) {
                $res = dolibarr_set_const($db, $maskconst, $maskvalue, 'chaine', 0, '', $conf->entity);
                if (!($res > 0)) {
                        $error++;
                }
        }

        if (!$error) {
                setEventMessages($langs->trans("SetupSaved"), null, 'mesgs');
        } else {
                setEventMessages($langs->trans("Error"), null, 'errors');
        }
} elseif ($action == 'specimen' && $tmpobjectkey) {
        $modele = GETPOST('module', 'alpha');

        $className = $myTmpObjects[$tmpobjectkey]['class'];
        $tmpobject = new $className($db);
        '@phan-var-force MyObject $tmpobject';
        $tmpobject->initAsSpecimen();

        // Search template files
        $file = '';
        $className = '';
        $dirmodels = array_merge(array('/'), (array) $conf->modules_parts['models']);
        foreach ($dirmodels as $reldir) {
                $file = dol_buildpath($reldir."core/modules/saft/doc/pdf_".$modele."_".strtolower($tmpobjectkey).".modules.php", 0);
                if (file_exists($file)) {
                        $className = "pdf_".$modele."_".strtolower($tmpobjectkey);
                        break;
                }
        }

        if ($className !== '') {
                require_once $file;

                $module = new $className($db);
                '@phan-var-force ModelePDFMyObject $module';

                '@phan-var-force ModelePDFMyObject $module';

                if ($module->write_file($tmpobject, $langs) > 0) {
                        header("Location: ".DOL_URL_ROOT."/document.php?modulepart=saft-".strtolower($tmpobjectkey)."&file=SPECIMEN.pdf");
                        return;
                } else {
                        setEventMessages($module->error, null, 'errors');
                        dol_syslog($module->error, LOG_ERR);
                }
        } else {
                setEventMessages($langs->trans("ErrorModuleNotFound"), null, 'errors');
                dol_syslog($langs->trans("ErrorModuleNotFound"), LOG_ERR);
        }
} elseif ($action == 'setmod') {
        // TODO Check if numbering module chosen can be activated by calling method canBeActivated
        if (!empty($tmpobjectkey)) {
                $constforval = 'SAFT_'.strtoupper($tmpobjectkey)."_ADDON";
                dolibarr_set_const($db, $constforval, $value, 'chaine', 0, '', $conf->entity);
        }
} elseif ($action == 'set') {
        // Activate a model
        $ret = addDocumentModel($value, $type, $label, $scandir);
} elseif ($action == 'del') {
        $ret = delDocumentModel($value, $type);
        if ($ret > 0) {
                if (!empty($tmpobjectkey)) {
                        $constforval = 'SAFT_'.strtoupper($tmpobjectkey).'_ADDON_PDF';
                        if (getDolGlobalString($constforval) == "$value") {
                                dolibarr_del_const($db, $constforval, $conf->entity);
                        }
                }
        }
} elseif ($action == 'setdoc') {
        // Set or unset default model
        if (!empty($tmpobjectkey)) {
                $constforval = 'SAFT_'.strtoupper($tmpobjectkey).'_ADDON_PDF';
                if (dolibarr_set_const($db, $constforval, $value, 'chaine', 0, '', $conf->entity)) {
                        // The constant that was read before the new set
                        // We therefore requires a variable to have a coherent view
                        $conf->global->{$constforval} = $value;
                }

                // We disable/enable the document template (into llx_document_model table)
                $ret = delDocumentModel($value, $type);
                if ($ret > 0) {
                        $ret = addDocumentModel($value, $type, $label, $scandir);
                }
        }
} elseif ($action == 'unsetdoc') {
        if (!empty($tmpobjectkey)) {
                $constforval = 'SAFT_'.strtoupper($tmpobjectkey).'_ADDON_PDF';
                dolibarr_del_const($db, $constforval, $conf->entity);
        }
}

$action = 'edit';


/*
 * View
 */

$form = new Form($db);

$help_url = '';
$title = "SaftSetup";

llxHeader('', $langs->trans($title), $help_url, '', 0, 0, '', '', '', 'mod-saft page-admin');

// Subheader
$linkback = '<a href="'.($backtopage ? $backtopage : DOL_URL_ROOT.'/admin/modules.php?restore_lastsearch_values=1').'">'.$langs->trans("BackToModuleList").'</a>';

print load_fiche_titre($langs->trans($title), $linkback, 'title_setup');

// Configuration header
$head = saftAdminPrepareHead();
print dol_get_fiche_head($head, 'settings', $langs->trans($title), -1, "saft@saft");

// Setup page goes here
echo '<span class="opacitymedium">'.$langs->trans("SaftSetupPage").'</span><br><br>';

if (!empty($formSetup->items)) {
        print $formSetup->generateOutput(true);
        print '<br>';
}

if (empty($setupnotempty)) {
        print '<br>'.$langs->trans("NothingToSetup");
}

print '<div class="info" style="margin: 0 0 16px 0;">';
print '<strong>Integração fiscal FaturaWeb</strong><br>';
print 'O Dolibarr envia o pedido. Numeração, hash, ATCUD, PDF e SAF-T são controlados pelo SAF-T Validator.<br>';
print 'Toda e qualquer configuração adicional deve ser feita no site faturaweb.com.<br>';
print 'Dados de emitente, ATCUD, séries e logo são controlados pelo portal faturaweb.com.';
print '</div>';

$envConfig = saft_get_runtime_api_config();

print '<div class="div-table-responsive-no-min" style="margin: 0 0 16px 0;">';
print '<table class="noborder centpercent">';
print '<tr class="liste_titre"><td colspan="2">Ambiente do webservice SAF-T</td></tr>';
print '<tr><td class="titlefield">Ambiente</td><td>';
print '<select name="SAFT_API_ENV" class="minwidth200">';
print '<option value="dev"'.($envConfig['env'] === 'dev' ? ' selected' : '').'>Ambiente dev</option>';
print '<option value="production"'.($envConfig['env'] === 'production' ? ' selected' : '').'>Ambiente production</option>';
print '</select>';
print '</td></tr>';
print '</table>';
print '</div>';
print '<div id="saft-save-actions" style="margin: 8px 0 16px 0;">';
print '<button type="button" class="button button-save" id="saft-save-all" style="display:none;">Guardar</button>';
print '</div>';

print '<script>';
print '(function(){';
print 'var envSelect = document.querySelector("select[name=\"SAFT_API_ENV\"]");';
print 'if (!envSelect) return;';
print 'var saveButton = document.querySelector("input.button-save[type=\"submit\"], button.button-save[type=\"submit\"]");';
print 'var mainForm = saveButton && saveButton.form ? saveButton.form : document.querySelector("form");';
print 'if (!mainForm) return;';
print 'var floatingSave = document.getElementById("saft-save-all");';
print 'if (!floatingSave) return;';
print 'var nativeSaves = mainForm.querySelectorAll("input.button-save[type=\"submit\"], button.button-save[type=\"submit\"]");';
print 'nativeSaves.forEach(function(btn){ btn.style.display = "none"; });';
print 'floatingSave.style.display = "inline-block";';
print 'mainForm.addEventListener("submit", function(){';
print 'var hiddenEnv = mainForm.querySelector("input[name=\"SAFT_API_ENV\"]");';
print 'if (!hiddenEnv) {';
print 'hiddenEnv = document.createElement("input");';
print 'hiddenEnv.type = "hidden";';
print 'hiddenEnv.name = "SAFT_API_ENV";';
print 'mainForm.appendChild(hiddenEnv);';
print '}';
print 'hiddenEnv.value = envSelect.value;';
print '});';
print 'floatingSave.addEventListener("click", function(){';
print 'if (typeof mainForm.requestSubmit === "function") {';
print 'mainForm.requestSubmit();';
print '} else {';
print 'mainForm.submit();';
print '}';
print '});';
print '})();';
print '</script>';

// Mostrar informações do usuário autenticado (se token configurado)
$envConfig = saft_get_runtime_api_config();
$apiToken = getDolGlobalString('SAFT_API_TOKEN', '');
$apiUrl = $envConfig['api_url'];
$verifyTls = (bool) $envConfig['verify_tls'];

if (!empty($apiToken)) {
        print '<br><div class="info" style="padding:16px; border-left:4px solid #28a745;">';
        print '<h3>🔒 API Privada Configurada</h3>';
        print '<div class="opacitymedium">'.$envConfig['label'].'</div><br>';
        
        $userInfo = saft_get_authenticated_user($apiUrl, $apiToken, $verifyTls);
        
        if (!empty($userInfo['ok']) && !empty($userInfo['data'])) {
                $userData = $userInfo['data'];
                $nif = !empty($userData['nif']) ? $userData['nif'] : 'N/A';
                $email = !empty($userData['email']) ? $userData['email'] : 'N/A';
                $dailyLimit = !empty($userData['daily_limit']) ? $userData['daily_limit'] : 'N/A';
                $usageToday = !empty($userData['usage_month']) ? $userData['usage_month'] : (!empty($userData['usage_today']) ? $userData['usage_today'] : 0);
                $remaining = max(0, (int)$dailyLimit - (int)$usageToday);
                
                print '<table class="noborder centpercent">';
                print '<tr><td width="30%"><strong>NIF Vinculado:</strong></td><td>'.dol_escape_htmltag($nif).'</td></tr>';
                print '<tr><td><strong>Email:</strong></td><td>'.dol_escape_htmltag($email).'</td></tr>';
                print '<tr><td><strong>Limite Mensal:</strong></td><td>'.dol_escape_htmltag((string)$dailyLimit).' consultas/mes</td></tr>';
                print '<tr><td><strong>Usado no Mes:</strong></td><td>'.dol_escape_htmltag((string)$usageToday).'/'.dol_escape_htmltag((string)$dailyLimit).' consultas</td></tr>';
                print '<tr><td><strong>Restante:</strong></td><td>'.dol_escape_htmltag((string)$remaining).' consultas</td></tr>';
                print '</table>';

                $capResult = saft_call_faturamento_capabilities($apiUrl, $apiToken, $verifyTls, 15);
                $capabilities = !empty($capResult['data']['capabilities']) && is_array($capResult['data']['capabilities']) ? $capResult['data']['capabilities'] : array();
                $capabilityError = '';
                if (empty($capabilities)) {
                        $capabilityError = !empty($capResult['error']) ? (string) $capResult['error'] : 'Endpoint de capacidades indisponível.';
                        if (!empty($capResult['status'])) {
                                $capabilityError .= ' HTTP '.$capResult['status'].'.';
                        }
                }

                print '<br><table class="noborder centpercent">';
                print '<tr class="liste_titre"><td>Módulo</td><td>Estado</td><td>Detalhe</td></tr>';
                saft_setup_access_row(
                        'SAF-T Validator',
                        true,
                        'Token privado válido. Pode consultar/validar XML conforme limites do backend.'
                );
                saft_setup_access_row(
                        'Importação de faturas',
                        $remaining > 0,
                        $remaining > 0 ? 'Token válido com créditos disponíveis para importação.' : 'Token válido, mas sem créditos/requests disponíveis.'
                );
                if (!empty($capabilities)) {
                        $messages = !empty($capabilities['messages']) && is_array($capabilities['messages']) ? implode(' ', $capabilities['messages']) : '';
                        saft_setup_access_row(
                                'Emissão de faturas',
                                !empty($capabilities['can_issue_invoices']),
                                !empty($capabilities['can_issue_invoices']) ? 'Token autorizado para emissão fiscal via Dolibarr.' : ($messages !== '' ? $messages : 'Token ainda não autorizado para emissão fiscal via Dolibarr.')
                        );
                        saft_setup_access_row(
                                'Emissão SAF-T mensal',
                                !empty($capabilities['can_export_monthly_saft']),
                                !empty($capabilities['can_export_monthly_saft']) ? 'Token autorizado para gerar e descarregar SAF-T mensal via Dolibarr.' : ($messages !== '' ? $messages : 'Token ainda não autorizado para SAF-T mensal via Dolibarr.')
                        );
                } else {
                        saft_setup_access_row(
                                'Emissão de faturas',
                                false,
                                $capabilityError
                        );
                        saft_setup_access_row(
                                'Emissão SAF-T mensal',
                                false,
                                $capabilityError
                        );
                }
                print '</table>';
        } else {
                $errorMsg = !empty($userInfo['error']) ? $userInfo['error'] : 'Erro desconhecido';
                print '<div class="warning">⚠️ Não foi possível verificar o token: '.dol_escape_htmltag($errorMsg).'</div>';
        }
        
        print '</div>';
}

// Page end
print dol_get_fiche_end();

llxFooter();
$db->close();
