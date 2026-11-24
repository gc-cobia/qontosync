<?php
/*
 * QontoSync dolibarr module
 * @file       supplier_invoices.php
 * @author     Gaëtan COURTOIS
 * @license    GPLv3
 */

$res = 0;
if (! $res && file_exists("../../main.inc.php")) $res = @include "../../main.inc.php";
if (! $res && file_exists("../../../main.inc.php")) $res = @include "../../../main.inc.php";
if (! $res && file_exists("../../../../main.inc.php")) $res = @include "../../../../main.inc.php";
if (! $res && preg_match('/\/custom\//', $_SERVER["PHP_SELF"])) $res = @include "../../../../main.inc.php";

// Security check
if ($user->socid > 0 || !$user->hasRight('qontosync', 'supplier_invoices', 'read')) {
    accessforbidden();
}

$langs->loadLangs(array("qontosync@qontosync", "einvoicing@einvoicing", "main", "bills", "suppliers", "companies", "errors"));

$eiEnabled = (bool) getDolGlobalInt('QONTOSYNC_EI_ENABLED');
$einvoicingModEnabled = isModEnabled('einvoicing');

// Load javascript files only when e-invoicing is enabled
$arrayofjs = $eiEnabled ? array('/qontosync/js/supplier_invoices.js') : array();

$title = "QontoSync - " . $langs->trans("SupplierInvoices");
llxHeader('', $title, '', '', 0, 0, $arrayofjs);

print load_fiche_titre($title, '', 'object_qonto.svg@qontosync');

if (!$eiEnabled) {
    print '<div class="error" style="margin-bottom: 15px; padding: 10px;">' . $langs->trans("QontoEInvoicingNotEnabled") . '</div>';
} else {
    include dol_buildpath('/qontosync/tpl/supplier_invoices.tpl.php');
}

llxFooter();
