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

$langs->loadLangs(array("qontosync@qontosync"));

$action = GETPOST('action', 'alpha');
$bank_rowid = GETPOST('bank_rowid', 'int');
$target_vals = GETPOST('target_val', 'array');

// Multi-company security segregation (Bank account entity verification)
if (!class_exists('QontoRepository')) dol_include_once('/qontosync/class/qontorepository.class.php');
$qontoRepo = new QontoRepository($db);

if ($bank_rowid > 0 && !$qontoRepo->isAccountAllowed($bank_rowid)) {
    http_response_code(403);
    echo json_encode(array('status' => 'error', 'message' => 'Permission Denied', 'type' => 'errors'));
    exit;
}

if ($action == 'batch_link' && is_array($target_vals) && $bank_rowid > 0) {
    
    if (!class_exists('QontoApi')) dol_include_once('/qontosync/class/qontoapi.class.php');
    if (!class_exists('QontoUpdater')) dol_include_once('/qontosync/class/qontoupdater.class.php');
    
    $api = new QontoApi();
    $qontoUpdater = new QontoUpdater($db);
    
    $success_count = 0;
    $error_count = 0;

    foreach ($target_vals as $qonto_id => $target) {
        
        // Keep the dashes of the Qonto ID
        $qonto_id = sanitizeVal($qonto_id, 'alphanohtml');

        if (empty($target)) continue;
        
        // Convert the string (e.g. "cinv_123") to an array using explode!
        if (count($parts = explode('_', strip_tags($target), 2)) != 2) {
            $error_count++;
            continue;
        }
        $type = sanitizeVal($parts[0], 'alpha');
        $doc_id = (int)$parts[1];

        // Fetch the transaction from Qonto
        $selected_trx = $api->getTransaction($qonto_id);

        if ($selected_trx) {
            
            // Retrieve the amount
    		$amount = (float)$selected_trx['amount'];
    		
    		// Calculate the real amount with its sign
    		if (isset($selected_trx['side']) && $selected_trx['side'] == 'debit') {
    			$amount = -$amount;
    		}
    		
			// Adapt sign to the document type
	    	$amount = ($type === 'sinv' || $type === 'vat' || $type === 'social' || $type === 'salary' || $type === 'expense') ? -$amount : $amount;
            
            // Build the array for the updater
            $amounts_array = array($doc_id => $amount);
            
            // Send the array to update()
            $res = $qontoUpdater->update($type, $amounts_array, $selected_trx, $bank_rowid);
            
            if ($res > 0) $success_count++;
            else $error_count++;
            
        } else {
            $error_count++;
        }
    }

    // Return JSON response
    if ($success_count > 0 || $error_count > 0) {
        $msg = $langs->transnoentities("BatchProcessingCompleted", $success_count, $error_count);
        $type = ($error_count > 0) ? 'warnings' : 'mesgs';
        $status = ($error_count > 0 && $success_count == 0) ? 'error' : 'success';
        echo json_encode(array(
            'status' => $status,
            'message' => $msg,
            'type' => $type
        ));
    } else {
        echo json_encode(array(
            'status' => 'success',
            'message' => $langs->transnoentities("NoTransactionToLink"),
            'type' => 'warnings'
        ));
    }
    exit;
}

http_response_code(400);
echo json_encode(array('status' => 'error', 'message' => 'Bad Request', 'type' => 'errors'));
exit;