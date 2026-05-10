<?php

// Load Dolibarr environment
$res = 0;
if (!$res && file_exists("../../../main.inc.php")) {
    $res = @include "../../../main.inc.php";
}
if (!$res && file_exists("../../../../main.inc.php")) {
    $res = @include "../../../../main.inc.php";
}
if (!$res && file_exists("../../../../../main.inc.php")) {
    $res = @include "../../../../../main.inc.php";
}
if (!$res && preg_match('/\/custom\//', $_SERVER['PHP_SELF'])) {
    $res = @include "../../../../../main.inc.php";
}

if (!$res) {
    die("Include of main.inc.php failed");
}

require_once DOL_DOCUMENT_ROOT . '/core/lib/admin.lib.php';
require_once DOL_DOCUMENT_ROOT . '/core/class/html.form.class.php';
require_once '../class/api.qonto.class.php';

// Load language files
$langs->loadLangs(array("admin", "qontosync@qontosync"));

// Security check
if (!$user->admin) {
    accessforbidden();
}

$action = GETPOST('action', 'alpha');
if (GETPOST('save', 'alpha')) $action = 'update';
if (GETPOST('test', 'alpha')) $action = 'test';

$api_login = (GETPOST('QONTOSYNC_API_LOGIN', 'alpha') ? GETPOST('QONTOSYNC_API_LOGIN', 'alpha') : $conf->global->QONTOSYNC_API_LOGIN);
$api_key = (GETPOST('QONTOSYNC_API_KEY', 'alpha') ? GETPOST('QONTOSYNC_API_KEY', 'alpha') : $conf->global->QONTOSYNC_API_KEY);

/*
 * Actions
 */

if ($action == 'update') {
    $error = 0;

    if (!dolibarr_set_const($db, "QONTOSYNC_API_LOGIN", $api_login, 'CHAMP_ET_VALEUR', 0, '', $conf->entity)) {
        $error++;
    }
    if (!dolibarr_set_const($db, "QONTOSYNC_API_KEY", $api_key, 'CHAMP_ET_VALEUR', 0, '', $conf->entity)) {
        $error++;
    }

    if (!$error) {
        setEventMessages($langs->trans("SetupSaved"), null, 'mesgs');
    } else {
        setEventMessages($langs->trans("ErrorFailedToSaveEntity", $conf->entity), null, 'errors');
    }
}

if ($action == 'test') {
    $qonto = new QontoAPI($db, $api_login, $api_key);
    $result = $qonto->testConnection();
    if ($result > 0) {
        setEventMessages($langs->trans("qontosyncConnectionSuccess"), null, 'mesgs');
    } else {
        setEventMessages($langs->trans("qontosyncConnectionError"), null, 'errors');
    }
}

/*
 * View
 */

$form = new Form($db);

llxHeader('', $langs->trans("qontosyncSetup"));

$linkback = '<a href="' . DOL_URL_ROOT . '/admin/modules.php?restore_lastsearch_values=1">' . $langs->trans("BackToModuleList") . '</a>';
print load_fiche_titre($langs->trans("qontosyncSetup"), $linkback, 'title_setup');

print '<form method="post" action="' . $_SERVER["PHP_SELF"] . '">';
print '<input type="hidden" name="token" value="' . newToken() . '">';
print '<input type="hidden" name="action" value="update">';

print '<table class="noborder centpercent">';
print '<tr class="liste_titre">';
print '<td>' . $langs->trans("Parameter") . '</td>';
print '<td>' . $langs->trans("Value") . '</td>';
print '</tr>';

// Qonto Login/Slug
print '<tr class="oddeven">';
print '<td>' . $form->textwithpicto($langs->trans("qontosyncApiLogin"), $langs->trans("qontosyncApiLoginHelp")) . '</td>';
print '<td><input type="text" size="40" name="QONTOSYNC_API_LOGIN" value="' . dol_escape_htmltag($api_login) . '"></td>';
print '</tr>';

// Qonto Secret Key
print '<tr class="oddeven">';
print '<td>' . $form->textwithpicto($langs->trans("qontosyncApiKey"), $langs->trans("qontosyncApiKeyHelp")) . '</td>';
print '<td><input type="password" size="40" name="QONTOSYNC_API_KEY" value="' . dol_escape_htmltag($api_key) . '"></td>';
print '</tr>';

print '</table>';

print '<div class="center">';
print '<input type="submit" name="save" class="button button-save" value="' . $langs->trans("Save") . '">';
print '&nbsp;&nbsp;';
print '<input type="submit" name="test" class="button" value="' . $langs->trans("qontosyncTestConnection") . '">';
print '</div>';

print '</form>';

llxFooter();
$db->close();
