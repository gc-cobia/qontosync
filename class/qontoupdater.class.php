<?php
/*
 * QontoSync dolibarr module 
 * @file       class/qontoupdater.class.php
 * @author     Gaëtan COURTOIS
 * @license    GPLv3
 */

/**
 * SECURITY WARNING - SQL INJECTIONS
 * As this module relies on standard Dolibarr $db abstraction (no prepared statements),
 * ANY dynamic variable injected in SQL strings MUST strictly follow these rules:
 * - Numerical IDs MUST be casted to integer: (int)$id
 * - Strings MUST be escaped: $this->db->escape($string)
 * - Arrays/Lists MUST be validated before implode()
 */

class QontoUpdater
{
    private $db;
	public $error = '';
	public $errors = array();
	private $multicurrency_enabled;

    public function __construct($db) {
		global $conf;
		$this->db = $db;
		$this->multicurrency_enabled = isModEnabled('multicurrency');
    }

	/**
	 * Link a Qonto transaction ID to a Dolibarr bank entry
	 *
	 * @param int $bank_line_id
	 * @param string $qonto_id
	 * @param string $ref_releve
	 * @return int 1 on success, -1 on error
	 */
	private function linkTransaction($bank_line_id, $qonto_id, $ref_releve)
	{
		global $conf, $user;

		if (empty($bank_line_id) || empty($qonto_id) || empty($ref_releve)) {
			dol_syslog(__METHOD__ . ": Error on input parameters", LOG_ERR);
			return -1;
		}
		
		// Lazy load AccountLine class if needed
		if (!class_exists('AccountLine')) require_once DOL_DOCUMENT_ROOT.'/compta/bank/class/account.class.php';
		
		$bank = new AccountLine($this->db);
		$res = $bank->fetch($bank_line_id);
		
		// Verify if the bank line exists
		if ($res <= 0) {
			dol_syslog(__METHOD__ . ": bank line " . $bank_line_id . " doesn't exist", LOG_ERR);
			return -1;
		}
		
		// Lazy load ExtraFields class
		if (!class_exists('ExtraFields')) require_once DOL_DOCUMENT_ROOT.'/core/class/extrafields.class.php';	

		$extrafields = new ExtraFields($this->db);
		$extrafields->fetch_name_optionals_label($bank->table_element);
		$bank->fetch_optionals();
		
		// Assign the Qonto ID to the target extrafield
		$bank->array_options['options_qonto_id'] = $qonto_id;
	
		// Try to update the specific extrafield
		$res = $bank->updateExtraField('qonto_id');
		
		// If the result is 0 (the row does not exist yet in the extrafields table) or < 0 (error)
		if ($res <= 0) {
			// Force the creation of the complete row
			$res = $bank->insertExtraFields();
			
			if ($res < 0) {
				dol_syslog(__METHOD__ . ": Error creating the bank line extrafield: ".$bank->error, LOG_ERR);
				return -1;
			}
		}
		
		// Update the bank line with the statement reference
		$bank->num_releve = $ref_releve;
		$res = $bank->update_conciliation($user, null, 1);
		if ($res < 0) {
			dol_syslog(__METHOD__ . ": Error updating the bank line statement: ".$bank->error, LOG_ERR);
			return -1;
		}
	
		return 1;
	}
	
	/**
	 * Unlink a Qonto transaction ID from a Dolibarr bank entry
	 *
	 * @param int $bank_line_id
	 * @return int 1 on success, -1 on error
	 */
	public function unlinkTransaction($bank_line_id)
	{
		global $conf, $user;

		if (empty($bank_line_id)) {
			dol_syslog(__METHOD__ . ": Error on input parameters", LOG_ERR);
			return -1;
		}
		
		// Lazy load AccountLine class if needed
		if (!class_exists('AccountLine')) require_once DOL_DOCUMENT_ROOT.'/compta/bank/class/account.class.php';
		
		$bank = new AccountLine($this->db);
		$res = $bank->fetch($bank_line_id);
		
		// Verify if the bank line exists
		if ($res <= 0) {
			dol_syslog(__METHOD__ . ": bank line " . $bank_line_id . " doesn't exist", LOG_ERR);
			return -1;
		}

		// Multi-tenant Security (Anti-IDOR)
		if (!class_exists('QontoRepository')) dol_include_once('/qontosync/class/qontorepository.class.php');
		$qontoRepo = new QontoRepository($this->db);

		if (!$qontoRepo->isAccountAllowed($bank->fk_account)) {
			dol_syslog(__METHOD__ . ": Bank account NOT allowed for current entity scope: " . $bank->fk_account, LOG_ERR);
			return -1;
		}

		// Init SQL transaction
		$this->db->begin();

		// Try to update the specific extrafieldm
		// Direct SQL to force NULL and bypass Dolibarr's ORM empty string conversion which breaks UNIQUE constraint)
		$sql = "UPDATE " . MAIN_DB_PREFIX . "bank_extrafields SET qonto_id = NULL WHERE fk_object = " . (int)$bank_line_id;
		$resql = $this->db->query($sql);
		
		if (!$resql) {
			// Rollback SQL transaction
			$this->db->rollback();
			dol_syslog(__METHOD__ . ": Error updating the bank line extrafield: ".$bank->error, LOG_ERR);
			return -1;
		}
		
		// Update the bank line to remove the statement reference
		$bank->num_releve = '';
		$res = $bank->update_conciliation($user, null, 0);
		
		if ($res < 0) {
			// Rollback SQL transaction
			$this->db->rollback();
			dol_syslog(__METHOD__ . ": Error updating the bank line statement: ".$bank->error, LOG_ERR);
			return -1;
		}
	
		// Commit SQL transaction
		$this->db->commit();
		dol_syslog(__METHOD__ . ": bank line " . $bank_line_id . " unlinked", LOG_INFO);
		return 1;
	}
	
