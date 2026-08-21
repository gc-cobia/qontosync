<?php
/*
 * QontoSync dolibarr module 
 * @file       ajax/get_statements.php
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
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(array('status' => 'error', 'message' => 'SESSION_EXPIRED'));
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

header('Content-Type: application/json; charset=utf-8');

// Security check
if (GETPOST('token', 'alpha') !== currentToken()) {
    http_response_code(401);
    echo json_encode(array('status' => 'error', 'message' => 'Invalid Token'));
    exit;
}

if ($user->socid > 0 || !$user->hasRight('qontosync', 'read')) {
    http_response_code(403);
    echo json_encode(array('status' => 'error', 'message' => 'Permission Denied'));
    exit;
}

$langs->loadLangs(array("qontosync@qontosync", "banks", "bills", "compta"));

$bank_rowid = GETPOST('bank_rowid', 'int');
$month = GETPOST('month', 'int');
$year = GETPOST('year', 'int');

// Multi-company security segregation (Bank account entity verification)
if (!class_exists('QontoRepository')) dol_include_once('/qontosync/class/qontorepository.class.php');
$qontoRepo = new QontoRepository($db);

if ($bank_rowid > 0 && !$qontoRepo->isAccountAllowed($bank_rowid)) {
    http_response_code(403);
    echo json_encode(array('status' => 'error', 'message' => 'Permission Denied'));
    exit;
}

if (empty($bank_rowid) || $bank_rowid <= 0) {
    echo json_encode(array(
        'status' => 'info',
        'message' => $langs->transnoentities("NoBankSelected"),
        'data' => array()
    ));
    exit;
}

// Fetching the bank rowid
if (!class_exists('Account')) require_once DOL_DOCUMENT_ROOT .'/compta/bank/class/account.class.php';
$account = new Account($db);
$account->fetch($bank_rowid);

// Load the API and the Matching service
if (!class_exists('QontoApi')) dol_include_once('/qontosync/class/qontoapi.class.php');
if (!class_exists('QontoMatching')) dol_include_once('/qontosync/class/qontomatching.class.php');

$api = new QontoApi();
$qontoMatching = new QontoMatching($db);

$dateStart = sprintf("%04d-01-01", $year);
$dateEnd   = sprintf("%04d-12-31", $year);

// Simultaneous retrieval
$transactions = $api->getTransactions($account->iban, $dateStart, $dateEnd);
$qonto_statements_raw = $api->getStatements($account->iban, $year);

if ($transactions === false) {
    echo json_encode(array(
        'status' => 'error',
        'message' => $langs->transnoentities("ErrorFetchingTransactions"),
        'data' => array()
    ));
    exit;
}

if (empty($transactions)) {
    echo json_encode(array(
        'status' => 'warning',
        'message' => $langs->transnoentities("NoTransactionsFound"),
        'data' => array()
    ));
    exit;
}

// Matching datas
$months_data = $qontoMatching->matchStatements($account, $year, $transactions, $qonto_statements_raw);

$formatted_months = array();

foreach ($months_data as $m => $data) {
    $is_future = ($year > date('Y') || ($year == date('Y') && $m > date('n')));

    $diff_open = price2num($data['qonto_opening'] - $data['dolibarr_opening'], 'MT');
    $diff_close = price2num($data['qonto_closing'] - $data['dolibarr_closing'], 'MT');

    $formatted_months[] = array(
        'month' => (int)$m,
        'label' => ucfirst($data['label']),
        'is_future' => (bool)$is_future,
        'dolibarr_opening' => price($data['dolibarr_opening']),
        'qonto_opening' => price($data['qonto_opening']),
        'diff_open' => (float)$diff_open,
        'diff_open_formatted' => price($diff_open),
        'dolibarr_closing' => price($data['dolibarr_closing']),
        'qonto_closing' => price($data['qonto_closing']),
        'diff_close' => (float)$diff_close,
        'diff_close_formatted' => price($diff_close),
        'in_dolibarr' => (bool)$data['in_dolibarr'],
        'in_qonto' => (bool)$data['in_qonto'],
        'dolibarr_url' => !empty($data['dolibarr_url']) ? $data['dolibarr_url'] : ''
    );
}

echo json_encode(array(
    'status' => 'success',
    'bank_rowid' => $bank_rowid,
    'year' => $year,
    'token' => newToken(),
    'data' => $formatted_months
));