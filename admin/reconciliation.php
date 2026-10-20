<?php
/*
 * QontoSync dolibarr module 
 * @file       admin/reconciliation.php
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

$langs->loadLangs(array("admin", "qontosync@qontosync"));

// Check if user is admin
if (!$user->admin) {
	accessforbidden();
}

$form = new Form($db);

$action = GETPOST('action', 'aZ');

if (GETPOST('save', 'alpha')) { $action = 'update'; }

$pm_transfer = GETPOST('QONTOSYNC_PM_TRANSFER', 'int');
$pm_card = GETPOST('QONTOSYNC_PM_CARD', 'int');
$pm_dd = GETPOST('QONTOSYNC_PM_DD', 'int');
$pm_check = GETPOST('QONTOSYNC_PM_CHECK', 'int');
$tolerance_days = GETPOST('QONTOSYNC_TOLERANCE_DAYS', 'int');
$max_months = GETPOST('QONTOSYNC_MAX_MONTHS', 'int');
$statement_format = GETPOST('QONTOSYNC_STATEMENT_FORMAT', 'alphanohtml');

$badge_color = GETPOST('QONTOSYNC_BADGE_COLOR', 'alphanohtml');
$badge_color_dark = GETPOST('QONTOSYNC_BADGE_COLOR_DARK', 'alphanohtml');

// Update constants
if ($action == 'update') {
	// Check CSRF token
	if (GETPOST('token', 'alpha') !== currentToken()) {
		setEventMessages($langs->trans("InvalidToken"), null, 'errors');
		$action = '';
	} else {
		dolibarr_set_const($db, 'QONTOSYNC_PM_TRANSFER', $pm_transfer, 'chaine', 0, '', $conf->entity);
		dolibarr_set_const($db, 'QONTOSYNC_PM_CARD', $pm_card, 'chaine', 0, '', $conf->entity);
		dolibarr_set_const($db, 'QONTOSYNC_PM_DD', $pm_dd, 'chaine', 0, '', $conf->entity);
		dolibarr_set_const($db, 'QONTOSYNC_PM_CHECK', $pm_check, 'chaine', 0, '', $conf->entity);
		
		dolibarr_set_const($db, 'QONTOSYNC_TOLERANCE_DAYS', $tolerance_days, 'chaine', 0, '', $conf->entity);
		dolibarr_set_const($db, 'QONTOSYNC_MAX_MONTHS', $max_months, 'chaine', 0, '', $conf->entity);
		dolibarr_set_const($db, 'QONTOSYNC_STATEMENT_FORMAT', $statement_format, 'chaine', 0, '', $conf->entity);

		dolibarr_set_const($db, 'QONTOSYNC_BADGE_COLOR', $badge_color, 'chaine', 0, '', $conf->entity);
		dolibarr_set_const($db, 'QONTOSYNC_BADGE_COLOR_DARK', $badge_color_dark, 'chaine', 0, '', $conf->entity);

		setEventMessages($langs->trans("SetupSaved"), null, 'mesgs');
	}
}

$page_name = "QontoSyncSetup";
llxHeader('', $langs->trans("QontoSyncSetup"));

$linkback = '<a href="'.DOL_URL_ROOT.'/admin/modules.php?restore_lastsearch_values=1">'.$langs->trans("BackToModuleList").'</a>';
print load_fiche_titre($langs->trans("QontoSyncSetup"), $linkback, 'title_setup');

$head = qontosyncAdminPrepareHead();

print dol_get_fiche_head(
    $head,
    'reconciliation',
    'QontoSync',
    -1,
    'bank'
);

print '<form method="post" action="'.dol_escape_htmltag($_SERVER["PHP_SELF"]).'">';
print '<input type="hidden" name="token" value="'.newToken().'">';
print '<input type="hidden" name="action" value="update">';

print '<table class="noborder centpercent">';

/**
 * Payments parameters
 */
print '<tr class="liste_titre"><td colspan="2">'.$langs->trans("QontoPaymentsMethodsCorrespondences").'</td></tr>';

print '<tr class="oddeven">';
print '<td>'.$langs->trans("QontoPMTransfer").'</td>';
print '<td>' . $form->select_types_paiements(getDolGlobalString('QONTOSYNC_PM_TRANSFER'), 'QONTOSYNC_PM_TRANSFER', '', 0, 1, 0, 0, 1, 'minwidth200 flat', 1) . '</td>';
print '</tr>';

