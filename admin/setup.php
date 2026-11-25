<?php
/*
 * QontoSync dolibarr module 
 * @file       admin/setup.php
 * @author     Gaëtan COURTOIS
 * @license    GPLv3
 */

$res = 0;
if (! $res && file_exists("../../main.inc.php")) $res = @include "../../main.inc.php";
if (! $res && file_exists("../../../main.inc.php")) $res = @include "../../../main.inc.php";
if (! $res && file_exists("../../../../main.inc.php")) $res = @include "../../../../main.inc.php";
if (! $res && preg_match('/\/custom\//', $_SERVER["PHP_SELF"])) $res = @include "../../../../main.inc.php";

if (!function_exists('dolibarr_set_const')) require_once DOL_DOCUMENT_ROOT.'/core/lib/admin.lib.php';
if (!function_exists('qontosyncAdminPrepareHead')) dol_include_once('/qontosync/lib/qontosync.lib.php');
if (!class_exists('QontoApi')) dol_include_once('/qontosync/class/qontoapi.class.php');

$langs->loadLangs(array("admin", "qontosync@qontosync"));

// Check if user is admin
if (!$user->admin) {
	accessforbidden();
}

$action = GETPOST('action', 'aZ');

if (GETPOST('save', 'alpha')) { $action = 'update'; }
if (GETPOST('test', 'alpha')) { $action = 'test'; }

$apikey = GETPOST('QONTOSYNC_API_KEY', 'none');
$slug = GETPOST('QONTOSYNC_SLUG', 'alphanohtml');
$sandbox = getDolGlobalInt('QONTOSYNC_SANDBOX');
$sandbox_token = GETPOST('QONTOSYNC_SANDBOX_TOKEN', 'none');

// Update constants
if ($action == 'update') {
	// Check CSRF token
	if (GETPOST('token', 'alpha') !== currentToken()) {
		setEventMessages($langs->trans("InvalidToken"), null, 'errors');
		$action = '';
	} else {
		// Note: We do not call dolEncrypt() manually here. Dolibarr's dolibarr_set_const()
		// automatically encrypts sensitive constants whose names end with _KEY or _TOKEN
		// (AES-256-CTR via dolEncrypt()) in llx_const, and Conf::setValues() automatically
		// decrypts them on boot. Passing plaintext also keeps $conf->global consistent in this request.
		if ($apikey !== '') {
			dolibarr_set_const($db, 'QONTOSYNC_API_KEY', $apikey, 'chaine', 0, '', $conf->entity);
		}

		dolibarr_set_const($db, 'QONTOSYNC_SLUG', $slug, 'chaine', 0, '', $conf->entity);

		if ($sandbox_token !== '') {
			dolibarr_set_const($db, 'QONTOSYNC_SANDBOX_TOKEN', $sandbox_token, 'chaine', 0, '', $conf->entity);
		}

		setEventMessages($langs->trans("SetupSaved"), null, 'mesgs');
	}
}

// Test API key
if ($action == 'test') {
	// Check CSRF token
	if (GETPOST('token', 'alpha') !== currentToken()) {
		setEventMessages($langs->trans("InvalidToken"), null, 'errors');
		$action = '';
	} else {
		// If a secret field was left empty, fall back to the stored value (already decrypted by Dolibarr on load)
		if ($apikey === '') {
			$apikey = getDolGlobalString('QONTOSYNC_API_KEY');
		}
		if ($sandbox_token === '') {
			$sandbox_token = getDolGlobalString('QONTOSYNC_SANDBOX_TOKEN');
		}

		if (empty($apikey) || empty($slug)) {
			setEventMessages($langs->trans("NoParameters"), null, 'warnings');
		} else {
			$api = new QontoApi($apikey, $slug, $sandbox, $sandbox_token);
			if ($api->testConnection() === false) {
				setEventMessages($langs->trans("ConnectionError"), null, 'errors');
			} else {
				setEventMessages($langs->trans("Connected"), null, 'mesgs');
			}
		}
	}
}

$apikey_placeholder = (getDolGlobalString('QONTOSYNC_API_KEY') !== '') ? '****************' : '';
$sandbox_token_placeholder = (getDolGlobalString('QONTOSYNC_SANDBOX_TOKEN') !== '') ? '****************' : '';

$page_name = "QontoSyncSetup";
llxHeader('', $langs->trans("QontoSyncSetup"));

$linkback = '<a href="'.DOL_URL_ROOT.'/admin/modules.php?restore_lastsearch_values=1">'.$langs->trans("BackToModuleList").'</a>';
print load_fiche_titre($langs->trans("QontoSyncSetup"), $linkback, 'title_setup');

$head = qontosyncAdminPrepareHead();

print dol_get_fiche_head(
    $head,
    'setup',
    'QontoSync',
    -1,
    'bank'
);

/**
 * Qonto API parameters
 */
print '<form method="post" action="'.dol_escape_htmltag($_SERVER["PHP_SELF"]).'">';
print '<input type="hidden" name="token" value="'.newToken().'">';
print '<input type="hidden" name="action" value="update">';

print '<table class="noborder centpercent">';
print '<tr class="liste_titre">';
print '<td>'.$langs->trans("Parameter").'</td>';
print '<td>'.$langs->trans("Value").'</td>';
print '</tr>';

print '<tr class="oddeven">';
print '<td>'.$langs->trans("QontoSlug").'</td>';
print '<td><input type="text" size="60" name="QONTOSYNC_SLUG" value="'.dol_escape_htmltag(getDolGlobalString('QONTOSYNC_SLUG')).'"></td>';
print '</tr>';


print '<tr class="oddeven">';
print '<td>'.$langs->trans("QontoAPIKey").'</td>';
print '<td><input type="password" size="60" name="QONTOSYNC_API_KEY" value="" placeholder="'.$apikey_placeholder.'" autocomplete="new-password"></td>';
print '</tr>';

print '<tr class="oddeven">';
print '<td>'.$langs->trans("QontoSandboxMode").'</td>';
print '<td>'.ajax_constantonoff('QONTOSYNC_SANDBOX').'</td>';
print '</tr>';

print '<tr class="oddeven">';
print '<td>'.$langs->trans("QontoSandboxToken").'</td>';
print '<td><input type="password" size="60" name="QONTOSYNC_SANDBOX_TOKEN" value="" placeholder="'.$sandbox_token_placeholder.'" autocomplete="new-password"></td>';
print '</tr>';

print '</table>';

print '<div class="center"><br>';
print '<input type="submit" name="save" class="button" value="'.$langs->trans("Save").'">&nbsp;&nbsp;';
print '<input type="submit" name="test" class="button" value="'.$langs->trans("Test").'">';
print '</div>';

print '</form>';

print dol_get_fiche_end();

llxFooter();