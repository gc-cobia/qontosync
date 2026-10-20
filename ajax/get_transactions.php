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

$langs->loadLangs(array("qontosync@qontosync", "banks", "bills", "compta", "accountancy"));

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
        'transactions' => array()
    ));
    exit;
}

// Fetching the bank rowid
if (!class_exists('Account')) require_once DOL_DOCUMENT_ROOT .'/compta/bank/class/account.class.php';
$account = new Account($db);

if ($account->fetch($bank_rowid) <= 0) {
    http_response_code(400);
    echo json_encode(array('status' => 'error', 'message' => $langs->transnoentities("BankAccountNotFound"), 'type' => 'errors'));
    exit;
}

$dateStart = sprintf("%04d-%02d-01", $year, $month);
$dateEnd = date("Y-m-t", strtotime($dateStart));

// Single API call for both the link processing and the table display
if (!class_exists('QontoApi')) dol_include_once('/qontosync/class/qontoapi.class.php');
$api = new QontoApi();
$transactions = $api->getTransactions($account->iban, $dateStart, $dateEnd);

if ($transactions === false) {
    echo json_encode(array(
        'status' => 'error',
        'message' => $langs->transnoentities("ErrorFetchingTransactions"),
        'transactions' => array()
    ));
    exit;
}

if (empty($transactions)) {
    echo json_encode(array(
        'status' => 'warning',
        'message' => $langs->transnoentities("NoTransactionsFound"),
        'transactions' => array()
    ));
    exit;
}

// Load documents fetching Qonto transactions
if (!class_exists('QontoMatching')) dol_include_once('/qontosync/class/qontomatching.class.php');
$qontoMatching = new QontoMatching($db);
$transactions = $qontoMatching->fetchTransactions($transactions, $bank_rowid);

$formatted_transactions = array();

