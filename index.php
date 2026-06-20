<?php
/*
 * QontoSync dolibarr module 
 * @file       index.php
 * @author     Gaëtan COURTOIS
 * @license    GPLv3
 */

$res = 0;
if (! $res && file_exists("../../main.inc.php")) $res = @include "../../main.inc.php";
if (! $res && file_exists("../../../main.inc.php")) $res = @include "../../../main.inc.php";
if (! $res && file_exists("../../../../main.inc.php")) $res = @include "../../../../main.inc.php";
if (! $res && preg_match('/\/custom\//', $_SERVER["PHP_SELF"])) $res = @include "../../../../main.inc.php";

// Security check
if ($user->socid > 0) {
	accessforbidden();
}

if (!$user->hasRight('qontosync', 'read')) {
    accessforbidden();
}

$langs->loadLangs(array("qontosync@qontosync", "banks", "bills", "trips", "compta"));

// Load javascript files
$arrayofjs = array(
    '/qontosync/js/ajax_search.js' 
);

llxHeader('', $langs->trans("QontoSync"), '', '', 0, 0, $arrayofjs);

print load_fiche_titre($langs->trans("QontoSync"), '', 'object_qonto.svg@qontosync');

// Prepare variables for view
$action = GETPOST('action', 'alpha');
$bank_rowid = GETPOST('bank_rowid','int');
$month = GETPOST('month', 'int') ? GETPOST('month', 'int') : date('m');
$year = GETPOST('year', 'int') ? GETPOST('year', 'int') : date('Y');

if (!class_exists('Form')) require_once DOL_DOCUMENT_ROOT.'/core/class/html.form.class.php';
$form = new Form($db);
$bank_rowid = $bank_rowid > 0 ? $bank_rowid : '';
$select_comptes_html = $form->select_comptes($bank_rowid, 'bank_rowid', 0, '', 2, '', 0, '', 1);

// Include the search form view
include dol_buildpath('/qontosync/core/tpl/search.tpl.php');

llxFooter();