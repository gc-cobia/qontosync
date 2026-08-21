<?php
/*
 * QontoSync dolibarr module 
 * @file       reconciliation.php
 * @author     Gaëtan COURTOIS
 * @license    GPLv3
 */

$res = 0;
if (! $res && file_exists("../../main.inc.php")) $res = @include "../../main.inc.php";
if (! $res && file_exists("../../../main.inc.php")) $res = @include "../../../main.inc.php";
if (! $res && file_exists("../../../../main.inc.php")) $res = @include "../../../../main.inc.php";
if (! $res && preg_match('/\/custom\//', $_SERVER["PHP_SELF"])) $res = @include "../../../../main.inc.php";

// Security check
if ($user->socid > 0 || !$user->hasRight('qontosync', 'read')) {
    accessforbidden();
}

$langs->loadLangs(array("qontosync@qontosync", "banks", "bills", "trips", "compta"));

// Load javascript files
$arrayofjs = array(
    '/qontosync/js/reconciliation.js',
    '/qontosync/js/hamburger.js',
    '/qontosync/js/batch_matching.js',
    '/qontosync/js/advanced_matching.js',
    '/qontosync/js/fifo.js'
);

llxHeader('', $langs->trans("QontoSync"), '', '', 0, 0, $arrayofjs);

print load_fiche_titre($langs->trans("QontoSync"), '', 'object_qonto.svg@qontosync');

// Prepare variables for view
$action = GETPOST('action', 'alpha');
$bank_rowid = GETPOST('bank_rowid','int');
$month = GETPOST('month', 'int') ? GETPOST('month', 'int') : date('m');
$year = GETPOST('year', 'int') ? GETPOST('year', 'int') : date('Y');

// Prepare HTML select list for bank accounts
if (!class_exists('Form')) require_once DOL_DOCUMENT_ROOT.'/core/class/html.form.class.php';
$form = new Form($db);
$bank_rowid = $bank_rowid > 0 ? $bank_rowid : '';
$select_comptes_html = $form->select_comptes($bank_rowid, 'bank_rowid', 0, '', 2, '', 0, '', 1);

// Prepare HTML select list for months
$array_months = array();
for ($i = 1; $i <= 12; $i++) {
    $array_months[$i] = sprintf("%02d", $i);
}
$select_month_html = $form->selectarray('month', $array_months, $month, 0, 0, 0, 'flat marginrightonly');

// Prepare HTML select list for years
$array_years = array();
for ($i = date('Y') - 5; $i <= date('Y'); $i++) {
    $array_years[$i] = $i;
}
$select_year_html = $form->selectarray('year', $array_years, $year, 0, 0, 0, 'flat marginrightonly');

// Include HTML5 JS templates and modal dialogs
include dol_buildpath('/qontosync/tpl/reconciliation.tpl.php');
include dol_buildpath('/qontosync/tpl/advanced_modal.tpl.php');

llxFooter();