	/**
	 * Create a Various Payment (Opération Diverse) and link it to a Qonto transaction
	 *
	 * @param string $qonto_id
	 * @param int    $bank_rowid
	 * @param string $accountancy_code
	 * @param string $fileparam
	 * @return int 1 on success, -1 on error
	 */
	public function createVariousPayment($qonto_id, $bank_rowid, $accountancy_code = '', $fileparam = 'od_attached_file')
	{
		global $conf, $user, $langs;

		if (empty($qonto_id) || empty($bank_rowid)) {
			dol_syslog(__METHOD__ . ": Error on input parameters", LOG_ERR);
			return -1;
		}

		// Check Accounting Module is enabled, accountancy code is required
		if (isModEnabled('accounting') && empty($accountancy_code)) {
			dol_syslog(__METHOD__ . ": Accountancy code is empty", LOG_ERR);
			return -1;
		}

		// Multi-tenant Security (Anti-IDOR)
		if (!class_exists('QontoRepository')) dol_include_once('/qontosync/class/qontorepository.class.php');
		$qontoRepo = new QontoRepository($this->db);

		if (!$qontoRepo->isAccountAllowed($bank_rowid)) {
			dol_syslog(__METHOD__ . ": Bank account NOT allowed for current entity scope: " . $bank_rowid, LOG_ERR);
			return -1;
		}

		// Lazy load classes
		if (!class_exists('QontoApi')) dol_include_once('/qontosync/class/qontoapi.class.php');
		if (!class_exists('QontoMatching')) dol_include_once('/qontosync/class/qontomatching.class.php');
		if (!class_exists('PaymentVarious')) require_once DOL_DOCUMENT_ROOT.'/compta/bank/class/paymentvarious.class.php';

		$api = new QontoApi();
		$qontoMatching = new QontoMatching($this->db);

		// Fetch transaction details
		$qonto_trx = $api->getTransaction($qonto_id);
		if (empty($qonto_trx)) {
			dol_syslog(__METHOD__ . ": Cannot fetch Qonto transaction details for ID: " . $qonto_id, LOG_ERR);
			return -1;
		}

		// Prepare payment object
		$payment = new PaymentVarious($this->db);
		$payment->datev = strtotime($qonto_trx['settled_at']);
		$payment->datep = isset($qonto_trx['emitted_at']) ? strtotime($qonto_trx['emitted_at']) : strtotime($qonto_trx['settled_at']);
		$payment->amount = abs($qonto_trx['amount']);
		$payment->sens = (isset($qonto_trx['side']) && $qonto_trx['side'] === 'debit') ? 0 : 1;
		$payment->fk_account = $bank_rowid;
		$payment->label = $qonto_trx['label'] . (!empty($qonto_trx['reference']) ? ' - ' . $qonto_trx['reference'] : '');
		$payment->num_payment = !empty($qonto_trx['reference']) ? $qonto_trx['reference'] : $qonto_id;

		$mop = $qontoMatching->getPaymentModeId(
			isset($qonto_trx['operation_type']) ? $qonto_trx['operation_type'] : '',
			isset($qonto_trx['side']) ? $qonto_trx['side'] : ''
		);
		$payment->type_payment = $mop;
		
		$payment->accountancy_code = $accountancy_code;

		// Statement reference calculation (identical to QontoUpdater::update())
		$ref_releve = '';
		if (isset($qonto_trx['settled_at'])) {
			$format_setting = getDolGlobalString('QONTOSYNC_STATEMENT_FORMAT') ?: '{Y}-{m}';
			$time_trx = strtotime($qonto_trx['settled_at']);
			
			$raw_ref = preg_replace_callback('/\{([^}]+)\}/', function($matches) use ($time_trx) {
				return date($matches[1], $time_trx);
			}, $format_setting);

			$clean_ref = dol_sanitizeFileName($raw_ref);

			$ref_releve = substr($clean_ref, 0, 50);
		}

		// Init SQL transaction
		$this->db->begin();

		$res = $payment->create($user);

		if ($res > 0) {
			$payment->fetch($res);

			$link_res = $this->linkTransaction($payment->fk_bank, $qonto_id, $ref_releve);
			if ($link_res > 0) {
				$this->db->commit();

				// Handle attached file if provided
				if (!empty($fileparam) && !empty($_FILES[$fileparam]['name'])) {
					if (!function_exists('dol_add_file_process')) {
						require_once DOL_DOCUMENT_ROOT.'/core/lib/files.lib.php';
					}
					$bank_dir_output = !empty($conf->bank->dir_output) ? $conf->bank->dir_output : (DOL_DATA_ROOT . '/bank');
					$upload_dir = $bank_dir_output . '/' . dol_sanitizeFileName((string) $payment->id);
					$upload_res = dol_add_file_process($upload_dir, 0, 1, $fileparam, '', null, '', 1, $payment);
					if ($upload_res < 0) {
						dol_syslog(__METHOD__ . ": Warning: Failed to process attached file for payment various ID " . $payment->id, LOG_WARNING);
					} else {
						dol_syslog(__METHOD__ . ": Attached file successfully added for payment various ID " . $payment->id, LOG_INFO);
					}
				}
				dol_syslog(__METHOD__ . ": Various payment created and linked to Qonto ID: " . $qonto_id, LOG_INFO);
				return 1;
			} else {
				$this->db->rollback();
				dol_syslog(__METHOD__ . ": Error linking various payment to Qonto ID: " . $qonto_id, LOG_ERR);
				return -1;
			}
		} else {
			$this->db->rollback();
			dol_syslog(__METHOD__ . ": Error creating various payment: " . $payment->error, LOG_ERR);
			return -1;
		}
	}
	
