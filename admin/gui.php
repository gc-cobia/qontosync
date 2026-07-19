<?php
/*
 * QontoSync dolibarr module 
 * @file       admin/gui.php
 * @author     Gaëtan COURTOIS
 * @license    GPLv3
 */

$res = 0;
if (! $res && file_exists("../../main.inc.php")) $res = @include "../../main.inc.php";
if (! $res && file_exists("../../../main.inc.php")) $res = @include "../../../main.inc.php";
if (! $res && file_exists("../../../../main.inc.php")) $res = @include "../../../../main.inc.php";
if (! $res && preg_match('/\/custom\//', $_SERVER["PHP_SELF"])) $res = @include "../../../../main.inc.php";

if (!function_exists('dolibarr_set_const')) require_once DOL_DOCUMENT_ROOT.'/core/lib/admin.lib.php';
if (!function_exists('dolEncrypt')) require_once DOL_DOCUMENT_ROOT.'/core/lib/security.lib.php';
if (!function_exists('qontosyncAdminPrepareHead')) dol_include_once('/qontosync/lib/qontosync.lib.php');
if (!class_exists('Form')) require_once DOL_DOCUMENT_ROOT.'/core/class/html.form.class.php';
if (!class_exists('QontoApi')) dol_include_once('/qontosync/class/qontoapi.class.php');

$langs->loadLangs(array("admin", "qontosync@qontosync"));

// Check if user is admin
if (!$user->admin) {
	accessforbidden();
}

$action = GETPOST('action', 'aZ');

if (GETPOST('save', 'alpha')) { $action = 'update'; }

$badge_color = GETPOST('QONTOSYNC_BADGE_COLOR', 'alphanohtml');
$badge_color_dark = GETPOST('QONTOSYNC_BADGE_COLOR_DARK', 'alphanohtml');

// Update constants
if ($action == 'update') {
	// Check CSRF token
	if (GETPOST('token', 'alpha') !== currentToken()) {
		setEventMessages($langs->trans("InvalidToken"), null, 'errors');
		$action = '';
	} else {
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
    'gui',
    'QontoSync',
    -1,
    'bank'
);

print '<form method="post" action="'.dol_escape_htmltag($_SERVER["PHP_SELF"]).'">';
print '<input type="hidden" name="token" value="'.newToken().'">';
print '<input type="hidden" name="action" value="update">';

print '<table class="noborder centpercent">';

/**
 * GUI parameters
 */
print '<tr class="liste_titre"><td colspan="2">'.$langs->trans("Color").'</td></tr>';

print '<tr class="oddeven">';
print '<td>'.$langs->trans("ReferenceBadgeColor").'</td>';
$val_badge_color = getDolGlobalString('QONTOSYNC_BADGE_COLOR') !== '' ? getDolGlobalString('QONTOSYNC_BADGE_COLOR') : '#1e40af';
print '<td><input type="color" name="QONTOSYNC_BADGE_COLOR" value="'.dol_escape_htmltag($val_badge_color).'"></td>';
print '</tr>';

print '<tr class="oddeven">';
print '<td>'.$langs->trans("ReferenceBadgeColor").' (' . $langs->trans("DarkThemeMode") . ')</td>';
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