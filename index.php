<?php
/*
 *  QontoSync dolibarr module 
 *  @file       index.php
 *  @author     Gaëtan COURTOIS
 *  @license    GPLv3
 */

require '../../main.inc.php';
require_once DOL_DOCUMENT_ROOT.'/compta/bank/class/account.class.php';
require_once dol_buildpath('/qontosync/class/qontoapi.class.php', 0);
require_once dol_buildpath('/qontosync/class/qontomatching.class.php', 0);
require_once dol_buildpath('/qontosync/lib/qontosync.lib.php', 0);

$matching = new QontoMatching($db);


$langs->loadLangs(array("qontosync@qontosync", "banks"));

// Security check
if ($user->socid > 0) {
	accessforbidden();
}
if (!$user->rights->qontosync->read) {
	accessforbidden();
}

llxHeader('', $langs->trans("QontoSync"));

print load_fiche_titre($langs->trans("QontoSync"), '', 'bank');


// Get params and define default values for month and year 
$action = GETPOST('action', 'alpha');
$bank_rowid = GETPOST('bank_rowid','int');
$month = GETPOST('month', 'int') ? GETPOST('month', 'int') : date('m');
$year = GETPOST('year', 'int') ? GETPOST('year', 'int') : date('Y');


// Fetch transactions before processing link or displaying search
if (!empty($bank_rowid) && $bank_rowid > 0 && ($action == 'search' || $action == 'link')) {

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
if ($action == 'link') {

	// Check CSRF token
	if (GETPOST('token', 'alpha') !== currentToken()) {
		setEventMessages($langs->trans("InvalidToken"), null, 'errors');
	} else {
		// Get params
		$target = GETPOST('target_val', 'alphanohtml');
		$qonto_id = GETPOST('qonto_id', 'alphanohtml');
	
	if (!empty($qonto_id) && !empty($target)) {
		if (is_array($transactions)) {

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
					setEventMessages($langs->trans("TransactionSuccessfullyLinked"), null, 'mesgs');
				} else {
					setEventMessages($langs->trans("FailedToLinkTransaction"), null, 'errors');
				}
			} else {
				setEventMessages($langs->trans("TransactionNotFound"), null, 'errors');
			}
		} else {
			setEventMessages($langs->trans("ErrorFetchingTransactions"), null, 'errors');
		}
		} else {
			setEventMessages($langs->trans("NoTransactionToLink"), null, 'warnings');
		}
	}
}

// Print search form
QontoSyncLib::printSearchForm($month, $year, $bank_rowid);

// Print search results table if form has been submitted
if (($action == 'search' || $action == 'link')) {
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


llxFooter();
