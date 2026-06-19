<?php
/*
 * QontoSync dolibarr module 
 * @file       ajax/update_batch.php
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
    http_response_code(403);
    echo "Invalid Token";
    exit;
}

if ($user->socid > 0) {
    http_response_code(403);
    echo "External users not allowed";
    exit;
}

if (!$user->hasRight('qontosync', 'write')) {
    http_response_code(403);
    echo "Permission Denied";
    exit;
}

$langs->loadLangs(array("qontosync@qontosync"));

$action = GETPOST('action', 'alpha');
$bank_rowid = GETPOST('bank_rowid', 'int');
$target_vals = GETPOST('target_val', 'array');

if ($action == 'batch_link' && is_array($target_vals) && $bank_rowid > 0) {
    
    if (!class_exists('QontoApi')) dol_include_once('/qontosync/class/qontoapi.class.php');
    $qontoUpdater = new QontoUpdater($db);
    
    if (!class_exists('QontoUpdater')) dol_include_once('/qontosync/class/qontoupdater.class.php');
    $api = new QontoApi();
    
    $success_count = 0;
    $error_count = 0;

    foreach ($target_vals as $qonto_id => $target) {
        $qonto_id = sanitizeVal($qonto_id, 'alphanohtml');
        $target = sanitizeVal($target, 'alphanohtml');

        if (empty($target)) continue;

        // We need to retrieve the transaction details for the update
        $selected_trx = $api->getTransaction($qonto_id);

        if ($selected_trx) {
            $res = $qontoUpdater->update($target, $selected_trx, $bank_rowid);
            if ($res > 0) $success_count++;
            else $error_count++;
        } else {
            $error_count++;
        }
    }

    // Prepare notification messages for the next display
    if ($success_count > 0 || $error_count > 0) {
        $msg = $langs->trans("BatchProcessingCompleted", $success_count, $error_count);
        $type = ($error_count > 0) ? 'warnings' : 'mesgs';
        setEventMessages($msg, null, $type);
    } else {
        setEventMessages($langs->trans("NoTransactionToLink"), null, 'warnings');
    }

    // Respond to JS that everything went well
    echo "OK";
    exit;
}

http_response_code(400);
echo "Bad Request";
exit;