print '<tr class="oddeven">';
print '<td>'.$langs->trans("QontoPMCard").'</td>';
print '<td>' . $form->select_types_paiements(getDolGlobalString('QONTOSYNC_PM_CARD'), 'QONTOSYNC_PM_CARD', '', 0, 1, 0, 0, 1, 'minwidth200 flat', 1) . '</td>';
print '</tr>';

print '<tr class="oddeven">';
print '<td>'.$langs->trans("QontoPMDD").'</td>';
print '<td>' . $form->select_types_paiements(getDolGlobalString('QONTOSYNC_PM_DD'), 'QONTOSYNC_PM_DD', '', 0, 1, 0, 0, 1, 'minwidth200 flat', 1) . '</td>';
print '</tr>';

print '<tr class="oddeven">';
print '<td>'.$langs->trans("QontoPMCheck").'</td>';
print '<td>' . $form->select_types_paiements(getDolGlobalString('QONTOSYNC_PM_CHECK'), 'QONTOSYNC_PM_CHECK', '', 0, 1, 0, 0, 1, 'minwidth200 flat', 1) . '</td>';
print '</tr>';

/**
 * Bank statement format
 */
print '<tr class="liste_titre"><td colspan="2">'.$langs->trans("QontoBankStatementFormat").'</td></tr>';

print '<tr class="oddeven">';
print '<td>'.$langs->trans("QontoStatementFormat").'<br><span class="opacitymedium small">'.$langs->trans("QontoStatementFormatTooltip").'</span></td>';
$val_statement_format = getDolGlobalString('QONTOSYNC_STATEMENT_FORMAT') !== '' ? getDolGlobalString('QONTOSYNC_STATEMENT_FORMAT') : '{Y}-{m}';
print '<td><input type="text" size="60" name="QONTOSYNC_STATEMENT_FORMAT" value="'.dol_escape_htmltag($val_statement_format).'"></td>';
print '</tr>';

/**
 * Correspondence parameters
 */
print '<tr class="liste_titre"><td colspan="2">'.$langs->trans("QontoTXCorrespondences").'</td></tr>';

print '<tr class="oddeven">';
print '<td>'.$langs->trans("QontoToleranceDays").'<br><span class="opacitymedium small">'.$langs->trans("QontoToleranceDaysTooltip").'</span></td>';
// if empty, set 7 by default
$val_tolerance = getDolGlobalString('QONTOSYNC_TOLERANCE_DAYS') !== '' ? getDolGlobalString('QONTOSYNC_TOLERANCE_DAYS') : '7';
print '<td><input type="number" min="0" max="365" size="5" name="QONTOSYNC_TOLERANCE_DAYS" value="'.$val_tolerance.'"></td>';
print '</tr>';
print '<tr class="oddeven">';
print '<td>'.$langs->trans("QontoMaxMonths").'<br><span class="opacitymedium small">'.$langs->trans("QontoMaxMonthsTooltip").'</span></td>';
// if empty, set 24 by default
$val_max_months = getDolGlobalString('QONTOSYNC_MAX_MONTHS') !== '' ? getDolGlobalString('QONTOSYNC_MAX_MONTHS') : '24';
print '<td><input type="number" min="1" max="120" size="5" name="QONTOSYNC_MAX_MONTHS" value="'.$val_max_months.'"></td>';
print '</tr>';

/**
 * GUI parameters
 */
print '<tr class="liste_titre"><td colspan="2">'.$langs->trans("QontoView").'</td></tr>';

print '<tr class="oddeven">';
print '<td>'.$langs->trans("QontoReferenceBadgeColor").'</td>';
$val_badge_color = getDolGlobalString('QONTOSYNC_BADGE_COLOR') !== '' ? getDolGlobalString('QONTOSYNC_BADGE_COLOR') : '#1e40af';
print '<td><input type="color" name="QONTOSYNC_BADGE_COLOR" value="'.dol_escape_htmltag($val_badge_color).'"></td>';
print '</tr>';

print '<tr class="oddeven">';
print '<td>'.$langs->trans("QontoReferenceBadgeColor").' (' . $langs->trans("QontoDarkThemeMode") . ')</td>';
$val_badge_color_dark = getDolGlobalString('QONTOSYNC_BADGE_COLOR_DARK') !== '' ? getDolGlobalString('QONTOSYNC_BADGE_COLOR_DARK') : '#1e3a8a';
print '<td><input type="color" name="QONTOSYNC_BADGE_COLOR_DARK" value="'.dol_escape_htmltag($val_badge_color_dark).'"></td>';
print '</tr>';

print '</table>';

print '<div class="center"><br>';
print '<input type="submit" name="save" class="button" value="'.$langs->trans("Save").'">';
print '</div>';

print '</form>';

print dol_get_fiche_end();

llxFooter();