<?php
/*
 * QontoSync dolibarr module 
 * @file       index.php
 * @author     Gaëtan COURTOIS
 * @license    GPLv3
 */

$res = 0;
if (! $res && file_exists("../../main.inc.php")) $res = @include "../../main.inc.php";
if (! $res && file_exists("../../../main.inc.php")) $res = @include "../../../main.inc.php";
if (! $res && file_exists("../../../../main.inc.php")) $res = @include "../../../../main.inc.php";
if (! $res && preg_match('/\/custom\//', $_SERVER["PHP_SELF"])) $res = @include "../../../../main.inc.php";

require_once DOL_DOCUMENT_ROOT .'/compta/bank/class/account.class.php';
if (!class_exists('QontoMatching')) dol_include_once('/qontosync/class/qontomatching.class.php');
if (!class_exists('QontoUpdater')) dol_include_once('/qontosync/class/qontoupdater.class.php');
if (!class_exists('QontoSyncLib')) dol_include_once('/qontosync/lib/qontosync.lib.php');

$qontoMatching = new QontoMatching($db);
$qontoUpdater = new QontoUpdater($db);


 // Style for badges and selects
$fixed_width = "100%";
$style_badge = "width: {$fixed_width}; display: inline-block; text-align: center; box-sizing: border-box; padding: 6px; white-space: normal;";
$style_select = "width: {$fixed_width}; max-width: {$fixed_width}; box-sizing: border-box;";

$langs->loadLangs(array("qontosync@qontosync", "banks", "bills", "trips", "compta"));

// Security check
if ($user->socid > 0) {
	accessforbidden();
}
if (!$user->hasRight('qontosync', 'read')) {
	accessforbidden();
}

// Load javascript files
$arrayofjs = array(
    '/qontosync/js/toggle_linked_rows.js',
    '/qontosync/js/ajax_search.js' 
);

llxHeader('', $langs->trans("QontoSync"), '', '', 0, 0, $arrayofjs);

print load_fiche_titre($langs->trans("QontoSync"), '', 'object_qonto.svg@qontosync');

// Get params and define default values for month and year 
$action = GETPOST('action', 'alpha');
$bank_rowid = GETPOST('bank_rowid','int');
$month = GETPOST('month', 'int') ? GETPOST('month', 'int') : date('m');
$year = GETPOST('year', 'int') ? GETPOST('year', 'int') : date('Y');

// Fetch transactions before processing link or displaying search
if (!empty($bank_rowid) && $bank_rowid > 0 && ($action == 'search' || $action == 'batch_link' || $action == 'process_advanced_match')) {
    
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
	if (!class_exists('QontoApi')) dol_include_once('/qontosync/class/qontoapi.class.php');
	$api = new QontoApi();
	$transactions = $api->getTransactions($selected_iban, $dateStart, $dateEnd);
}

// Process advanced match from modal
if ($action == 'process_advanced_match') {
	// Check CSRF token
	if (GETPOST('token', 'alpha') !== currentToken()) {
		setEventMessages($langs->trans("InvalidToken"), null, 'errors');
	} else {
		$qonto_id = GETPOST('qonto_id', 'alphanohtml');
		$link_docs = GETPOST('link_doc', 'array');
		
		if (empty($qonto_id) || empty($bank_rowid) || empty($link_docs) || !is_array($link_docs)) {
			setEventMessages($langs->trans("NoTransactionToLink"), null, 'warnings');
		} else {
			// Find the correct transaction securely from the API payload
			$selected_trx = null;
			if (is_array($transactions)) {
				foreach ($transactions as $t) {
					if ($t['id'] === $qonto_id) {
						$selected_trx = $t;
						break;
					}
				}
			}
			
			if (empty($selected_trx)) {
				setEventMessages($langs->trans("ErrorFetchingTransactions"), null, 'errors');
			} else {
				$qonto_amount = (float)$selected_trx['amount'];
				if (isset($selected_trx['side']) && $selected_trx['side'] == 'debit') {
					$qonto_amount = -$qonto_amount;
				}
				$qonto_amount_abs = abs($qonto_amount);
				
				$amounts_array = array();
				$doc_type = '';
				$total_imputed = 0.0;
				$error = 0;
				
				// Iterate through submitted documents
				foreach ($link_docs as $doc) {
					$parts = explode('_', $doc, 2);
					if (count($parts) == 2) {
						$current_type = $parts[0];
						$doc_id = (int)$parts[1];
						
						// Ensure strict accounting logic: only one family of document per bank line
						if (empty($doc_type)) {
							$doc_type = $current_type;
						} elseif ($doc_type !== $current_type) {
							setEventMessages($langs->trans("ErrorMixedDocumentTypes"), null, 'errors');
							$error++;
							break;
						}
						
						// Retrieve the input amount associated to the checked document
						$input_name = 'amount_' . $current_type . '_' . $doc_id;
						$imputed_amount = (float)price2num(GETPOST($input_name, 'alphanohtml'));
						
						if ($imputed_amount > 0) {
							$amounts_array[$doc_id] = $imputed_amount;
							$total_imputed += $imputed_amount;
						}
					}
				}
				
				if ($error == 0 && empty($amounts_array)) {
					setEventMessages($langs->trans("ErrorNoAmountSpecified"), null, 'errors');
					$error++;
				}
				
				// Verify coherence: sum of payments must not exceed Qonto transaction amount
				if ($error == 0 && price2num($total_imputed, 'MT') > price2num($qonto_amount_abs, 'MT')) {
					setEventMessages($langs->trans("ErrorImputedAmountExceeds"), null, 'errors');
					$error++;
				}
				
				if ($error == 0) {
					// Call the advanced matching method
					$res = $qontoUpdater->updateAdvanced($doc_type, $amounts_array, $selected_trx, $bank_rowid);
					
					if ($res > 0) {
						setEventMessages($langs->trans("TransactionSuccessfullyLinked"), null, 'mesgs');
					} else {
						setEventMessages($langs->trans("FailedToLinkTransaction"), null, 'errors');
					}
				}
			}
		}
	}
}

// Link transaction if form has been submitted (Standard batch link)
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
                    // Attempt to link
                    $res = $qontoUpdater->update($target, $selected_trx, $bank_rowid);
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


// Print search form
if (!class_exists('Form')) require_once DOL_DOCUMENT_ROOT.'/core/class/html.form.class.php';
$form = new Form($db);
$bank_rowid = $bank_rowid > 0 ? $bank_rowid : '';
$select_comptes_html = $form->select_comptes($bank_rowid, 'bank_rowid', 0, '', 2, '', 0, '', 1);

        		
include dol_buildpath('/qontosync/core/tpl/search.tpl.php');

llxFooter();