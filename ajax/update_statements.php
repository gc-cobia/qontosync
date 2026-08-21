<?php
/*
 * QontoSync dolibarr module 
 * @file       ajax/update_statements.php
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
        echo json_encode(array('status' => 'error', 'message' => 'SESSION_EXPIRED', 'type' => 'errors'));
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
    echo json_encode(array('status' => 'error', 'message' => 'Invalid Token', 'type' => 'errors'));
    exit;
}

if ($user->socid > 0 || !$user->hasRight('qontosync', 'write')) {
    http_response_code(403);
    echo json_encode(array('status' => 'error', 'message' => 'Permission Denied', 'type' => 'errors'));
    exit;
}

$langs->loadLangs(array("qontosync@qontosync", "banks"));

$action = GETPOST('action', 'alpha');
$bank_rowid = GETPOST('bank_rowid', 'int');
$year = GETPOST('year', 'int');

// Multi-company security segregation (Bank account entity verification)
if (!class_exists('QontoRepository')) dol_include_once('/qontosync/class/qontorepository.class.php');
$qontoRepo = new QontoRepository($db);

if ($bank_rowid > 0 && !$qontoRepo->isAccountAllowed($bank_rowid)) {
    http_response_code(403);
    echo json_encode(array('status' => 'error', 'message' => 'Permission Denied', 'type' => 'errors'));
    exit;
}

if ($action == 'sync_ged' && $bank_rowid > 0 && $year > 0) {
    
    if (!class_exists('Account')) require_once DOL_DOCUMENT_ROOT .'/compta/bank/class/account.class.php';
    $account = new Account($db);
    if ($account->fetch($bank_rowid) <= 0) {
        http_response_code(400);
        echo json_encode(array('status' => 'error', 'message' => 'Bank account not found', 'type' => 'errors'));
        exit;
    }

    if (!class_exists('QontoApi')) dol_include_once('/qontosync/class/qontoapi.class.php');
    if (!class_exists('QontoUpdater')) dol_include_once('/qontosync/class/qontoupdater.class.php');

    $api = new QontoApi();
    $qontoUpdater = new QontoUpdater($db);

    // Prepare the data
    $sync_result = $qontoUpdater->syncStatementsGED($account, $year, $api);

    // Prepare notification messages for the next display
    if ($sync_result['success_count'] > 0 || $sync_result['error_count'] > 0) {
        $msg = $langs->transnoentities("FileTransferComplete") . ' (' . $sync_result['success_count'] . ')';
        if ($sync_result['error_count'] > 0) {
            $msg .= ' ' . $langs->transnoentities("ErrorFailedToSaveFile") . ' (' . $sync_result['error_count'] . ')';
            $type = 'warnings';
            $status = ($sync_result['success_count'] == 0) ? 'error' : 'success';
        } else {
            $type = 'mesgs';
            $status = 'success';
        }
    } else {
        $msg = "Completed";
        $type = 'mesgs';
        $status = 'success';
    }

    // Respond to JS
    echo json_encode(array(
        'status' => $status,
        'message' => $msg,
        'type' => $type
    ));
    exit;
}

http_response_code(400);
echo json_encode(array('status' => 'error', 'message' => 'Bad Request', 'type' => 'errors'));
exit;