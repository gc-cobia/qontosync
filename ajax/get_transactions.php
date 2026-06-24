<?php
/*
 * QontoSync dolibarr module 
 * @file       ajax/get_transactions.php
 * @author     Gaëtan COURTOIS
 * @license    GPLv3
 */
 
if (!defined('NOTOKENRENEWAL')) define('NOTOKENRENEWAL', '1');
if (!defined('NOREQUIREMENU'))  define('NOREQUIREMENU', '1');
if (!defined('NOREQUIREAJAX'))  define('NOREQUIREAJAX', '1');

/** NO INCEPTION SCRIPT **/
ob_start();
$session_is_valid = false;
register_shutdown_function(function() use (&$session_is_valid) {
    if (!$session_is_valid) {
        if (ob_get_length()) {
            ob_end_clean();
        }
        http_response_code(401);
        echo "SESSION_EXPIRED";
        exit;
    }
});
/*************************/

$res = 0;
if (! $res && file_exists("../../../main.inc.php")) $res = @include "../../../main.inc.php";
if (! $res && file_exists("../../../../main.inc.php")) $res = @include "../../../../main.inc.php";

/** NO INCEPTION SCRIPT **/
$session_is_valid = true;
ob_end_clean();
/*************************/

// Security check
if (GETPOST('token', 'alpha') !== currentToken()) {
    http_response_code(401);
    echo "Invalid Token";
    exit;
}

if ($user->socid > 0 || !$user->hasRight('qontosync', 'read')) {
    http_response_code(403);
    echo "Permission Denied";
    exit;
}

$langs->loadLangs(array("qontosync@qontosync", "banks", "bills", "compta"));

$bank_rowid = GETPOST('bank_rowid', 'int');
$month = GETPOST('month', 'int');
$year = GETPOST('year', 'int');

if (empty($bank_rowid) || $bank_rowid <= 0) {
    print '<div  class="info">' . $langs->trans("NoBankSelected") . '</div>';
    exit;
}

// Fetching the bank rowid
if (!class_exists('Account')) require_once DOL_DOCUMENT_ROOT .'/compta/bank/class/account.class.php';
$account = new Account($db);
$account->fetch($bank_rowid);

$dateStart = sprintf("%04d-%02d-01", $year, $month);
$dateEnd = date("Y-m-t", strtotime($dateStart));

// Single API call for both the link processing and the table display
if (!class_exists('QontoApi')) dol_include_once('/qontosync/class/qontoapi.class.php');
$api = new QontoApi();
$transactions = $api->getTransactions($account->iban, $dateStart, $dateEnd);

if ($transactions === false) {
    print '<div class="error">' . $langs->trans("ErrorFetchingTransactions") . '</div>';
} else if (empty($transactions)) {
    print '<div class="warning">' . $langs->trans("NoTransactionsFound") . '</div>';
} else {
    // Load documents fetching Qonto transactions
    if (!class_exists('QontoMatching')) dol_include_once('/qontosync/class/qontomatching.class.php');
    $qontoMatching = new QontoMatching($db);
    $transactions = $qontoMatching->fetchTransactions($transactions, $bank_rowid);

    // Rendering the results
    include dol_buildpath('/qontosync/core/tpl/results_table.tpl.php');
}