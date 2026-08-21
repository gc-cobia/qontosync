<?php
/*
 * QontoSync dolibarr module 
 * @file       ajax/update_advanced.php
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
$qonto_id = GETPOST('qonto_id', 'alphanohtml');
$link_docs = GETPOST('link_doc', 'array');

// Multi-company security segregation (Bank account entity verification)
if (!class_exists('QontoRepository')) dol_include_once('/qontosync/class/qontorepository.class.php');
$qontoRepo = new QontoRepository($db);

if ($bank_rowid > 0 && !$qontoRepo->isAccountAllowed($bank_rowid)) {
    http_response_code(403);
    echo json_encode(array('status' => 'error', 'message' => 'Permission Denied', 'type' => 'errors'));
    exit;
}

if ($action == 'process_advanced_match') {
    
    if (empty($qonto_id) || empty($bank_rowid) || empty($link_docs) || !is_array($link_docs)) {
        echo json_encode(array(
            'status' => 'error',
            'message' => $langs->transnoentities("NoTransactionToLink"),
            'type' => 'warnings'
        ));
        exit;
    }

    if (!class_exists('QontoApi')) dol_include_once('/qontosync/class/qontoapi.class.php');
    if (!class_exists('QontoUpdater')) dol_include_once('/qontosync/class/qontoupdater.class.php');
    
    $api = new QontoApi();
    $qontoUpdater = new QontoUpdater($db);
    
    // Fetch the transaction from Qonto
    $selected_trx = $api->getTransaction($qonto_id);

    if (empty($selected_trx)) {
        echo json_encode(array(
            'status' => 'error',
            'message' => $langs->transnoentities("ErrorFetchingTransactions"),
            'type' => 'errors'
        ));
        exit;
    }
    
    // Retrieve the amount
    $amount = abs((float)$selected_trx['amount']);
    
    // Calculate the real amount with its sign
    if (isset($selected_trx['side']) && $selected_trx['side'] == 'debit') {
        $amount = -$amount;
    }
    
    $amounts_array = array();
    $doc_type = '';
    $total_imputed = 0.0;
    
    foreach ($link_docs as $doc) {
        if (count($parts = explode('_', strip_tags($doc), 2)) != 2) {
            continue;
        }
        
        $current_type = sanitizeVal($parts[0], 'alpha');
        $doc_id = (int)$parts[1];
        
        if (empty($doc_type)) {
            $doc_type = $current_type;
        } elseif ($doc_type !== $current_type) {
            echo json_encode(array(
                'status' => 'error',
                'message' => $langs->transnoentities("ErrorMixedDocumentTypes"),
                'type' => 'errors'
            ));
            exit;
        }
        
        $input_name = 'amount_' . $current_type . '_' . $doc_id;
        $imputed_amount = (float)price2num(GETPOST($input_name, 'alphanohtml'));
        
        // Retrieves the entered gross amount (Invoice = Positive, Credit Note = Negative)
        if ($imputed_amount != 0) {
            $amounts_array[$doc_id] = $imputed_amount;
            $total_imputed += $imputed_amount;
        }
    }
    
    if (empty($amounts_array)) {
        echo json_encode(array(
            'status' => 'error',
            'message' => $langs->transnoentities("ErrorNoAmountSpecified"),
            'type' => 'errors'
        ));
        exit;
    }

    // Adapt sign to the document type
    $total_imputed = ($doc_type === 'sinv' || $doc_type === 'vat' || $doc_type === 'social' || $doc_type === 'salary' || $doc_type === 'expense') ? -$total_imputed : $total_imputed;
    
    // The total must EXACTLY match the transaction amount to be compatible with QontoUpdater::update()
    if (price2num($total_imputed, 'MT') != price2num($amount, 'MT')) {
        echo json_encode(array(
            'status' => 'error',
            'message' => $langs->transnoentities("ErrorImputedAmountExceeds"),
            'type' => 'errors'
        ));
        exit;
    }
    
    // Send the array to update()
    $res = $qontoUpdater->update($doc_type, $amounts_array, $selected_trx, $bank_rowid);
    
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
}

http_response_code(400);
echo json_encode(array('status' => 'error', 'message' => 'Bad Request', 'type' => 'errors'));
exit;