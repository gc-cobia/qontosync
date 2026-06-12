<?php
/*
 *  QontoSync dolibarr module 
 *  @file       index.php
 *  @author     Gaëtan COURTOIS
 *  @license    GPLv3
 */

$res = 0;
if (! $res && file_exists("../../main.inc.php")) $res = @include "../../main.inc.php";
if (! $res && file_exists("../../../main.inc.php")) $res = @include "../../../main.inc.php";
if (! $res && file_exists("../../../../main.inc.php")) $res = @include "../../../../main.inc.php";
if (! $res && preg_match('/\/custom\//', $_SERVER["PHP_SELF"])) $res = @include "../../../../main.inc.php";

dol_include_once('/compta/bank/class/account.class.php');
dol_include_once('/qontosync/class/qontoapi.class.php');
dol_include_once('/qontosync/class/qontomatching.class.php');
dol_include_once('/qontosync/lib/qontosync.lib.php');

$matching = new QontoMatching($db);


$langs->loadLangs(array("qontosync@qontosync", "banks", "bills", "trips", "compta"));

// Security check
if ($user->socid > 0) {
	accessforbidden();
}
if (!$user->hasRight('qontosync', 'read')) {
	accessforbidden();
}

// Load javascript files
$arrayofjs = array('/qontosync/js/toggle_linked_rows.js');

llxHeader('', $langs->trans("QontoSync"), '', '', 0, 0, $arrayofjs);

print load_fiche_titre($langs->trans("QontoSync"), '', 'object_qonto.svg@qontosync');

// Get params and define default values for month and year 
$action = GETPOST('action', 'alpha');
$bank_rowid = GETPOST('bank_rowid','int');
$month = GETPOST('month', 'int') ? GETPOST('month', 'int') : date('m');
$year = GETPOST('year', 'int') ? GETPOST('year', 'int') : date('Y');


// Fetch transactions before processing link or displaying search
if (!empty($bank_rowid) && $bank_rowid > 0 && ($action == 'search' || $action == 'batch_link')) {
    
	// Init variables
	$transactions = null;

	// Get account info
	$account = new Account($db);
	$account->fetch($bank_rowid);

	// Set dates range for transactions request
	$dateStart = sprintf("%04d-%02d-01", $year, $month);
	$dateEnd = date("Y-m-t", strtotime($dateStart));

	// Get account iban 
	$selected_iban = $account->iban;

	// Single API call for both the link processing and the table display
	$api = new QontoApi();
	$transactions = $api->getTransactions($selected_iban, $dateStart, $dateEnd);
}

// Link transaction if form has been submitted
if ($action == 'batch_link') {

	// Check CSRF token
	if (GETPOST('token', 'alpha') !== currentToken()) {
		setEventMessages($langs->trans("InvalidToken"), null, 'errors');
	} else {
		// Get params
		$target_vals = GETPOST('target_val', 'array');
	
        if (is_array($target_vals) && is_array($transactions)) {
            $success_count = 0;
            $error_count = 0;

			foreach ($target_vals as $qonto_id => $target) {

				// Double check : cleaning keys and values extracted from the table
                $qonto_id = sanitizeVal($qonto_id, 'alphanohtml');
                $target = sanitizeVal($target, 'alphanohtml');

                // If the user hasn't chosen any match for this line, skip it
                if (empty($target)) {
                    continue;
                }

                // Find the correct transaction securely from the API payload
                $selected_trx = null;
                foreach ($transactions as $t) {
                    if ($t['id'] === $qonto_id) {
                        $selected_trx = $t;
                        break;
                    }
                }

                if ($selected_trx) {
                    // Trying to Link
                    $res = $matching->update($target, $selected_trx, $bank_rowid);
                    if ($res > 0) {
                        $success_count++;
                    } else {
                        $error_count++;
                    }
                }
            }


            // Global notification to the user
            if ($success_count > 0 || $error_count > 0) {
                $msg = $langs->trans("BatchProcessingCompleted", $success_count, $error_count);
                $type = ($error_count > 0) ? 'warnings' : 'mesgs';
                setEventMessages($msg, null, $type);
            } else {
                setEventMessages($langs->trans("NoTransactionToLink"), null, 'warnings');
            }
        } else {
            setEventMessages($langs->trans("ErrorFetchingTransactions"), null, 'errors');
        }
    }
}

// Print page begin flexbox container
QontoSyncLib::printPageBegin();

// Print search form
QontoSyncLib::printSearchForm($month, $year, $bank_rowid);

// Print search results table if form has been submitted
if (($action == 'search' || $action == 'batch_link')) {
	if (!empty($bank_rowid) && $bank_rowid > 0) {
		if ($transactions === false) {
			setEventMessages($langs->trans("ErrorFetchingTransactions"), null, 'errors');
		} else if (empty($transactions)) {
			setEventMessages($langs->trans("NoTransactionsFound"), null, 'warnings');
		} else {
			// Check if there are transactions to link
			$f_trx = $matching->fetchTransactions($transactions, $bank_rowid);

			// Render table results
			QontoSyncLib::printResultsTable($f_trx, $db, $bank_rowid, $month, $year);
		}

	} else {
		setEventMessages($langs->trans("NoBankSelected"), null, 'warnings');
	}
}

// Print page end flexbox container
QontoSyncLib::printPageEnd();

llxFooter();