    /**
	 * Unified method: Update selected entries with the Qonto transaction (Single or Multiple)
	 *
	 * @param string $type            Document type ('bank', 'cinv', 'sinv', 'vat')
	 * @param array  $amounts_array   Array of document ID => imputed amount
	 * @param mixed  $qonto_data      Qonto transaction data (array) OR Qonto transaction ID (string)
	 * @param int    $bank_account_id Bank account ID
	 * @return int 1 on success, -1 on error
	 */
	public function update($type, $amounts_array, $qonto_data, $bank_account_id = 0)
	{
		global $conf, $user;
		
		if (empty($type) || empty($amounts_array) || !is_array($amounts_array) || empty($qonto_data)) {
			dol_syslog(__METHOD__ . ": Error on input parameters", LOG_ERR);
			return -1;
		}

		// Multi-tenant Security (Anti-IDOR)
		if (!class_exists('QontoRepository')) dol_include_once('/qontosync/class/qontorepository.class.php');
		$qontoRepo = new QontoRepository($this->db);

		if (empty($bank_account_id) || !$qontoRepo->isAccountAllowed($bank_account_id)) {
			dol_syslog(__METHOD__ . ": Bank account NOT allowed for current entity scope: " . $bank_account_id, LOG_ERR);
			return -1;
		}

		// Security: Block multiple documents selection for 1-to-1 relation types
        $one_to_one_types = array('bank', 'vat', 'social', 'salary', 'expense');
        if (in_array($type, $one_to_one_types) && count($amounts_array) > 1) {
            dol_syslog(__METHOD__ . ": Error, cannot link multiple documents for type " . $type, LOG_ERR);
            return -1;
        }
		
		// Resolve transaction data
		$qonto_trx = array();
		if (is_array($qonto_data)) {
			// Fast track: Array provided (ex: from update_batch)
			$qonto_trx = $qonto_data;
		} elseif (is_string($qonto_data)) {
			// Smart track: ID provided, fetch from API (ex: for cron/trigger)
			if (!class_exists('QontoApi')) dol_include_once('/qontosync/class/qontoapi.class.php');
			$api = new QontoApi();
			$qonto_trx = $api->getTransaction($qonto_data);
			
			if (empty($qonto_trx)) {
				dol_syslog(__METHOD__ . ": Cannot fetch Qonto transaction details for ID: " . $qonto_data, LOG_ERR);
				return -1;
			}
		} else {
			return -1;
		}
			
		// Define local qonto currency amount
		$qonto_local_amount = 0.0;
		if (!empty($this->multicurrency_enabled)) {
			$qonto_local_amount = isset($qonto_trx['local_amount']) ? (float)$qonto_trx['local_amount'] : 0.0;
		}

		// Calculate the real Qonto amount with its sign
		$qonto_amount = isset($qonto_trx['amount']) ? (float)$qonto_trx['amount'] : 0.0;
		if (empty($qonto_amount)) {
			dol_syslog(__METHOD__ . ": Error: Qonto amount is zero or empty.", LOG_ERR);
			return -1;
		}
		if (isset($qonto_trx['side']) && $qonto_trx['side'] == 'debit') {
			$qonto_amount = -$qonto_amount;
			$qonto_local_amount = -$qonto_local_amount;
		}
		
		// Amount verification
		$total_imputed = 0.0;
		foreach ($amounts_array as $doc_id => $amount) {
			// Keep the sign (+ for invoice, - for credit note)
			$total_imputed += (float)$amount;
		}
		
		// Adapt sign to the document type
		$total_imputed = ($type === 'sinv' || $type === 'vat' || $type === 'social' || $type === 'salary' || $type === 'expense') ? -$total_imputed : $total_imputed;
			
		// Strict verification
		if (price2num($total_imputed, 'MT') != price2num($qonto_amount, 'MT')) {
			dol_syslog(__METHOD__ . ": Error: Total imputed (" . $total_imputed . ") != Qonto amount (" . $qonto_amount . ").", LOG_ERR);
			return -1;
		}

        // Load QontoMatching class if not already loaded
        if (!class_exists('QontoMatching')) dol_include_once('/qontosync/class/qontomatching.class.php');
        $qontoMatching = new QontoMatching($this->db);
		
		// Check if Qonto ID already exists (REALTIME check, explicitly bypass cache)
		if ($qontoMatching->isLinked($qonto_trx['id'], $bank_account_id, false) !== 0) {
			dol_syslog(__METHOD__ . ": Transaction ID (".$qonto_trx['id'].") already linked.", LOG_WARNING);
			return -1;
		}

		// Init SQL transaction
		$this->db->begin();
		
		// Init error counter
		$error = 0;
		
		// Define operation type string for legacy code use below
		$op_type = isset($qonto_trx['operation_type']) ? $qonto_trx['operation_type'] : '';
		
		// Get payment id from dolibarr
		$mop = $qontoMatching->getPaymentModeId(
			$op_type, 
			isset($qonto_trx['side']) ? $qonto_trx['side'] : ''
		);
		
		// Extract bank identifier based on operation type (e.g., 'transfer', 'income', etc.)
		$emitter_name = '';
		$emitter_bank_name = '';
		if (!empty($op_type) && isset($qonto_trx[$op_type]['counterparty_bank_identifier'])) {
			$emitter_bank_name = $qonto_trx[$op_type]['counterparty_bank_identifier'];
		}

		// Extract transfer/check number if available
		$num_chq = '';
		if ($op_type == 'cheque' || $op_type == 'check') {
			$num_chq = isset($qonto_trx['check']['check_number']) ? $qonto_trx['check']['check_number'] : '';
		} elseif ($op_type == 'direct_debit_collection') {
			$num_chq = isset($qonto_trx['direct_debit_collection']['unique_mandate_reference']) ? $qonto_trx['direct_debit_collection']['unique_mandate_reference'] : '';
		} else {
			$num_chq = isset($qonto_trx['reference']) ? $qonto_trx['reference'] : '';
		}

		// Extract statement reference
		$ref_releve = '';
		if (isset($qonto_trx['settled_at'])) {
			$format_setting = getDolGlobalString('QONTOSYNC_STATEMENT_FORMAT') ?: '{Y}-{m}';
			$time_trx = strtotime($qonto_trx['settled_at']);
			
			$raw_ref = preg_replace_callback('/\{([^}]+)\}/', function($matches) use ($time_trx) {
				return date($matches[1], $time_trx);
			}, $format_setting);

			$clean_ref = dol_sanitizeFileName($raw_ref);

			$ref_releve = substr($clean_ref, 0, 50);
		}

		$autoclose = 1;
		$payment_label_prefix = '';
		
		switch ($type) {
			case 'bank':
				// Security check: Bank module is enabled
				if (!isModEnabled('banque')) { dol_syslog(__METHOD__ . ": Bank module is disabled.", LOG_ERR); $error++; break; }
				
				// Accounting security: 1 Qonto transaction = 1 single Dolibarr bank line
				if (count($amounts_array) > 1) {
					dol_syslog(__METHOD__ . ": Error, cannot link multiple bank lines to a single Qonto transaction.", LOG_ERR);
					$error++;
					break;
				}
				
				// Get the unique ID of the sent bank line
				reset($amounts_array);
				$doc_id = key($amounts_array);

				// Updating details of the bank line with transaction dates
				if (!class_exists('AccountLine')) require_once DOL_DOCUMENT_ROOT.'/compta/bank/class/account.class.php';
				$line = new AccountLine($this->db);

				if ($line->fetch($doc_id) > 0) {

					// Multi-Company Security (IDOR)
					if ((int)$line->fk_account !== (int)$bank_account_id) {
						dol_syslog(__METHOD__ . ": Security Alert - Bank line " . $doc_id . " does not belong to authorized bank account " . $bank_account_id, LOG_WARNING);
						$error++;
						break;
					}

					$line->datev = strtotime($qonto_trx['settled_at']);
					$line->dateo = isset($qonto_trx['emitted_at']) ? strtotime($qonto_trx['emitted_at']) : strtotime($qonto_trx['settled_at']);

					$update = $line->update($user);
					if ($update < 0) {
						dol_syslog(__METHOD__ . ": Error updating bank line dates: " . $line->error, LOG_ERR);
						$error++;
						break;
					}
				}
				
				// Link directly to the existing bank line (no payment creation)
				$run = $this->linkTransaction($doc_id, $qonto_trx['id'], $ref_releve);
				if ($run < 0) {
					dol_syslog(__METHOD__ . ": Error, can not link the transaction", LOG_ERR);
					$error++;
				}
				break;

			case 'cinv':
				// Security check: Customer Invoice module is enabled
				if (!isModEnabled('facture')) { dol_syslog(__METHOD__ . ": Customer Invoice module is disabled.", LOG_ERR); $error++; break; }

                // Declare payment and invoice classes
				if (!class_exists('Paiement')) require_once DOL_DOCUMENT_ROOT.'/compta/paiement/class/paiement.class.php';
				if (!class_exists('Facture')) require_once DOL_DOCUMENT_ROOT.'/compta/facture/class/facture.class.php';
				
				// Initialize invoice
				$invoice = new Facture($this->db);

				// Initialize payment
				$payment = new Paiement($this->db);
                $payment_type_str = 'payment';

				$valid_amounts = array();
				
                $first_socid = 0;
                $payment_label_ref = '';

				// Multi-currency pre-check
				$valid_mc_amounts = array();
                $mc_remain_all_docs = 0;
                $mc_remain_by_doc = array();
				$eur_remaining = abs($qonto_amount);
				$mc_remaining = abs($qonto_local_amount);
				$doc_index = 0;
                $total_docs = 0;
                
                if (!empty($this->multicurrency_enabled) && $qonto_local_amount != 0) {
                    foreach ($amounts_array as $doc_id => $amount) {
                        if ($invoice->fetch($doc_id) > 0) {
                            $tmp_total = (float)$invoice->multicurrency_total_ttc;
                            $tmp_paid = $qontoRepo->getMulticurrencyPaidAmount($doc_id, 'cinv');
                            $tmp_rem = price2num($tmp_total - $tmp_paid, 'MT');
                            
                            $mc_remain_by_doc[$doc_id] = $tmp_rem;
                            $mc_remain_all_docs += $tmp_rem;

							if (
								$invoice->multicurrency_code != $conf->currency && 
								!empty($qonto_trx['local_currency']) && 
								strtoupper($invoice->multicurrency_code) === strtoupper($qonto_trx['local_currency'])
							) {
                                $total_docs++;
                            }
                        }
                    }
					
					$fetched_docs = count($mc_remain_by_doc);
					if ($total_docs > 0 && $total_docs < $fetched_docs) {
						dol_syslog(__METHOD__ . ": Error, mixed currency documents detected in the same reconciliation batch.", LOG_ERR);
						$error++;
						break;
					}
                }
                
                // If transaction local amount = total remain to pay of docs (in foreign currency)
                $is_perfect_mc_match = (abs($mc_remain_all_docs - abs($qonto_local_amount)) <= 0.01);

				// Loop through amounts_array to validate and collect payment data	
				foreach ($amounts_array as $doc_id => $amount) {
					if ($invoice->fetch($doc_id) > 0) {

						// Check if the invoice is already marked as paid
						if ($invoice->paye == 1) {
							dol_syslog(__METHOD__ . ": Error, the customer invoice " . $doc_id . " is already marked as paid.", LOG_ERR);
							$error++;
							break;
						}

						// Multi-Company Security (IDOR)
						if ($invoice->entity != $conf->entity && (!function_exists('getEntity') || !in_array((string)$invoice->entity, explode(',', getEntity('facture'))))) {
							dol_syslog(__METHOD__ . ": Entity mismatch on customer invoice " . $doc_id, LOG_WARNING);
							$error++; break;
						}

					    // Retrieve thirdparty info for the first document to populate the bank line
						if ($first_socid == 0) {
							$first_socid = $invoice->fk_soc;
							$invoice->fetch_thirdparty();
							$emitter_name = is_object($invoice->thirdparty) ? $invoice->thirdparty->name : '';

						// Strict blocking if the third party is different for the following documents
						} elseif ($first_socid != $invoice->fk_soc) {
						    dol_syslog(__METHOD__ . ": Documents from different third parties detected.", LOG_ERR);
							$error++; break;
						}

                        // Handle Credit Notes by applying a negative sign to the payment amount
						$pay_amount = abs((float)$amount);
						if ($invoice->type == 2 || $invoice->total_ttc < 0) $pay_amount = -$pay_amount;

						$valid_amounts[$doc_id] = $pay_amount;

						// Handle multicurrency different change
						if (!empty($this->multicurrency_enabled) && $qonto_local_amount != 0) {
							if (
								$invoice->multicurrency_code != $conf->currency && 
								!empty($qonto_trx['local_currency']) && 
								strtoupper($invoice->multicurrency_code) === strtoupper($qonto_trx['local_currency'])
							) {
								$autoclose = 0;
								$doc_index++;
								$is_last_doc = ($doc_index === $total_docs);
								$mc_remain = isset($mc_remain_by_doc[$doc_id]) ? $mc_remain_by_doc[$doc_id] : 0;
                                
								if ($is_perfect_mc_match) {
									$mc_imput = abs($mc_remain);
                                } else {
                                    // Case of pure partial payment: proportional distribution
									if ($eur_remaining > 0) {
                                        $qonto_imput_tx = abs($amount) / $eur_remaining;
                                        $mc_imput = $qonto_imput_tx * $mc_remaining;
                                    } else {
                                        $mc_imput = 0;
                                    }
                                }
                                
								$mc_imput = price2num($mc_imput, 'MT');

								if ($is_last_doc && abs(abs($amount) - $eur_remaining) <= 0.01) {
                                    $mc_imput = $mc_remaining;
                                }

                                // Safeguard: the amount of currency allocated cannot exceed the balance remaining in the overall transaction
                                if ($mc_imput > abs($mc_remaining)) {
                                    $mc_imput = abs($mc_remaining);
                                }
                                
                                // The currency used for the next loop iteration is determined
								$eur_remaining = price2num($eur_remaining - abs($amount), 'MT');
                                $mc_remaining = price2num($mc_remaining - $mc_imput, 'MT');

								// Prepare for payment
								$mc_pay_amount = $mc_imput;
								if ($invoice->type == 2 || $invoice->total_ttc < 0) $mc_pay_amount = -$mc_pay_amount;
								$valid_mc_amounts[$doc_id] = $mc_pay_amount;
							}
						}

						$payment_label_ref .= $invoice->ref . ', ';
					} else {
						$error++;
					}
				}
				break;
				
			case 'sinv':
				// Security check: Supplier Invoice module is enabled
				if (!isModEnabled('fournisseur')) { dol_syslog(__METHOD__ . ": Supplier Invoice module is disabled.", LOG_ERR); $error++; break; }
				
				// Declare payment and invoice classes
				if (!class_exists('PaiementFourn')) require_once DOL_DOCUMENT_ROOT.'/fourn/class/paiementfourn.class.php';
				if (!class_exists('FactureFournisseur')) require_once DOL_DOCUMENT_ROOT.'/fourn/class/fournisseur.facture.class.php';
				
				// Initialize invoice
				$invoice = new FactureFournisseur($this->db);

				// Initialize payment
				$payment = new PaiementFourn($this->db);
				$payment_type_str = 'payment_supplier';

				$valid_amounts = array();

				$first_socid = 0;
				$payment_label_ref = '';

				// Multi-currency pre-check
				$valid_mc_amounts = array();
                $mc_remain_all_docs = 0;
                $mc_remain_by_doc = array();
				$eur_remaining = abs($qonto_amount);
				$mc_remaining = abs($qonto_local_amount);
				$doc_index = 0;
                $total_docs = 0;
                
                if (!empty($this->multicurrency_enabled) && $qonto_local_amount != 0) {
                    foreach ($amounts_array as $doc_id => $amount) {
                        if ($invoice->fetch($doc_id) > 0) {
                            $tmp_total = (float)$invoice->multicurrency_total_ttc;
                            $tmp_paid = $qontoRepo->getMulticurrencyPaidAmount($doc_id, 'sinv');
                            $tmp_rem = price2num($tmp_total - $tmp_paid, 'MT');
                            
                            $mc_remain_by_doc[$doc_id] = $tmp_rem;
                            $mc_remain_all_docs += $tmp_rem;

							if (
								$invoice->multicurrency_code != $conf->currency && 
								!empty($qonto_trx['local_currency']) && 
								strtoupper($invoice->multicurrency_code) === strtoupper($qonto_trx['local_currency'])
							) {
                                $total_docs++;
                            }
                        }
                    }
					
					$fetched_docs = count($mc_remain_by_doc);
					if ($total_docs > 0 && $total_docs < $fetched_docs) {
						dol_syslog(__METHOD__ . ": Error, mixed currency documents detected in the same reconciliation batch.", LOG_ERR);
						$error++;
						break;
					}
                }
                
                // If Qonto currency amount = remain to pay (in foreign currency) of all docs; fast imputation
                $is_perfect_mc_match = (abs($mc_remain_all_docs - abs($qonto_local_amount)) <= 0.01);

				// Loop through amounts_array to validate and collect payment data	
				foreach ($amounts_array as $doc_id => $amount) {
					if ($invoice->fetch($doc_id) > 0) {

						// Check if the invoice is already marked as paid
						if ($invoice->paye == 1) {
							dol_syslog(__METHOD__ . ": Error, the supplier invoice " . $doc_id . " is already marked as paid.", LOG_ERR);
							$error++;
							break;
						}

						// Multi-Company Security (IDOR)
						if ($invoice->entity != $conf->entity && (!function_exists('getEntity') || !in_array((string)$invoice->entity, explode(',', getEntity('facture_fourn'))))) {
							dol_syslog(__METHOD__ . ": Entity mismatch on supplier invoice " . $doc_id, LOG_WARNING);
							$error++; break;
						}

						// Retrieve thirdparty info for the first document to populate the bank line
						if ($first_socid == 0) {
							$first_socid = $invoice->fk_soc;
							$invoice->fetch_thirdparty();
							$emitter_name = is_object($invoice->thirdparty) ? $invoice->thirdparty->name : '';

						// Strict blocking if the third party is different for the following documents
						} elseif ($first_socid != $invoice->fk_soc) {
							dol_syslog(__METHOD__ . ": Documents from different third parties detected.", LOG_ERR);
							$error++; break;
						}

						$pay_amount = abs((float)$amount);
						if ($invoice->type == 2 || $invoice->total_ttc < 0) $pay_amount = -$pay_amount;

						$valid_amounts[$doc_id] = $pay_amount;

						// Handle multicurrency different change
						if (!empty($this->multicurrency_enabled) && $qonto_local_amount != 0) {
							if (
								$invoice->multicurrency_code != $conf->currency && 
								!empty($qonto_trx['local_currency']) && 
								strtoupper($invoice->multicurrency_code) === strtoupper($qonto_trx['local_currency'])
							) {
								$autoclose = 0;
								$doc_index++;
								$is_last_doc = ($doc_index === $total_docs);
								$mc_remain = isset($mc_remain_by_doc[$doc_id]) ? $mc_remain_by_doc[$doc_id] : 0;
                                
								if ($is_perfect_mc_match) {
									$mc_imput = abs($mc_remain);
                                } else {
                                    // Case of pure partial payment: proportional distribution
									if ($eur_remaining > 0) {
                                        $qonto_imput_tx = abs($amount) / $eur_remaining;
                                        $mc_imput = $qonto_imput_tx * $mc_remaining;
                                    } else {
                                        $mc_imput = 0;
                                    }
                                }
                                
								$mc_imput = price2num($mc_imput, 'MT');

								if ($is_last_doc && abs(abs($amount) - $eur_remaining) <= 0.01) {
                                    $mc_imput = $mc_remaining;
                                }

                                // Safeguard: the amount of currency allocated cannot exceed the balance remaining in the overall transaction
                                if ($mc_imput > abs($mc_remaining)) {
                                    $mc_imput = abs($mc_remaining);
                                }
                                
                                // The currency used for the next loop iteration is determined
								$eur_remaining = price2num($eur_remaining - abs($amount), 'MT');
                                $mc_remaining = price2num($mc_remaining - $mc_imput, 'MT');

								// Prepare for payment
								$mc_pay_amount = $mc_imput;
								if ($invoice->type == 2 || $invoice->total_ttc < 0) $mc_pay_amount = -$mc_pay_amount;
								$valid_mc_amounts[$doc_id] = $mc_pay_amount;
							}
						}

						$payment_label_ref .= $invoice->ref . ', ';
					} else {
						$error++;
					}
				}
				break;
			
			case 'vat':
				// Security check: Tax & Social module is enabled
                if (!isModEnabled('tax')) { dol_syslog(__METHOD__ . ": Tax/Social module disabled.", LOG_ERR); $error++; break; }

				// Declare payment and tax classes
				if (!class_exists('PaymentVat')) require_once DOL_DOCUMENT_ROOT.'/compta/tva/class/paymentvat.class.php';
				if (!class_exists('Tva')) require_once DOL_DOCUMENT_ROOT.'/compta/tva/class/tva.class.php';
				
				// Initialize payment
				$payment = new PaymentVat($this->db);
                $payment_type_str = 'payment_vat';
                $payment_label_prefix = '(VATPayment) ';

                $valid_amounts = array();

				// Get the unique VAT element
                reset($amounts_array);
                $doc_id = key($amounts_array);
                $amount = current($amounts_array);

                $vat = new Tva($this->db);
                if ($vat->fetch($doc_id) > 0) {

					// Check if the vat is already marked as paid
					if ($vat->paye == 1) {
						dol_syslog(__METHOD__ . ": Error, the VAT declaration " . $doc_id . " is already marked as paid.", LOG_ERR);
						$error++;
						break;
					}	

					// Multi-Company Security (IDOR)
					$doc_entity = isset($vat->entity) ? $vat->entity : null;
					if ($doc_entity === null) {
						$resql = $this->db->query("SELECT entity FROM " . MAIN_DB_PREFIX . "tva WHERE rowid = " . (int)$doc_id);
						if ($resql && $obj = $this->db->fetch_object($resql)) $doc_entity = $obj->entity;
					}
					if ($doc_entity !== null && $doc_entity != $conf->entity && (!function_exists('getEntity') || !in_array((string)$doc_entity, explode(',', getEntity('tva'))))) {
						dol_syslog(__METHOD__ . ": Entity mismatch on VAT " . $doc_id, LOG_WARNING);
						$error++; break;
					}

                    $pay_amount = abs((float)$amount);
                    if ($vat->amount < 0) $pay_amount = -$pay_amount;
                    
                    $valid_amounts[$doc_id] = $pay_amount;
                    $payment_label_ref = $vat->ref;
                } else {
                    $error++;
                }
				break;
			
			case 'social':
                // Security check: Tax & Social module is enabled
                if (!isModEnabled('tax')) { dol_syslog(__METHOD__ . ": Tax/Social module disabled.", LOG_ERR); $error++; break; }

                // Declare payment and social classes
                if (!class_exists('PaymentSocialContribution')) require_once DOL_DOCUMENT_ROOT.'/compta/sociales/class/paymentsocialcontribution.class.php';
                if (!class_exists('ChargeSociales')) require_once DOL_DOCUMENT_ROOT.'/compta/sociales/class/chargesociales.class.php';

                // Initialize payment
                $payment = new PaymentSocialContribution($this->db);
                $payment_type_str = 'payment_sc';
                $payment_label_prefix = '(SocialPayment) ';

                $valid_amounts = array();

                // Get the unique social element
                reset($amounts_array);
                $doc_id = key($amounts_array);
                $amount = current($amounts_array);

                $charge = new ChargeSociales($this->db);
                if ($charge->fetch($doc_id) > 0) {

					// Check if the social is already marked as paid
					if ($charge->paye == 1) {
						dol_syslog(__METHOD__ . ": Error, the social charge " . $doc_id . " is already marked as paid.", LOG_ERR);
						$error++;
						break;
					}

					// Multi-Company Security (IDOR)
					$doc_entity = isset($charge->entity) ? $charge->entity : null;
					if ($doc_entity === null) {
						$resql = $this->db->query("SELECT entity FROM " . MAIN_DB_PREFIX . "chargesociales WHERE rowid = " . (int)$doc_id);
						if ($resql && $obj = $this->db->fetch_object($resql)) $doc_entity = $obj->entity;
					}
					if ($doc_entity !== null && $doc_entity != $conf->entity && (!function_exists('getEntity') || !in_array((string)$doc_entity, explode(',', getEntity('tax'))))) {
						dol_syslog(__METHOD__ . ": Entity mismatch on Social Charge " . $doc_id, LOG_WARNING);
						$error++; break;
					}

                    $valid_amounts[$doc_id] = abs((float)$amount);
                    $payment_label_ref = $charge->ref;
                } else { 
                    $error++; 
                }
                break;

			case 'salary':
                // Security check: Salaries module is enabled
                if (!isModEnabled('salaries')) { dol_syslog(__METHOD__ . ": Salary module disabled.", LOG_ERR); $error++; break; }

                // Declare payment and salary classes
                if (!class_exists('PaymentSalary')) require_once DOL_DOCUMENT_ROOT.'/salaries/class/paymentsalary.class.php';
                if (!class_exists('Salary')) require_once DOL_DOCUMENT_ROOT.'/salaries/class/salary.class.php';

                // Initialize payment
                $payment = new PaymentSalary($this->db);
                $payment_type_str = 'payment_salary';
                $payment_label_prefix = '(SalaryPayment) ';

                $valid_amounts = array();

                // Get the unique salary element
                reset($amounts_array);
                $doc_id = key($amounts_array);
                $amount = current($amounts_array);

                $salary = new Salary($this->db);
                if ($salary->fetch($doc_id) > 0) {

					// Check if the salary is already marked as paid
					if ($salary->paye == 1) {
						dol_syslog(__METHOD__ . ": Error, the salary " . $doc_id . " is already marked as paid.", LOG_ERR);
						$error++;
						break;
					}

					// Multi-Company Security (IDOR)
					$doc_entity = isset($salary->entity) ? $salary->entity : null;
					if ($doc_entity === null) {
						$resql = $this->db->query("SELECT entity FROM " . MAIN_DB_PREFIX . "salary WHERE rowid = " . (int)$doc_id);
						if ($resql && $obj = $this->db->fetch_object($resql)) $doc_entity = $obj->entity;
					}
					if ($doc_entity !== null && $doc_entity != $conf->entity && (!function_exists('getEntity') || !in_array((string)$doc_entity, explode(',', getEntity('salary'))))) {
						dol_syslog(__METHOD__ . ": Entity mismatch on Salary " . $doc_id, LOG_WARNING);
						$error++; break;
					}

                    $valid_amounts[$doc_id] = abs((float)$amount);
                    
                    // Create the reference text for the bank line
                    $start = dol_print_date($salary->datesp, 'day');
                    $end = dol_print_date($salary->dateep, 'day');
                    $period = $start . " - " . $end;
                    
                    $payment_label_ref = !empty($salary->label) ? $salary->label : "Sal. " . $period;
                } else { 
                    $error++; 
                }
                break;

            case 'expense':
                // Security check: Expense Report module is enabled
                if (!isModEnabled('expensereport')) { dol_syslog(__METHOD__ . ": Expense Report module disabled.", LOG_ERR); $error++; break; }

                // Declare payment and expense report classes
                if (!class_exists('PaymentExpenseReport')) require_once DOL_DOCUMENT_ROOT.'/expensereport/class/paymentexpensereport.class.php';
                if (!class_exists('ExpenseReport')) require_once DOL_DOCUMENT_ROOT.'/expensereport/class/expensereport.class.php';
                
                // Initialize payment
                $payment = new PaymentExpenseReport($this->db);
                $payment_type_str = 'payment_expensereport';
                $payment_label_prefix = '(ExpensePayment) ';
                
                $valid_amounts = array();

                // Get the unique expense report element
                reset($amounts_array);
                $doc_id = key($amounts_array);
                $amount = current($amounts_array);

                $expense = new ExpenseReport($this->db);
                if ($expense->fetch($doc_id) > 0) {

					// Check if the expense is already marked as paid
					if ($expense->paye == 1) {
						dol_syslog(__METHOD__ . ": Error, the expense report " . $doc_id . " is already marked as paid.", LOG_ERR);
						$error++;
						break;
					}

					// Multi-Company Security (IDOR)
					$doc_entity = isset($expense->entity) ? $expense->entity : null;
					if ($doc_entity === null) {
						$resql = $this->db->query("SELECT entity FROM " . MAIN_DB_PREFIX . "expensereport WHERE rowid = " . (int)$doc_id);
						if ($resql && $obj = $this->db->fetch_object($resql)) $doc_entity = $obj->entity;
					}
					if ($doc_entity !== null && $doc_entity != $conf->entity && (!function_exists('getEntity') || !in_array((string)$doc_entity, explode(',', getEntity('expensereport'))))) {
						dol_syslog(__METHOD__ . ": Entity mismatch on Expense Report " . $doc_id, LOG_WARNING);
						$error++; break;
					}

                    $valid_amounts[$doc_id] = abs((float)$amount);
                    $payment_label_ref = $expense->ref;
                } else { 
                    $error++; 
                }
                break;
			
			default:
				break;
		}
		
        // Global payment
        if ($error == 0 && $type !== 'bank' && is_object($payment)) {
            
            // Properties common to all payments
            
            // 1. Bank account assignment (according to the classes)
            $payment->fk_account = $bank_account_id;
            $payment->fk_bank = $bank_account_id;

            // 2. Massive assignment of payment method to counter "OperNotDefined"
            $payment->paiementid = $mop;       // For Customer/Supplier Invoices
            $payment->paiementtype = $mop;     // For VAT
            $payment->fk_typepayment = $mop;   // For Salaries, Social Charges and Expense Reports
            
            $payment->datepaye = strtotime($qonto_trx['settled_at']);
            $payment->datep = strtotime($qonto_trx['settled_at']); // Specifically required by PaymentExpenseReport and PaymentSalary
            $payment->num_payment = $num_chq;
            $payment->amounts = $valid_amounts;

            $payment_label_ref = rtrim($payment_label_ref, ', ');

            // Specificity for single-document payment classes that ignore array keys in create()
            reset($valid_amounts);
            $first_doc_id = key($valid_amounts);
            
            if ($type === 'vat' || $type === 'social') {
                $payment->chid = $first_doc_id;
            } elseif ($type === 'expense') {
                $payment->fk_expensereport = $first_doc_id;
            }
			

            // Dolibarr payment creation (arg 1 = auto close document)
            $run = $payment->create($user, $autoclose);
            if ($run > 0) {
                
                // Push the newly created payment into the bank entries (AccountLine)
                $bank_line = $payment->addPaymentToBank(
                    $user,
                    $payment_type_str,
                    $payment_label_prefix . $payment_label_ref,
                    $bank_account_id,
                    $emitter_name,
                    $emitter_bank_name
                );
                
                // Workaround PaymentVAT::addPaymentToBank() returns '1' instead of the ID of the created bank line
                if (in_array($type, array('vat', 'salary', 'social', 'expense')) && $bank_line == 1) {
                    $payment->fetch($payment->id);
                    $bank_line = $payment->fk_bank;
                }
                
                // If the bank account is correctly created, we update it with the Qonto information and link it
                if($bank_line > 0) {
                    if (!class_exists('AccountLine')) require_once DOL_DOCUMENT_ROOT.'/compta/bank/class/account.class.php';
                    $line = new AccountLine($this->db);				
                    
                    if ($line->fetch($bank_line) > 0) {
                        $line->label = isset($qonto_trx['label']) ? $qonto_trx['label'] : $payment_label_prefix . $payment_label_ref;
                        $line->datev = strtotime($qonto_trx['settled_at']);
                        $line->dateo = isset($qonto_trx['emitted_at']) ? strtotime($qonto_trx['emitted_at']) : strtotime($qonto_trx['settled_at']);
                        
                        $update = $line->update($user);
                        if ($update > 0) {
                            $run = $this->linkTransaction($bank_line, $qonto_trx['id'], $ref_releve);
                            if ($run < 0) { $error++; }
                            
                            // Expense payment: Dolibarr doesn't close it automatically, so we do it manually.
                            if ($type === 'expense' && $error === 0) {
                                if (!class_exists('ExpenseReport')) require_once DOL_DOCUMENT_ROOT.'/expensereport/class/expensereport.class.php';
                                $expense_obj = new ExpenseReport($this->db);
                                $res_paid = $expense_obj->setPaid($first_doc_id, $user);
                                if ($res_paid < 0) { $error++; }
                            }

							// Multi-currency, close document if paid (Force closure for exchange rate differences)
							if (!empty($this->multicurrency_enabled) && !empty($valid_mc_amounts)) {
                                
                                // Type (Customer or Supplier)
                                $table_pf = ($type === 'cinv') ? 'paiement_facture' : 'paiementfourn_facturefourn';
                                $field_p  = ($type === 'cinv') ? 'fk_paiement' : 'fk_paiementfourn';
                                $field_f  = ($type === 'cinv') ? 'fk_facture' : 'fk_facturefourn';
								                                
                                // Single loop for foreign currency payments
                                foreach ($valid_mc_amounts as $fk_doc => $mc_amount) {
                                    
                                    // Declare class to call
									 if ($type === 'cinv') {
                                        if (!class_exists('Facture')) require_once DOL_DOCUMENT_ROOT.'/compta/facture/class/facture.class.php';
                                        $inv_to_close = new Facture($this->db);
                                    } else {
                                        if (!class_exists('FactureFournisseur')) require_once DOL_DOCUMENT_ROOT.'/fourn/class/fournisseur.facture.class.php';
                                        $inv_to_close = new FactureFournisseur($this->db);
                                    }
                                    
                                    // 1. SQL Byoass on payments
                                    $mc_code_db = $this->db->escape($qonto_trx['local_currency']);
                                    $imputed_eur = isset($valid_amounts[$fk_doc]) ? abs($valid_amounts[$fk_doc]) : 0;
                                    $mc_tx_db = ($imputed_eur != 0) ? (abs($mc_amount) / $imputed_eur) : 1;
									if ($inv_to_close->fetch($fk_doc) > 0) {
                                        $mc_code_db = $this->db->escape($inv_to_close->multicurrency_code);
                                    }
                                    
                                    $sql_mc = "UPDATE " . MAIN_DB_PREFIX . $table_pf . " ";
                                    $sql_mc .= "SET multicurrency_amount = " . (float)price2num($mc_amount, 'MT') . ", ";
                                    $sql_mc .= "    multicurrency_code = '" . $mc_code_db . "', ";
                                    $sql_mc .= "    multicurrency_tx = " . $mc_tx_db . " ";
                                    $sql_mc .= "WHERE " . $field_p . " = " . (int)$payment->id . " AND " . $field_f . " = " . (int)$fk_doc;
                                    
                                    $res_mc = $this->db->query($sql_mc);
                                    if (!$res_mc) {
                                        dol_syslog(__METHOD__ . ": Error while correcting the currency", LOG_ERR);
                                        $error++;
                                    }
                                    
                                    // 2. Smart closing management based on actual remaining balances
                                    if ($inv_to_close->fetch($fk_doc) > 0) {
                                        $new_remain_eur = (float)$inv_to_close->getRemainToPay();
                                        
                                        $mc_total = (float)$inv_to_close->multicurrency_total_ttc;
                                        $mc_already_paid = $qontoRepo->getMulticurrencyPaidAmount($fk_doc, $type);
                                        $new_remain_mc = $mc_total - $mc_already_paid;

                                        // CASE A: Everything balances perfectly to zero (EUR and foreign currency) -> Standard closing
                                        if (abs($new_remain_eur) <= 0.01 && abs($new_remain_mc) <= 0.01) {
                                            $res_paid = $inv_to_close->setPaid($user);
                                            if ($res_paid < 0) { $error++; }
                                        } 
                                        // CASE B: Fully paid in foreign currency, but a difference in EUR remains -> Exchange rate difference
                                        elseif (abs($new_remain_mc) <= 0.01 && abs($new_remain_eur) > 0.01) {
                                            $res_paid = $inv_to_close->setPaid($user, 'ExchangeRate', 'Exchange diff (MC full) - QontoSync');
                                            if ($res_paid < 0) { $error++; }
                                        } 
                                    }

									$final_parent_mc_amount = ($type === 'sinv') ? -$qonto_local_amount : $qonto_local_amount;

									$table_parent = ($type === 'cinv') ? 'paiement' : 'paiementfourn';
									$sql_parent = "UPDATE " . MAIN_DB_PREFIX . $table_parent . " ";
									$sql_parent .= "SET multicurrency_amount = " . (float)price2num($final_parent_mc_amount, 'MT') . " ";
									$sql_parent .= "WHERE rowid = " . (int)$payment->id;

									$res_parent = $this->db->query($sql_parent);
                                    if (!$res_parent) {
                                        dol_syslog(__METHOD__ . ": Error while correcting the currency", LOG_ERR);
                                        $error++;
                                    }
                                }
                            }

                        } else {
                            dol_syslog(__METHOD__ . ": Error updating bank line: " . $line->error, LOG_ERR);
                            $error++;
                        }
                    } else {
                        dol_syslog(__METHOD__ . ": Error fetching bank line: " . $line->error, LOG_ERR);
                        $error++;
                    }
                } else {
                    dol_syslog(__METHOD__ . ": Error pushing payment to the bank: ".$payment->error, LOG_ERR);
                    $error++;
                }
            } else {
                dol_syslog(__METHOD__ . ": Error creating the payment: ".$payment->error, LOG_ERR);
                $error++;
            }
        }
	
        // Finalize the transaction
		if ($error === 0) {
			$this->db->commit();
			dol_syslog(__METHOD__ . ": Transaction Qonto ID (".$qonto_trx['id'].") matched successfully.", LOG_INFO);
			return 1;
		} else {
			$this->db->rollback();
			return -1;
		}
	}

