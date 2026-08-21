<?php
/*
 * QontoSync dolibarr module 
 * @file       ajax/get_advanced.php
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

$langs->loadLangs(array("qontosync@qontosync", "banks", "bills", "compta", "salaries", "main"));

$qonto_id = GETPOST('qonto_id', 'alphanohtml');
$bank_rowid = GETPOST('bank_rowid', 'int');

// Multi-company security segregation (Bank account entity verification)
if (!class_exists('QontoRepository')) dol_include_once('/qontosync/class/qontorepository.class.php');
$qontoRepo = new QontoRepository($db);

if ($bank_rowid > 0 && !$qontoRepo->isAccountAllowed($bank_rowid)) {
    http_response_code(403);
    echo json_encode(array('status' => 'error', 'message' => 'Permission Denied'));
    exit;
}

// If no transaction ID
if (empty($qonto_id)) {
    http_response_code(400);
    echo json_encode(array('status' => 'error', 'message' => 'ID de transaction manquant.'));
    exit;
}

// Try to get transaction informations from Qonto API
if (!class_exists('QontoApi')) dol_include_once('/qontosync/class/qontoapi.class.php');
$api = new QontoApi();
$raw_trx = $api->getTransaction($qonto_id);

// If transaction does not exist
if (empty($raw_trx)) {
    http_response_code(404);
    echo json_encode(array('status' => 'error', 'message' => 'Impossible de récupérer les détails de la transaction depuis Qonto.'));
    exit;
}

// Calculate the real Qonto amount with its sign
$processed_trx = $raw_trx;
$processed_trx['amount'] = (float)$raw_trx['amount'];
if (isset($processed_trx['side']) && $processed_trx['side'] == 'debit') {
    $processed_trx['amount'] = -$processed_trx['amount'];
}

// Verify if the transaction is linked with a bank entry
if (!class_exists('QontoMatching')) dol_include_once('/qontosync/class/qontomatching.class.php');
$qontoMatching = new QontoMatching($db);
$linked = $qontoMatching->isLinked($qonto_id, $bank_rowid, false);

// Payment type label determination
$op_type = isset($processed_trx['operation_type']) ? $processed_trx['operation_type'] : '';
$mop = $qontoMatching->getPaymentModeId($op_type, isset($processed_trx['side']) ? $processed_trx['side'] : '');
$payment_label = '';
if (!empty($mop)) {
    $payment_code = $langs->getLabelFromKey($db, $mop, 'c_paiement', 'id', 'code');
    $payment_label = $langs->transnoentities("PaymentTypeShort" . $payment_code);
    if ($payment_label == "PaymentTypeShort" . $payment_code) {
        $payment_label = $langs->getLabelFromKey($db, $mop, 'c_paiement', 'id', 'libelle');
    }
}

$linked_details = array();
$pending_documents = array();

if ($linked > 0) {
    // If the transaction is already linked, retrieve the details (Payments -> Invoices)
    $raw_linked_details = $qontoRepo->getLinkedDetails($linked);
    foreach ($raw_linked_details as $detail) {
        $doc_type_label = '';
        if ($detail->doc_type == 'cinv') $doc_type_label = $langs->transnoentities("CustomersInvoices");
        elseif ($detail->doc_type == 'sinv') $doc_type_label = $langs->transnoentities("SupplierInvoices");
        elseif ($detail->doc_type == 'vat') $doc_type_label = $langs->transnoentities("VAT");
        elseif ($detail->doc_type == 'social') $doc_type_label = $langs->transnoentities("SocialContributions");
        elseif ($detail->doc_type == 'expense') $doc_type_label = $langs->transnoentities("ExpenseReports");
        elseif ($detail->doc_type == 'various') $doc_type_label = $langs->transnoentities("VariousPayment");
        elseif ($detail->doc_type == 'salary') $doc_type_label = $langs->transnoentities("SalaryPayment");

        $linked_details[] = array(
            'doc_type' => $detail->doc_type,
            'doc_type_label' => $doc_type_label,
            'payment_id' => $detail->payment_id,
            'ref' => $detail->ref,
            'ref_supplier' => !empty($detail->ref_supplier) ? $detail->ref_supplier : '',
            'amount' => (float)$detail->amount,
            'amount_formatted' => price($detail->amount)
        );
    }
} elseif ($linked === 0) {
    // Get unpaid documents
    $filtered_documents = $qontoRepo->getUnpaidDocuments('all');

    // Customer Invoices
    if (!empty($filtered_documents['c_invoices'])) {
        foreach ($filtered_documents['c_invoices'] as $d) {
            $remain_for_js = (float)$d->amount;
            $amt_display = price($d->amount) . ' ' . $conf->currency;

            if (!empty($d->mc_code) && $d->mc_code != $conf->currency) {
                if (isset($processed_trx['currency']) && $d->mc_code == $processed_trx['currency']) {
                    $remain_for_js = (float)$d->mc_amount;
                }
                $amt_display = price($d->mc_amount) . ' ' . $d->mc_code . '<br><span class="opacitymedium small">' . price($d->amount) . ' ' . $conf->currency . '</span>';
            }

            $pending_documents[] = array(
                'key' => 'cinv_' . $d->rowid,
                'doctype' => 'cinv',
                'doctype_label' => $langs->transnoentities("CustomersInvoices"),
                'is_credit_note' => ((int)$d->inv_type == 2),
                'credit_note_label' => $langs->transnoentities("CreditNote"),
                'rowid' => (int)$d->rowid,
                'date_formatted' => dol_print_date($d->datev, 'day'),
                'label' => $d->label,
                'ref_supplier' => '',
                'thirdparty' => $d->thirdparty,
                'amount' => (float)$d->amount,
                'remain_for_js' => $remain_for_js,
                'amount_formatted' => $amt_display
            );
        }
    }

    // Supplier Invoices
    if (!empty($filtered_documents['s_invoices'])) {
        foreach ($filtered_documents['s_invoices'] as $d) {
            $remain_for_js = (float)$d->amount;
            $amt_display = price($d->amount) . ' ' . $conf->currency;

            if (!empty($d->mc_code) && $d->mc_code != $conf->currency) {
                if (isset($processed_trx['currency']) && $d->mc_code == $processed_trx['currency']) {
                    $remain_for_js = (float)$d->mc_amount;
                }
                $amt_display = price($d->mc_amount) . ' ' . $d->mc_code . '<br><span class="opacitymedium small">' . price($d->amount) . ' ' . $conf->currency . '</span>';
            }

            $pending_documents[] = array(
                'key' => 'sinv_' . $d->rowid,
                'doctype' => 'sinv',
                'doctype_label' => $langs->transnoentities("SupplierInvoices"),
                'is_credit_note' => ((int)$d->inv_type == 2),
                'credit_note_label' => $langs->transnoentities("CreditNote"),
                'rowid' => (int)$d->rowid,
                'date_formatted' => dol_print_date($d->datev, 'day'),
                'label' => $d->label,
                'ref_supplier' => !empty($d->ref_supplier) ? $d->ref_supplier : '',
                'thirdparty' => $d->thirdparty,
                'amount' => (float)$d->amount,
                'remain_for_js' => $remain_for_js,
                'amount_formatted' => $amt_display
            );
        }
    }

    // VAT
    if (!empty($filtered_documents['vat'])) {
        foreach ($filtered_documents['vat'] as $d) {
            $pending_documents[] = array(
                'key' => 'vat_' . $d->rowid,
                'doctype' => 'vat',
                'doctype_label' => $langs->transnoentities("VAT"),
                'is_credit_note' => false,
                'credit_note_label' => '',
                'rowid' => (int)$d->rowid,
                'date_formatted' => dol_print_date($d->datev, 'day'),
                'label' => $d->label,
                'ref_supplier' => '',
                'thirdparty' => 'VAT',
                'amount' => (float)$d->amount,
                'remain_for_js' => (float)$d->amount,
                'amount_formatted' => price($d->amount)
            );
        }
    }

    // Social Charges
    if (!empty($filtered_documents['social'])) {
        foreach ($filtered_documents['social'] as $d) {
            $pending_documents[] = array(
                'key' => 'social_' . $d->rowid,
                'doctype' => 'social',
                'doctype_label' => $langs->transnoentities("SocialContributions"),
                'is_credit_note' => false,
                'credit_note_label' => '',
                'rowid' => (int)$d->rowid,
                'date_formatted' => dol_print_date($d->datev, 'day'),
                'label' => $d->label,
                'ref_supplier' => '',
                'thirdparty' => 'SOCIAL',
                'amount' => (float)$d->amount,
                'remain_for_js' => (float)$d->amount,
                'amount_formatted' => price($d->amount)
            );
        }
    }

    // Salary Payments
    if (!empty($filtered_documents['salary'])) {
        foreach ($filtered_documents['salary'] as $d) {
            $pending_documents[] = array(
                'key' => 'salary_' . $d->rowid,
                'doctype' => 'salary',
                'doctype_label' => $langs->transnoentities("SalaryPayment"),
                'is_credit_note' => false,
                'credit_note_label' => '',
                'rowid' => (int)$d->rowid,
                'date_formatted' => dol_print_date($d->datev, 'day'),
                'label' => $d->label,
                'ref_supplier' => '',
                'thirdparty' => 'SALARY',
                'amount' => (float)$d->amount,
                'remain_for_js' => (float)$d->amount,
                'amount_formatted' => price($d->amount)
            );
        }
    }

    // Expense Reports
    if (!empty($filtered_documents['expense'])) {
        foreach ($filtered_documents['expense'] as $d) {
            $pending_documents[] = array(
                'key' => 'expense_' . $d->rowid,
                'doctype' => 'expense',
                'doctype_label' => $langs->transnoentities("ExpenseReports"),
                'is_credit_note' => false,
                'credit_note_label' => '',
                'rowid' => (int)$d->rowid,
                'date_formatted' => dol_print_date($d->datev, 'day'),
                'label' => $d->label,
                'ref_supplier' => '',
                'thirdparty' => 'EXPENSE',
                'amount' => (float)$d->amount,
                'remain_for_js' => (float)$d->amount,
                'amount_formatted' => price($d->amount)
            );
        }
    }
}

$date_formatted = !empty($processed_trx['settled_at']) ? dol_print_date(strtotime($processed_trx['settled_at']), 'day') : '';

$transaction_data = array(
    'id' => $processed_trx['id'],
    'label' => !empty($processed_trx['label']) ? $processed_trx['label'] : '',
    'settled_at' => !empty($processed_trx['settled_at']) ? $processed_trx['settled_at'] : '',
    'date_formatted' => $date_formatted,
    'amount' => (float)$processed_trx['amount'],
    'amount_formatted' => price($processed_trx['amount']),
    'amount_abs' => abs((float)$processed_trx['amount']),
    'currency' => !empty($processed_trx['currency']) ? $processed_trx['currency'] : $conf->currency,
    'type_label' => $payment_label,
    'reference' => !empty($processed_trx['reference']) ? $processed_trx['reference'] : '',
    'side' => !empty($processed_trx['side']) ? $processed_trx['side'] : ''
);

$response = array(
    'status' => 'success',
    'token' => newToken(),
    'transaction' => $transaction_data,
    'linked' => $linked,
    'linked_message' => ($linked > 0) ? $langs->transnoentities("TransactionAlreadyLinked", "<strong>" . $langs->transnoentities("AlreadyLinked") . "</strong>", $linked) : ($linked < 0 ? $langs->transnoentities("ErrorMultipleLinks", "<strong>" . $langs->transnoentities("MultipleLinks") . "</strong>") : ''),
    'linked_details' => $linked_details,
    'pending_documents' => $pending_documents
);

echo json_encode($response);
