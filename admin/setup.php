<?php
/*
 * QontoSync dolibarr module 
 * @file       setup.php
 * @author     Gaëtan COURTOIS
 * @license    GPLv3
 */

$res = 0;
if (! $res && file_exists("../../main.inc.php")) $res = @include "../../main.inc.php";
if (! $res && file_exists("../../../main.inc.php")) $res = @include "../../../main.inc.php";
if (! $res && file_exists("../../../../main.inc.php")) $res = @include "../../../../main.inc.php";
if (! $res && preg_match('/\/custom\//', $_SERVER["PHP_SELF"])) $res = @include "../../../../main.inc.php";

dol_include_once('/core/lib/admin.lib.php');
dol_include_once('/core/class/html.form.class.php');
dol_include_once('/qontosync/class/qontoapi.class.php');

$langs->loadLangs(array("admin", "qontosync@qontosync", "bills", "banks", "compta"));

// Check if user is admin
if (!$user->admin) {
	accessforbidden();
}

$form = new Form($db);

$action = GETPOST('action', 'aZ');
if (GETPOST('save', 'alpha')) {
	$action = 'update';
}
if (GETPOST('test', 'alpha')) {
	$action = 'test';
}

$apikey = GETPOST('QONTOSYNC_API_KEY', 'alphanohtml');
$slug = GETPOST('QONTOSYNC_SLUG', 'alphanohtml');
$sandbox = GETPOST('QONTOSYNC_SANDBOX', 'int') ? 1 : 0;
$sandbox_token = GETPOST('QONTOSYNC_SANDBOX_TOKEN', 'alphanohtml');
$pm_transfer = GETPOST('QONTOSYNC_PM_TRANSFER', 'int');
$pm_card = GETPOST('QONTOSYNC_PM_CARD', 'int');
$pm_dd = GETPOST('QONTOSYNC_PM_DD', 'int');
$pm_check = GETPOST('QONTOSYNC_PM_CHECK', 'int');
$tolerance_days = GETPOST('QONTOSYNC_TOLERANCE_DAYS', 'int');

