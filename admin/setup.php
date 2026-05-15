<?php

// Load Dolibarr environment
$res = 0;
if (!$res && file_exists("../../../main.inc.php")) $res = @include "../../../main.inc.php";
if (!$res && file_exists("../../../../main.inc.php")) $res = @include "../../../../main.inc.php";

if (!$res) die("Include of main.inc.php failed");

require_once DOL_DOCUMENT_ROOT . '/core/lib/admin.lib.php';
require_once DOL_DOCUMENT_ROOT . '/core/class/html.form.class.php';

// Load language files
$langs->loadLangs(array("admin", "qontosync@qontosync"));

// Security check
if (!$user->admin) {
    accessforbidden();
}

$action = GETPOST('action', 'alpha');

/*
 * Actions
 */

if ($action == 'update') {
    $error = 0;

    $api_login = GETPOST('QONTOSYNC_API_LOGIN', 'alpha');
    $api_key = GETPOST('QONTOSYNC_API_KEY', 'alpha');
    $mock_mode = GETPOST('QONTOSYNC_MOCK_MODE', 'int');

    if (!dolibarr_set_const($db, "QONTOSYNC_API_LOGIN", $api_login, 'CHAMP_ET_VALEUR', 0, '', $conf->entity)) $error++;
    if (!dolibarr_set_const($db, "QONTOSYNC_API_KEY", $api_key, 'CHAMP_ET_VALEUR', 0, '', $conf->entity)) $error++;
    if (!dolibarr_set_const($db, "QONTOSYNC_MOCK_MODE", $mock_mode, 'CHAMP_ET_VALEUR', 0, '', $conf->entity)) $error++;

    if (!$error) {
        setEventMessages($langs->trans("SetupSaved"), null, 'mesgs');
    } else {
        setEventMessages($langs->trans("Error"), null, 'errors');
    }
}

/*
 * View
 */

$form = new Form($db);

llxHeader('', $langs->trans("QontoSyncSetup"));

$linkback = '<a href="' . DOL_URL_ROOT . '/admin/modules.php?restore_lastsearch_values=1">' . $langs->trans("BackToModuleList") . '</a>';
print load_fiche_titre($langs->trans("QontoSyncSetup"), $linkback, 'title_setup');

print '<form method="post" action="' . $_SERVER["PHP_SELF"] . '">';
print '<input type="hidden" name="token" value="' . newToken() . '">';
print '<input type="hidden" name="action" value="update">';

print '<table class="noborder centpercent">';
print '<tr class="liste_titre">';
print '<td>' . $langs->trans("Parameter") . '</td>';
print '<td>' . $langs->trans("Value") . '</td>';
print '</tr>';

// API Login
print '<tr class="oddeven">';
print '<td>' . $langs->trans("QontoSyncApiLogin") . '</td>';
print '<td><input type="text" size="40" name="QONTOSYNC_API_LOGIN" value="' . dol_escape_htmltag($conf->global->QONTOSYNC_API_LOGIN) . '"></td>';
print '</tr>';

// API Key
print '<tr class="oddeven">';
print '<td>' . $langs->trans("QontoSyncApiKey") . '</td>';
print '<td><input type="password" size="40" name="QONTOSYNC_API_KEY" value="' . dol_escape_htmltag($conf->global->QONTOSYNC_API_KEY) . '"></td>';
print '</tr>';

// Mock Mode
print '<tr class="oddeven">';
print '<td>' . $form->textwithpicto($langs->trans("QontoSyncMockMode"), $langs->trans("QontoSyncMockModeHelp")) . '</td>';
print '<td>' . $form->selectyesno("QONTOSYNC_MOCK_MODE", $conf->global->QONTOSYNC_MOCK_MODE, 1) . '</td>';
print '</tr>';

print '</table>';

print '<div class="center">';
print '<input type="submit" class="button" value="' . $langs->trans("Save") . '">';
print '</div>';

print '</form>';

llxFooter();
$db->close();
