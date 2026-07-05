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

if ($user->socid > 0 || !$user->hasRight('qontosync', 'write')) {
    http_response_code(403);
    echo "Permission Denied";
    exit;
}

$langs->loadLangs(array("qontosync@qontosync", "banks"));

$action = GETPOST('action', 'alpha');
$bank_rowid = GETPOST('bank_rowid', 'int');
$year = GETPOST('year', 'int');

// Multi-company security segregation (Bank account entity verification)
if (!class_exists('QontoRepository')) dol_include_once('/qontosync/class/qontorepository.class.php');
$qontoRepo = new QontoRepository($db);

if ($bank_rowid <= 0 || !$qontoRepo->isAccountAllowed($bank_rowid)) {
    http_response_code(403);
    echo "Permission Denied";
    exit;
}

if ($action == 'sync_ged' && $bank_rowid > 0 && $year > 0) {
    
    if (!class_exists('Account')) require_once DOL_DOCUMENT_ROOT .'/compta/bank/class/account.class.php';
    $account = new Account($db);
    if ($account->fetch($bank_rowid) <= 0) {
        http_response_code(400);
        echo "Bank account not found";
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
        $msg = $langs->trans("FileTransferComplete") . ' (' . $sync_result['success_count'] . ')';
        if ($sync_result['error_count'] > 0) {
            $msg .= ' ' . $langs->trans("ErrorFailedToSaveFile") . ' (' . $sync_result['error_count'] . ')';
            setEventMessages($msg, null, 'warnings');
        } else {
            setEventMessages($msg, null, 'mesgs');
        }
    } else {
        setEventMessages("Completed", null, 'mesgs');
    }

    // Respond to JS
    print dol_htmloutput_events();
    exit;
}

http_response_code(400);
echo "Bad Request";
exit;