	/**
	 * Download missing statements from Qonto to Dolibarr GED
	 *
	 * @param Account  $account Bank account object
	 * @param int      $year    Year to analyze
	 * @param QontoApi $api     Instantiated Qonto API object
	 * @return array            Array with success, error, and skipped counts
	 */
	public function syncStatementsGED($account, $year, $api)
	{
		global $conf;

		$result = array(
			'success_count'  => 0,
			'error_count'    => 0,
			'already_exists' => 0,
			'api_error'      => false,
			'valid_refs'     => array()
		);

		// 1. Retrieving the list of statements from the Qonto API
		$qonto_statements_raw = $api->getStatements($account->iban, $year);

		if ($qonto_statements_raw === false) {
			$result['api_error'] = true;
			return $result;
		}

		// 2. Indexing of statements by period ("05-2024")
		$indexed_statements = array();
		if (is_array($qonto_statements_raw)) {
			foreach ($qonto_statements_raw as $stmt) {
				if (!empty($stmt['period'])) {
					$indexed_statements[$stmt['period']] = $stmt;
				}
			}
		}

		// 3. Format definition
		$format_setting = getDolGlobalString('QONTOSYNC_STATEMENT_FORMAT') !== '' ? getDolGlobalString('QONTOSYNC_STATEMENT_FORMAT') : '{Y}-{m}';

		// 4. Download loop
		for ($m = 1; $m <= 12; $m++) {
			$period_key = sprintf("%02d-%04d", $m, $year);

			if (isset($indexed_statements[$period_key])) {
				$stmt = $indexed_statements[$period_key];
				$statement_url = $stmt['file']['file_url'];

				// Calculating the exact file name
				$filter_start_month = sprintf("%04d-%02d-01", $year, $m);
				$time_month = strtotime($filter_start_month);
				$raw_ref = preg_replace_callback('/\{([^}]+)\}/', function($matches) use ($time_month) {
					return date($matches[1], $time_month);
				}, $format_setting);
				$clean_ref = dol_sanitizeFileName($raw_ref);
				$ref_releve = substr($clean_ref, 0, 50);
				
				$dir_output = $conf->bank->dir_output . '/' . $account->id . '/statement/' . $ref_releve;
				if (!file_exists($dir_output)) {
					dol_mkdir($dir_output);
				}

				$filename = $ref_releve . '.pdf';
				$dest_file_path = $dir_output . '/' . $filename;

				// If the file already exists locally, it is ignored.
				if (file_exists($dest_file_path)) {
					$result['already_exists']++;
					$result['valid_refs'][] = $ref_releve;
					continue;
				}

				// Secure download
				if (!empty($statement_url)) {

					$fp = fopen($dest_file_path, 'w+');
					if ($fp === false) {
						$result['error_count']++;
						dol_syslog(__METHOD__ . ": Unable to open the file for writing : " . $dest_file_path, LOG_ERR);
						continue;
					}

					$ch = curl_init();

					curl_setopt($ch, CURLOPT_URL, $statement_url);
					curl_setopt($ch, CURLOPT_FILE, $fp);
					
					curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
					curl_setopt($ch, CURLOPT_MAXREDIRS, 3);

					curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 10);
					curl_setopt($ch, CURLOPT_TIMEOUT, 60);
					
					curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
					curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 2);
					if (defined('CURLOPT_PROTOCOLS_STR')) {
						curl_setopt($ch, CURLOPT_PROTOCOLS_STR, 'HTTPS');
					} else {
						curl_setopt($ch, CURLOPT_PROTOCOLS, CURLPROTO_HTTPS);
					}
					
					curl_exec($ch);
					$http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
					
					curl_close($ch);
					fclose($fp);

					if ($http_code == 200) {
						if (!empty($conf->global->MAIN_UMASK)) {
							@chmod($dest_file_path, octdec($conf->global->MAIN_UMASK));
						}
						$result['success_count']++;
						$result['valid_refs'][] = $ref_releve;
						dol_syslog(__METHOD__ . ": Statement downloaded successfully for period " . $period_key . " to " . $dest_file_path, LOG_INFO);
					} else {
						@unlink($dest_file_path);
						$result['error_count']++;
						dol_syslog(__METHOD__ . ": Failed to download statement from Qonto. HTTP Code: " . $http_code, LOG_ERR);
					}
				}
			}
		}

		return $result;
	}
}