// Update constants
if ($action == 'update') {
	// Check CSRF token
	if (GETPOST('token', 'alpha') !== currentToken()) {
		setEventMessages($langs->trans("InvalidToken"), null, 'errors');
		$action = '';
	} else {
		dolibarr_set_const($db, 'QONTOSYNC_API_KEY', $apikey, 'chaine', 0, '', $conf->entity);
		dolibarr_set_const($db, 'QONTOSYNC_SLUG', $slug, 'chaine', 0, '', $conf->entity);
		dolibarr_set_const($db, 'QONTOSYNC_SANDBOX', $sandbox, 'chaine', 0, '', $conf->entity);
		dolibarr_set_const($db, 'QONTOSYNC_SANDBOX_TOKEN', $sandbox_token, 'chaine', 0, '', $conf->entity);

		dolibarr_set_const($db, 'QONTOSYNC_PM_TRANSFER', $pm_transfer, 'chaine', 0, '', $conf->entity);
		dolibarr_set_const($db, 'QONTOSYNC_PM_CARD', $pm_card, 'chaine', 0, '', $conf->entity);
		dolibarr_set_const($db, 'QONTOSYNC_PM_DD', $pm_dd, 'chaine', 0, '', $conf->entity);
		dolibarr_set_const($db, 'QONTOSYNC_PM_CHECK', $pm_check, 'chaine', 0, '', $conf->entity);
		
		dolibarr_set_const($db, 'QONTOSYNC_TOLERANCE_DAYS', $tolerance_days, 'chaine', 0, '', $conf->entity);

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

// Get VAR from dictionary
$sql = "SELECT id, code, libelle FROM " . MAIN_DB_PREFIX . "c_paiement WHERE active = 1 AND entity IN (" . getEntity('c_paiement') . ")";
$resql = $db->query($sql);
$payment_modes = array();
$payment_modes[''] = '-- ' . $langs->trans("Select") . ' --';

if ($resql) {
	while ($obj = $db->fetch_object($resql)) {
		$trans_key = "PaymentTypeShort" . $obj->code;
		$translated_label = $langs->trans($trans_key);
		
		if ($translated_label == $trans_key) {
			$translated_label = $langs->trans($obj->code);
			if ($translated_label == $obj->code) {
				$translated_label = $obj->libelle;
			}
		}
		
		$payment_modes[$obj->id] = $translated_label . ' (' . $obj->code . ')';
	}
	$db->free($resql);
}

$page_name = "QontoSyncSetup";
llxHeader('', $langs->trans("QontoSyncSetup"));

$linkback = '<a href="'.DOL_URL_ROOT.'/admin/modules.php?restore_lastsearch_values=1">'.$langs->trans("BackToModuleList").'</a>';
print load_fiche_titre($langs->trans("QontoSyncSetup"), $linkback, 'title_setup');


/**
 * Qonto API parameters
 */
print '<form method="post" action="'.$_SERVER["PHP_SELF"].'">';
print '<input type="hidden" name="token" value="'.newToken().'">';
print '<input type="hidden" name="action" value="update">';

print '<table class="noborder centpercent">';
print '<tr class="liste_titre">';
print '<td>'.$langs->trans("Parameter").'</td>';
print '<td>'.$langs->trans("Value").'</td>';
print '</tr>';

print '<tr class="oddeven">';
print '<td>'.$langs->trans("QontoSlug").'</td>';
print '<td><input type="text" size="60" name="QONTOSYNC_SLUG" value="'.getDolGlobalString('QONTOSYNC_SLUG').'"></td>';
print '</tr>';


print '<tr class="oddeven">';
print '<td>'.$langs->trans("QontoAPIKey").'</td>';
print '<td><input type="text" size="60" name="QONTOSYNC_API_KEY" value="'.getDolGlobalString('QONTOSYNC_API_KEY').'"></td>';
print '</tr>';

print '<tr class="oddeven">';
print '<td>'.$langs->trans("QontoSandboxMode").'</td>';
print '<td><input type="checkbox" name="QONTOSYNC_SANDBOX" value="1"'.(getDolGlobalString('QONTOSYNC_SANDBOX') ? ' checked="checked"' : '').'></td>';
print '</tr>';

print '<tr class="oddeven">';
print '<td>'.$langs->trans("QontoSandboxToken").'</td>';
print '<td><input type="text" size="60" name="QONTOSYNC_SANDBOX_TOKEN" value="'.getDolGlobalString('QONTOSYNC_SANDBOX_TOKEN').'"></td>';
print '</tr>';

print '<tr class="liste_titre"><td colspan="2">'.$langs->trans("Correspondence").'</td></tr>';

/**
 * Payments parameters
 */
print '<tr class="oddeven">';
print '<td>'.$langs->trans("QontoSyncPMTransfer").'</td>';
print '<td>' . $form->selectarray('QONTOSYNC_PM_TRANSFER', $payment_modes, getDolGlobalString('QONTOSYNC_PM_TRANSFER')) . '</td>';
print '</tr>';

print '<tr class="oddeven">';
print '<td>'.$langs->trans("QontoSyncPMCard").'</td>';
print '<td>' . $form->selectarray('QONTOSYNC_PM_CARD', $payment_modes, getDolGlobalString('QONTOSYNC_PM_CARD')) . '</td>';
print '</tr>';

print '<tr class="oddeven">';
print '<td>'.$langs->trans("QontoSyncPMDD").'</td>';
print '<td>' . $form->selectarray('QONTOSYNC_PM_DD', $payment_modes, getDolGlobalString('QONTOSYNC_PM_DD')) . '</td>';
print '</tr>';

print '<tr class="oddeven">';
print '<td>'.$langs->trans("QontoSyncPMCheck").'</td>';
print '<td>' . $form->selectarray('QONTOSYNC_PM_CHECK', $payment_modes, getDolGlobalString('QONTOSYNC_PM_CHECK')) . '</td>';
print '</tr>';

print '<tr class="oddeven">';
print '<td>'.$langs->trans("QontoSyncToleranceDays").'<br><span class="opacitymedium small">'.$langs->trans("QontoSyncToleranceDaysTooltip").'</span></td>';
// if empty, set 7 by default
$val_tolerance = getDolGlobalString('QONTOSYNC_TOLERANCE_DAYS') !== '' ? getDolGlobalString('QONTOSYNC_TOLERANCE_DAYS') : '7';
print '<td><input type="number" min="0" max="365" size="5" name="QONTOSYNC_TOLERANCE_DAYS" value="'.$val_tolerance.'"></td>';
print '</tr>';

print '</table>';

print '<div class="center"><br>';
print '<input type="submit" name="save" class="button" value="'.$langs->trans("Save").'">&nbsp;&nbsp;';
print '<input type="submit" name="test" class="button" value="'.$langs->trans("Test").'">';
print '</div>';

print '</form>';

llxFooter();