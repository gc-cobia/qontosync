<?php
/*
 * QontoSync dolibarr module 
 * @file       ajax/create_various.php
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

$langs->loadLangs(array("qontosync@qontosync", "banks", "bills", "compta", "accountancy"));

$qonto_id = GETPOST('qonto_id', 'alphanohtml');
$bank_rowid = GETPOST('bank_rowid', 'int');
$accountancy_code = GETPOST('accountancy_code', 'alphanohtml');

// Multi-company security segregation (Bank account entity verification / Anti-IDOR)
if (!class_exists('QontoRepository')) dol_include_once('/qontosync/class/qontorepository.class.php');
$qontoRepo = new QontoRepository($db);

if (empty($bank_rowid) || !$qontoRepo->isAccountAllowed($bank_rowid)) {
    http_response_code(403);
    echo json_encode(array('status' => 'error', 'message' => 'Permission Denied', 'type' => 'errors'));
    exit;
}

if (empty($qonto_id)) {
    http_response_code(400);
    echo json_encode(array('status' => 'error', 'message' => 'Bad Request: missing qonto_id', 'type' => 'errors'));
    exit;
}

if (!class_exists('QontoUpdater')) dol_include_once('/qontosync/class/qontoupdater.class.php');
$qontoUpdater = new QontoUpdater($db);

$res = $qontoUpdater->createVariousPayment($qonto_id, $bank_rowid, $accountancy_code);

if ($res > 0) {
    echo json_encode(array(
        'status' => 'success',
        'message' => $langs->transnoentities("TransactionSuccessfullyLinked"),
        'type' => 'mesgs'
    ));
} else {
    echo json_encode(array(
        'status' => 'error',
        'message' => $langs->transnoentities("FailedToLinkTransaction"),
        'type' => 'errors'
    ));
}
exit;
