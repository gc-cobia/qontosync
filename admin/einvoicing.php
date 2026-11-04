<?php
/*
 * QontoSync dolibarr module 
 * @file       admin/einvoicing.php
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
if (!class_exists('Form')) require_once DOL_DOCUMENT_ROOT.'/core/class/html.form.class.php';
if (!class_exists('QontoApi')) dol_include_once('/qontosync/class/qontoapi.class.php');

$langs->loadLangs(array("admin", "products", "qontosync@qontosync"));

// Check if user is admin
if (!$user->admin) {
	accessforbidden();
}

$form = new Form($db);

$action = GETPOST('action', 'aZ09');
if (GETPOST('save', 'alpha')) {
	$action = 'update';
}

if ($action === 'update') {
	if (GETPOST('token', 'alpha') !== currentToken()) {
		setEventMessages($langs->trans("InvalidToken"), null, 'errors');
	} else {
		if (GETPOSTISSET('QONTOSYNC_EI_SUPP_CATCHALL_PRODUCT_ID')) {
			$catchallProdId = GETPOSTINT('QONTOSYNC_EI_SUPP_CATCHALL_PRODUCT_ID');
			dolibarr_set_const($db, 'QONTOSYNC_EI_SUPP_CATCHALL_PRODUCT_ID', ($catchallProdId > 0 ? (string) $catchallProdId : ''), 'chaine', 0, '', $conf->entity);
		}
		setEventMessages($langs->trans("SetupSaved"), null, 'mesgs');
	}
}

// Fetch live e-invoicing settings for display
$api = new QontoApi();
$live_settings = false;
$live_sending_status = '';
$live_receiving_status = '';
if (empty($api->error)) {
	$live_settings = $api->getEInvoicingSettings();
	if (is_array($live_settings)) {
		$live_sending_status = isset($live_settings['sending_status']) ? $live_settings['sending_status'] : (isset($live_settings['einvoicing_settings']['sending_status']) ? $live_settings['einvoicing_settings']['sending_status'] : '');
		$live_receiving_status = isset($live_settings['receiving_status']) ? $live_settings['receiving_status'] : (isset($live_settings['einvoicing_settings']['receiving_status']) ? $live_settings['einvoicing_settings']['receiving_status'] : '');
	}
}

$page_name = "QontoSyncSetup";
llxHeader('', $langs->trans("QontoSyncSetup"));

$linkback = '<a href="'.DOL_URL_ROOT.'/admin/modules.php?restore_lastsearch_values=1">'.$langs->trans("BackToModuleList").'</a>';
print load_fiche_titre($langs->trans("QontoSyncSetup"), $linkback, 'title_setup');

$head = qontosyncAdminPrepareHead();

print dol_get_fiche_head(
    $head,
    'einvoicing',
    'QontoSync',
    -1,
    'bank'
);

print '<form method="post" action="'.dol_escape_htmltag($_SERVER["PHP_SELF"]).'">';
print '<input type="hidden" name="token" value="'.newToken().'">';
print '<input type="hidden" name="action" value="update">';

// LIVE STATUS BANNER
print '<table class="noborder centpercent">';

print '<tr class="oddeven">';
print '<td><strong>'.$langs->trans("QontoLiveEInvoicingStatus").'</strong></td>';

if (getDolGlobalInt('QONTOSYNC_SANDBOX')) {
	print '<td>';
    print '<span class="badge badge-status8">' . dol_escape_htmltag($langs->trans("QontoCanNotInSandbox")) . '</span>';
	print '</td>';
} else {
	print '<td>';

	$rec_badge_class = (strtolower($live_receiving_status) === 'enabled') ? 'badge-status4' : 'badge-status8';
	$send_badge_class = (strtolower($live_sending_status) === 'enabled') ? 'badge-status4' : 'badge-status8';

	print '<span class="badge '.$rec_badge_class.'">'.$langs->trans("QontoReceivingStatus").': '.dol_escape_htmltag($live_receiving_status ? $live_receiving_status : $langs->trans("Disabled")).'</span> ';
	print '<span class="badge '.$send_badge_class.'">'.$langs->trans("QontoSendingStatus").': '.dol_escape_htmltag($live_sending_status ? $live_sending_status : $langs->trans("Disabled")).'</span>';
	print '</td>';
}

print '</tr>';

// EINVOICING ACTIVATION
print '<tr class="oddeven">';
print '<td>'.$langs->trans("QontoEInvoicingEnabled").'<br><span class="opacitymedium small">'.$langs->trans("QontoEInvoicingEnabledTooltip").'</span></td>';
print '<td>'.ajax_constantonoff('QONTOSYNC_EI_ENABLED').'</td>';
print '</tr>';

// SUPPLIERS PARAMETERS
print '<tr class="liste_titre"><td colspan="2">'.$langs->trans("QontoSuppliersParam").'</td></tr>';

print '<tr class="oddeven">';
print '<td>'.$langs->trans("QontoEInvoicingAutoThirdparty").'<br><span class="opacitymedium small">'.$langs->trans("QontoEInvoicingAutoThirdpartyTooltip").'</span></td>';
print '<td>'.ajax_constantonoff('QONTOSYNC_EI_SUPP_AUTO_THIRDPARTY').'</td>';
print '</tr>';

print '<tr class="oddeven">';
print '<td>'.$langs->trans("QontoEInvoicingCompleteThirdparty").'<br><span class="opacitymedium small">'.$langs->trans("QontoEInvoicingCompleteThirdpartyTooltip").'</span></td>';
print '<td>'.ajax_constantonoff('QONTOSYNC_EI_SUPP_COMPLETE_THIRDPARTY').'</td>';
print '</tr>';

print '<tr class="oddeven">';
print '<td>'.$langs->trans("QontoEInvoicingAutoProduct").'<br><span class="opacitymedium small">'.$langs->trans("QontoEInvoicingAutoProductTooltip").'</span></td>';
print '<td>'.ajax_constantonoff('QONTOSYNC_EI_SUPP_AUTO_PRODUCT', array(), null, 0, 0, 1).'</td>';
print '</tr>';

if (!getDolGlobalInt('QONTOSYNC_EI_SUPP_AUTO_PRODUCT')) {
	if (!getDolGlobalInt('MAIN_DISABLE_FREE_LINES')) {
		print '<tr class="oddeven">';
		print '<td>'.$langs->trans("QontoEInvoicingFreeLines").'<br><span class="opacitymedium small">'.$langs->trans("QontoEInvoicingFreeLinesTooltip").'</span></td>';
		print '<td>'.ajax_constantonoff('QONTOSYNC_EI_SUPP_FREE_LINES').'</td>';
		print '</tr>';
	}

	print '<tr class="oddeven">';
	print '<td>'.$langs->trans("QontoEInvoicingCatchallProduct").'<br><span class="opacitymedium small">'.$langs->trans("QontoEInvoicingCatchallProductTooltip").'</span></td>';
	print '<td>'.ajax_constantonoff('QONTOSYNC_EI_SUPP_CATCHALL_PRODUCT', array(), null, 0, 0, 1).'</td>';
	print '</tr>';

	print '<tr class="oddeven">';
	print '<td>'.$langs->trans("QontoEInvoicingCatchallProductId").'<br><span class="opacitymedium small">'.$langs->trans("QontoEInvoicingCatchallProductIdTooltip").'</span></td>';
	print '<td>';
	if (getDolGlobalInt('QONTOSYNC_EI_SUPP_CATCHALL_PRODUCT')) {
		print $form->select_produits((int) getDolGlobalInt('QONTOSYNC_EI_SUPP_CATCHALL_PRODUCT_ID'), 'QONTOSYNC_EI_SUPP_CATCHALL_PRODUCT_ID', '', 0, 0, -1, 2, '', 0, array(), 0, '1', 0, 'minwidth300 maxwidth500', 0, '', null, 1, 1);
		print ' <input type="submit" name="save" class="button smallpaddingimp valignmiddle" value="'.$langs->trans("Save").'">';
	} else {
		print '<span class="opacitymedium">'.$langs->trans("QontoEInvoicingCatchallProductLocked").'</span>';
	}
	print '</td>';
	print '</tr>';
}

print '<tr class="oddeven">';
print '<td>'.$langs->trans("QontoEInvoicingAutoImport").'<br><span class="opacitymedium small">'.$langs->trans("QontoEInvoicingAutoImportTooltip").'</span></td>';
print '<td>'.ajax_constantonoff('QONTOSYNC_EI_SUPP_AUTO_IMPORT').'</td>';
print '</tr>';
print '<tr class="oddeven">';
print '<td>'.$langs->trans("QontoEInvoicingAutoValidate").'<br><span class="opacitymedium small">'.$langs->trans("QontoEInvoicingAutoValidateTooltip").'</span></td>';
print '<td>'.ajax_constantonoff('QONTOSYNC_EI_SUPP_AUTO_VALIDATE').'</td>';
print '</tr>';

// CUSTOMERS PARAMETERS
print '<tr class="liste_titre"><td colspan="2">'.$langs->trans("QontoCustomersParam").'</td></tr>';

print '<tr class="oddeven">';
print '<td>'.$langs->trans("QontoEInvoicingAutoExport").'<br><span class="opacitymedium small">'.$langs->trans("QontoEInvoicingAutoExportTooltip").'</span></td>';
print '<td>'.ajax_constantonoff('QONTOSYNC_EI_CUST_AUTO_EXPORT').'</td>';
print '</tr>';
print '<tr class="oddeven">';
print '<td>'.$langs->trans("QontoEInvoicingAutoUpdate").'<br><span class="opacitymedium small">'.$langs->trans("QontoEInvoicingAutoUpdateTooltip").'</span></td>';
print '<td>'.ajax_constantonoff('QONTOSYNC_EI_CUST_AUTO_UPDATE').'</td>';
print '</tr>';

print '</table>';

print '</form>';

print dol_get_fiche_end();

llxFooter();