<?php
/*
 * QontoSync dolibarr module 
 * @file       advanced_match.php
 * @author     Gaëtan COURTOIS
 * @license    GPLv3
 */

if (!defined('NOTOKENRENEWAL')) define('NOTOKENRENEWAL', '1');
if (!defined('NOREQUIREAJAX'))  define('NOREQUIREAJAX', '1');

/** NO INCEPTION SCRIPT **/
ob_start();
$session_is_valid = false;
register_shutdown_function(function() use (&$session_is_valid) {
    if (!$session_is_valid) {
        if (ob_get_length()) {
            ob_end_clean();
        }
        
        echo '<script type="text/javascript">window.parent.location.reload();</script>';
        exit;
    }
});
/*************************/

$res = 0;
if (! $res && file_exists("../../main.inc.php")) $res = @include "../../main.inc.php";
if (! $res && file_exists("../../../main.inc.php")) $res = @include "../../../main.inc.php";
if (! $res && file_exists("../../../../main.inc.php")) $res = @include "../../../../main.inc.php";
if (! $res && preg_match('/\/custom\//', $_SERVER["PHP_SELF"])) $res = @include "../../../../main.inc.php";

/** NO INCEPTION SCRIPT **/
$session_is_valid = true;
ob_end_clean();
/*************************/

// Security check
if (GETPOST('token', 'alpha') !== currentToken() || $user->socid > 0 || !$user->hasRight('qontosync', 'read')) {
    echo '<script type="text/javascript">window.parent.location.reload();</script>';
    exit;
}

$langs->loadLangs(array("qontosync@qontosync", "banks", "bills", "compta", "salaries"));

$conf->dol_hide_topmenu = 1;
$conf->dol_hide_leftmenu = 1;

$arrayofjs = array(
    '/qontosync/js/advanced_matching.js',
    '/qontosync/js/fifo.js',
    '/qontosync/js/ajax_advanced.js'
);

llxHeader('', $langs->trans("AdvancedSearch"), '', '', 0, 0, $arrayofjs);


$qonto_id = GETPOST('qonto_id', 'alphanohtml');
$bank_rowid = GETPOST('bank_rowid', 'int');

// Multi-company security segregation (Bank account entity verification)
if (!class_exists('QontoRepository')) dol_include_once('/qontosync/class/qontorepository.class.php');
$qontoRepo = new QontoRepository($db);

if ($bank_rowid <= 0 || !$qontoRepo->isAccountAllowed($bank_rowid)) {
    http_response_code(403);
    echo "Permission Denied";
    exit;
}

// If no transaction ID
if (empty($qonto_id)) {
    print "<div class='error'>ID de transaction manquant.</div>";
    llxFooter();
    exit;
}

// Try to get transaction informations
if (!class_exists('QontoApi')) dol_include_once('/qontosync/class/qontoapi.class.php');
$api = new QontoApi();
$raw_trx = $api->getTransaction($qonto_id);

// If transaction does not exist
if (empty($raw_trx)) {
    print "<div class='error'>Impossible de récupérer les détails de la transaction depuis Qonto.</div>";
    llxFooter();
    exit;
}

// Calculate the real Qonto amount with its sign
$processed_trx = $raw_trx;
$processed_trx['amount'] = (float)$raw_trx['amount'];
if (isset($processed_trx['side']) && $processed_trx['side'] == 'debit') {
    $processed_trx['amount'] = -$processed_trx['amount'];
}

// Verify if the transaction is linked with a bank entrie
if (!class_exists('QontoMatching')) dol_include_once('/qontosync/class/qontomatching.class.php');
$qontoMatching = new QontoMatching($db);
$linked = $qontoMatching->isLinked($qonto_id, $bank_rowid, false); 

// Single instantiation of the Repository
if (!class_exists('QontoRepository')) dol_include_once('/qontosync/class/qontorepository.class.php');
$qontoRepo = new QontoRepository($db);

$filtered_documents = array();
$linked_details = array();

if ($linked > 0) {
    // If the transaction is already linked, we will retrieve the details (Payments -> Invoices)
    $linked_details = $qontoRepo->getLinkedDetails($linked);
} elseif ($linked === 0) {
    // Get unpaid documents
    $filtered_documents = $qontoRepo->getUnpaidDocuments('all');
}


// Get payment label
$op_type = isset($processed_trx['operation_type']) ? $processed_trx['operation_type'] : '';
$mop = $qontoMatching->getPaymentModeId($op_type, isset($processed_trx['side']) ? $processed_trx['side'] : '');
$payment_label = '';
if (!empty($mop)) {
    $payment_code = $langs->getLabelFromKey($db, $mop, 'c_paiement', 'id', 'code');
    $payment_label = $langs->trans("PaymentTypeShort" . $payment_code);
    if ($payment_label == "PaymentTypeShort" . $payment_code) {
        $payment_label = $langs->getLabelFromKey($db, $mop, 'c_paiement', 'id', 'libelle');
    }
}

// Call the view
include dol_buildpath('/qontosync/core/tpl/advanced_modal.tpl.php');

llxFooter();