<?php
/*
 * QontoSync dolibarr module 
 * @file       statements.php
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

$langs->loadLangs(array("qontosync@qontosync", "banks", "compta", "main"));

// Load javascript files
$arrayofjs = array(
    '/qontosync/js/statements.js' 
);

llxHeader('', $langs->trans("QontoSync"), '', '', 0, 0, $arrayofjs);

print load_fiche_titre($langs->trans("QontoSync"), '', 'object_qonto.svg@qontosync');

// Prepare variables for view
$action = GETPOST('action', 'alpha');
$bank_rowid = GETPOST('bank_rowid','int');
$year = GETPOST('year', 'int') ? GETPOST('year', 'int') : date('Y');

if (!class_exists('Form')) require_once DOL_DOCUMENT_ROOT.'/core/class/html.form.class.php';
$form = new Form($db);
$bank_rowid = $bank_rowid > 0 ? $bank_rowid : '';
$select_comptes_html = $form->select_comptes($bank_rowid, 'bank_rowid', 0, '', 2, '', 0, '', 1);

// Prepare HTML select list for years
$array_years = array();
for ($i = date('Y') - 5; $i <= date('Y'); $i++) {
    $array_years[$i] = $i;
}
$select_year_html = $form->selectarray('year', $array_years, $year, 0, 0, 0, 'flat marginrightonly');

// Include HTML5 JS templates and view
include dol_buildpath('/qontosync/tpl/statements.tpl.php');

llxFooter();