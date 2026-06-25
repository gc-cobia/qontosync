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

$langs->loadLangs(array("qontosync@qontosync"));

if (!class_exists('QontoApi')) dol_include_once('/qontosync/class/qontoapi.class.php');
if (!class_exists('QontoUpdater')) dol_include_once('/qontosync/class/qontoupdater.class.php');

$action = GETPOST('action', 'alpha');
$bank_rowid = GETPOST('bank_rowid', 'int');
$qonto_id = GETPOST('qonto_id', 'alphanohtml');
$link_docs = GETPOST('link_doc', 'array');

if ($action == 'process_advanced_match') {
    
    if (empty($qonto_id) || empty($bank_rowid) || empty($link_docs) || !is_array($link_docs)) {
        setEventMessages($langs->trans("NoTransactionToLink"), null, 'warnings');
        echo "NO_DOCS";
        exit;
    }

    $api = new QontoApi();
    $qontoUpdater = new QontoUpdater($db);
    
    // Fetch the transaction from Qonto
    $selected_trx = $api->getTransaction($qonto_id);

    if (empty($selected_trx)) {
        setEventMessages($langs->trans("ErrorFetchingTransactions"), null, 'errors');
        echo "API_ERROR";
        exit;
    }
    
    // Retrieve the amount
    $amount = abs((float)$selected_trx['amount']);
    
    $amounts_array = array();
    $doc_type = '';
    $total_imputed = 0.0;
    $error = 0;
    
    foreach ($link_docs as $doc) {
        if (count($parts = explode('_', strip_tags($doc), 2)) != 2) {
            $error++;
            continue;
        }
        
        $current_type = sanitizeVal($parts[0], 'alpha');
        $doc_id = (int)$parts[1];
        
        if (empty($doc_type)) {
            $doc_type = $current_type;
        } elseif ($doc_type !== $current_type) {
            setEventMessages($langs->trans("ErrorMixedDocumentTypes"), null, 'errors');
            $error++;
            break;
        }
        
        $input_name = 'amount_' . $current_type . '_' . $doc_id;
        $imputed_amount = (float)price2num(GETPOST($input_name, 'alphanohtml'));
        
        // Retrieves the entered gross amount (Invoice = Positive, Credit Note = Negative)
        if ($imputed_amount != 0) {
            $amounts_array[$doc_id] = $imputed_amount;
            $total_imputed += $imputed_amount;
        }
    }
    
    if ($error == 0 && empty($amounts_array)) {
        setEventMessages($langs->trans("ErrorNoAmountSpecified"), null, 'errors');
        $error++;
    }
    
    // The total must EXACTLY match the transaction amount to be compatible with QontoUpdater::update()
    if ($error == 0 && price2num($total_imputed, 'MT') != price2num($amount, 'MT')) {
        setEventMessages($langs->trans("ErrorImputedAmountExceeds"), null, 'errors');
        $error++;
    }
    
    if ($error == 0) {
        // Send the array to update()
        $res = $qontoUpdater->update($doc_type, $amounts_array, $selected_trx, $bank_rowid);
        
        if ($res > 0) {
            setEventMessages($langs->trans("TransactionSuccessfullyLinked"), null, 'mesgs');
        } else {
            setEventMessages($langs->trans("FailedToLinkTransaction"), null, 'errors');
        }
    }

    // Respond to JS
    echo "OK";
    exit;
}

http_response_code(400);
echo "Bad Request";
exit;