foreach ($transactions as $t) {
    $q_id = $t['id'];
    $settled_time = !empty($t['settled_at']) ? strtotime($t['settled_at']) : null;
    $date_formatted = $settled_time ? dol_print_date($settled_time, 'day') : '';

    $trx_item = array(
        'id' => $q_id,
        'label' => $t['label'],
        'reference' => !empty($t['reference']) ? $t['reference'] : '',
        'settled_at' => $t['settled_at'],
        'date_formatted' => $date_formatted,
        'amount' => (float) $t['amount'],
        'amount_formatted' => price($t['amount']),
        'currency' => !empty($t['currency']) ? $t['currency'] : $conf->currency,
        'is_negative' => ($t['amount'] < 0),
        'trx_status' => !empty($t['trx_status']) ? $t['trx_status'] : 'unlinked',
        'linked_rowid' => !empty($t['linked_rowid']) ? (int) $t['linked_rowid'] : 0,
        'linked_url' => !empty($t['linked_rowid']) ? (DOL_URL_ROOT . '/compta/bank/line.php?rowid=' . (int) $t['linked_rowid']) : '',
        'various_payment_url' => '',
        'has_matches' => false,
        'matches' => array(
            'bank' => array(),
            'cinv' => array(),
            'sinv' => array(),
            'vat' => array(),
            'social' => array(),
            'salary' => array(),
            'expense' => array()
        )
    );

    if ($trx_item['trx_status'] === 'unlinked') {
        // Build matching options
        // 1. Bank entries
        if (!empty($t['trx']) && is_array($t['trx'])) {
            foreach ($t['trx'] as $de) {
                $trans_label = $de->label;
                if (preg_match('/^\(([^)]+)\)(.*)$/i', $trans_label, $reg)) {
                    $trans_label = '(' . $langs->transnoentities($reg[1]) . ')' . $reg[2];
                } else {
                    $trans_label = $langs->transnoentities($trans_label);
                }
                $trx_item['matches']['bank'][] = array(
                    'value' => 'bank_' . $de->rowid,
                    'label' => dol_print_date($db->jdate($de->datev), 'day') . ' - ' . $trans_label . ' (' . price($de->amount) . ')'
                );
            }
        }

        // 2. Customer invoices
        if (!empty($t['c_invoices']) && is_array($t['c_invoices'])) {
            foreach ($t['c_invoices'] as $cinv) {
                $amt_display = price($cinv->amount) . ' ' . $conf->currency;
                if (!empty($cinv->mc_code) && $cinv->mc_code != $conf->currency) {
                    $amt_display = price($cinv->mc_amount) . ' ' . $cinv->mc_code . ' (' . $amt_display . ')';
                }
                $trx_item['matches']['cinv'][] = array(
                    'value' => 'cinv_' . $cinv->rowid,
                    'label' => dol_print_date($cinv->datev, 'day') . ' - ' . $cinv->label . ' (' . $amt_display . ') - ' . $cinv->thirdparty
                );
            }
        }

        // 3. Supplier invoices
        if (!empty($t['s_invoices']) && is_array($t['s_invoices'])) {
            foreach ($t['s_invoices'] as $sinv) {
                $amt_display = price($sinv->amount) . ' ' . $conf->currency;
                if (!empty($sinv->mc_code) && $sinv->mc_code != $conf->currency) {
                    $amt_display = price($sinv->mc_amount) . ' ' . $sinv->mc_code . ' (' . $amt_display . ')';
                }
                $ref_sup = !empty($sinv->ref_supplier) ? ' (' . $sinv->ref_supplier . ')' : '';
                $trx_item['matches']['sinv'][] = array(
                    'value' => 'sinv_' . $sinv->rowid,
                    'label' => dol_print_date($sinv->datev, 'day') . ' - ' . $sinv->label . ' (' . $amt_display . ') - ' . $sinv->thirdparty . $ref_sup
                );
            }
        }

        // 4. VAT
        if (!empty($t['vat']) && is_array($t['vat'])) {
            foreach ($t['vat'] as $vat) {
                $trx_item['matches']['vat'][] = array(
                    'value' => 'vat_' . $vat->rowid,
                    'label' => dol_print_date($vat->datev, 'day') . ' - ' . $vat->label . ' (' . price($vat->amount) . ')'
                );
            }
        }

        // 5. Social contributions
        if (!empty($t['social']) && is_array($t['social'])) {
            foreach ($t['social'] as $soc) {
                $trx_item['matches']['social'][] = array(
                    'value' => 'social_' . $soc->rowid,
                    'label' => dol_print_date($soc->datev, 'day') . ' - ' . $soc->label . ' (' . price($soc->amount) . ')'
                );
            }
        }

        // 6. Salaries
        if (!empty($t['salary']) && is_array($t['salary'])) {
            foreach ($t['salary'] as $sal) {
                $trx_item['matches']['salary'][] = array(
                    'value' => 'salary_' . $sal->rowid,
                    'label' => $sal->label . ' (' . price($sal->amount) . ')'
                );
            }
        }

        // 7. Expense reports
        if (!empty($t['expense']) && is_array($t['expense'])) {
            foreach ($t['expense'] as $exp) {
                $trx_item['matches']['expense'][] = array(
                    'value' => 'expense_' . $exp->rowid,
                    'label' => dol_print_date($exp->datev, 'day') . ' - ' . $exp->label . ' (' . price($exp->amount) . ')'
                );
            }
        }

        $trx_item['has_matches'] = (
            !empty($trx_item['matches']['bank']) ||
            !empty($trx_item['matches']['cinv']) ||
            !empty($trx_item['matches']['sinv']) ||
            !empty($trx_item['matches']['vat']) ||
            !empty($trx_item['matches']['social']) ||
            !empty($trx_item['matches']['salary']) ||
            !empty($trx_item['matches']['expense'])
        );
    }

    $formatted_transactions[] = $trx_item;
}

echo json_encode(array(
    'status' => 'success',
    'bank_rowid' => $bank_rowid,
    'token' => newToken(),
    'hide_linked' => (bool) getDolGlobalString('QONTOSYNC_HIDE_LINKED'),
    'transactions' => $formatted_transactions
));
