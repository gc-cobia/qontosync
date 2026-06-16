<?php
/*
 * QontoSync dolibarr module 
 * @file       qontomatching.class.php
 * @author     Gaëtan COURTOIS
 * @license    GPLv3
 */

class QontoMatching
{
	public $db;
	private $links_cache = array(); // PHP cache to store already linked Qonto IDs

	public function __construct($db)
	{
		$this->db = $db;
	}
	
	/**
	 * Single function to query the database (DRY principle).
	 * If $qonto_id is provided, it searches for a specific ID. Otherwise, it loads the entire account.
	 * * @param int $bank_id
	 * @param string|null $qonto_id
	 * @return array Associative array [qonto_id => fk_object]
	 */
	private function _fetchLinksFromDB($bank_id, $qonto_id = null)
	{
		$sql = "SELECT ef.qonto_id, ef.fk_object ";
		$sql .= "FROM " . MAIN_DB_PREFIX . "bank_extrafields as ef ";
		$sql .= "JOIN " . MAIN_DB_PREFIX . "bank as b ON ef.fk_object = b.rowid ";
		$sql .= "WHERE b.fk_account = " . (int) $bank_id . " ";
		
		if ($qonto_id !== null) {
			$sql .= "AND ef.qonto_id = '" . $this->db->escape($qonto_id) . "'";
		} else {
			$sql .= "AND ef.qonto_id IS NOT NULL AND ef.qonto_id != ''";
		}

		$resql = $this->db->query($sql);
		$results = array();
		
		if ($resql) {
			while ($obj = $this->db->fetch_object($resql)) {
				// Handle duplicates in the database to prevent linking errors
				if (!isset($results[$obj->qonto_id])) {
					$results[$obj->qonto_id] = $obj->fk_object;
				} else {
					$results[$obj->qonto_id] = -1; 
				}
			}
			$this->db->free($resql);
		}
		
		return $results;
	}

	/**
	 * Load existing links into the PHP cache for a given bank account.
	 * * @param int $bank_id
	 * @param bool $force_reload
	 */
	public function loadLinksCache($bank_id, $force_reload = false)
	{
		if ($force_reload || !isset($this->links_cache[$bank_id])) {
			$this->links_cache[$bank_id] = $this->_fetchLinksFromDB($bank_id);
		}
	}

	/**
	 * Verify if a transaction is already linked.
	 *
	 * @param string $qonto_id Qonto transaction ID
	 * @param int $bank_id Bank account id
	 * @param bool $cache Set to false to force a real-time database query
	 * @return int linked_rowid if linked, 0 if not linked, -1 if error (too many links)
	 */
	public function isLinked($qonto_id, $bank_id, $cache = true)
	{
		if (empty($qonto_id) || empty($bank_id)) {
			dol_syslog(__METHOD__ . ": Error on input parameters", LOG_ERR);
			return 0;
		}
		
		// CACHE Mode: Used for batch processing and listing to avoid N+1 query overhead
		if ($cache) {
			$this->loadLinksCache($bank_id);
			return isset($this->links_cache[$bank_id][$qonto_id]) ? $this->links_cache[$bank_id][$qonto_id] : 0;
		
		// REALTIME Mode: Used during insert/update to strictly avoid race conditions
		} else {
			$result = $this->_fetchLinksFromDB($bank_id, $qonto_id);
			return isset($result[$qonto_id]) ? $result[$qonto_id] : 0;
		}
	}

	/**
	 * Get transactions linked or to link to the extrafield qonto_id
	 *
	 * @param array $transaction Qonto transactions array
	 * @param int $bank_id Bank account id
	 * @param bool $fast_mod
	 * @return array Array transactions with trx status and linked_rowid or trx_status = "ErrorTooManyTrx" or trx_status = "unlinked" with trx array
	 */
	public function fetchTransactions($transaction, $bank_id, $fast_mod = false)
	{
		global $conf; // Required to read the tolerance setting from global configuration

		if (empty($transaction) || !is_array($transaction) || empty($bank_id)) {
			dol_syslog(__METHOD__ . ": Error on input parameters", LOG_ERR);
			return array();
		}
		
		// Lazy load utils functions
		if (!class_exists('QontoRepository')) {
			dol_include_once('/qontosync/class/qontorepository.class.php');
		}
		$qontoRepo = new QontoRepository($this->db);
		
		/**
		* Invoices will be loaded only if at least one transaction is unlinked
		*/
		$unpaid_c_invoices = array();
		$unpaid_s_invoices = array();
		$unpaid_vat = array();
		$items_loaded = false;
		
		$entries = array();
		
		// Load tolerance setting from configuration (default to 7 days if not set)
		$tolerance_days = isset($conf->global->QONTOSYNC_TOLERANCE_DAYS) ? (int)$conf->global->QONTOSYNC_TOLERANCE_DAYS : 7;
		
		// 1. Pre-load the links cache for the whole account
		$this->loadLinksCache($bank_id);
		
		// 2. Pre-fetch unlinked bank lines to prevent N+1 queries in the loop
		$unlinked_bank_lines = array();
		$sql_bank = "SELECT b.rowid, b.amount, b.label, b.datev ";
		$sql_bank .= "FROM " . MAIN_DB_PREFIX . "bank as b ";
		$sql_bank .= "LEFT JOIN " . MAIN_DB_PREFIX . "bank_extrafields as ef ON ef.fk_object = b.rowid ";
		$sql_bank .= "WHERE b.fk_account = " . (int)$bank_id . " ";
		$sql_bank .= "AND (ef.qonto_id IS NULL OR ef.qonto_id = '')";
		
		$resql_bank = $this->db->query($sql_bank);
		if ($resql_bank) {
			while ($obj = $this->db->fetch_object($resql_bank)) {
				$unlinked_bank_lines[] = clone $obj;
			}
			$this->db->free($resql_bank);
		}

		foreach($transaction as $t) {
			if (empty($t['id'])) {
				continue;
			}
			
			$t['linked_rowid'] = 0;
			$t['trx'] = array();
			
			$t['amount'] = isset($t['amount']) ? (float)$t['amount'] : 0.0;
			
			// Correct the sign amount due to the side movement
			if (isset($t['side']) && $t['side'] == 'debit') {
				$t['amount'] = -$t['amount'];
			}

			// Verify if transaction is already linked using the cached data
			$link_status = $this->isLinked($t['id'], $bank_id);
			
			if ($link_status === -1) {
				$t["trx_status"] = "errorTooManyTrx";
			} elseif ($link_status > 0) {
				$t["linked_rowid"] = (int)$link_status;
				$t["trx_status"] = "linked";
			} else {
				$t["trx_status"] = "unlinked";
				
				// Lazy load unpaid items ONCE if not already loaded
				if (!$items_loaded) {
					$unpaid_docs = $qontoRepo->getUnpaidDocuments('all');
		
					$unpaid_c_invoices = $unpaid_docs['c_invoices'];
					$unpaid_s_invoices = $unpaid_docs['s_invoices'];
					$unpaid_vat        = $unpaid_docs['vat'];
					
					$items_loaded = true;
				}
				
				// Search for matching amounts AND dates within the pre-fetched unlinked bank lines
				$qonto_amount_bank = (float)$t['amount'];
				
				// Normalize Qonto date to midnight to calculate full days difference
				$qonto_time = strtotime(date('Y-m-d', strtotime($t['settled_at'])));
				
				foreach ($unlinked_bank_lines as $b_line) {
					// Normalize Dolibarr bank line date to midnight
					$bank_time = strtotime(date('Y-m-d', $this->db->jdate($b_line->datev)));
					
					// Calculate the absolute difference in days (86400 seconds = 1 day)
					$diff_days = abs($qonto_time - $bank_time) / 86400;
					
					// Check if the amount matches AND if it falls within the tolerance range
					if ($diff_days <= $tolerance_days && price2num((float)$b_line->amount, 'MT') == price2num($qonto_amount_bank, 'MT')) {
						$t["trx"][] = clone $b_line;
					}
				}
			}
			
			$t["c_invoices"] = array();
			$t["s_invoices"] = array();
			$t["vat"] = array();
			
			if ($t["trx_status"] === "unlinked") {
				$qonto_amount = abs((float)$t['amount']);
				
				// Compare transactions amount with unpaid customers invoices amount
				foreach ($unpaid_c_invoices as $c_inv) {
					if (price2num(abs((float)$c_inv->amount), 'MT') == price2num($qonto_amount, 'MT')) {
						$t["c_invoices"][] = clone $c_inv;
					}
				}
				
				// Compare transactions amount with unpaid suppliers invoices amount
				foreach ($unpaid_s_invoices as $s_inv) {
					if (price2num(abs((float)$s_inv->amount), 'MT') == price2num($qonto_amount, 'MT')) {
						$t["s_invoices"][] = clone $s_inv;
					}
				}

				// Compare transactions amount with unpaid VAT
				foreach ($unpaid_vat as $vat) {
					if (price2num(abs((float)$vat->amount), 'MT') == price2num($qonto_amount, 'MT')) {
						$t["vat"][] = clone $vat;
					}
				}
			}

			// Add entries to the list
			$entries[] = $t;
		}

		// Return the list of entries
		return $entries;
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
		global $user;

		if (empty($bank_line_id) || empty($qonto_id) || empty($ref_releve)) {
			dol_syslog(__METHOD__ . ": Error on input parameters", LOG_ERR);
			return -1;
		}
		
		// Lazy load AccountLine class if needed
		if (!class_exists('AccountLine')) {
			dol_include_once('/compta/bank/class/account.class.php');
		}
		
		$bank = new AccountLine($this->db);
		$res = $bank->fetch($bank_line_id);
		
		// Verify if the bank line exists
		if ($res <= 0) {
			return -1;
		}
		
		// Lazy load ExtraFields class
		if (!class_exists('ExtraFields')) {
			dol_include_once('/core/class/extrafields.class.php');
		}	

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
			
			if ($res <= 0) {
				dol_syslog(__METHOD__ . ": Error creating the bank line extrafield: ".$bank->error, LOG_ERR);
				return -1;
			}
		}
		
		// Update the bank line with the statement reference
		$bank->num_releve = $ref_releve;
		$res = $bank->update_conciliation($user, null, 1);
		if ($res <= 0) {
			dol_syslog(__METHOD__ . ": Error updating the bank line statement: ".$bank->error, LOG_ERR);
			return -1;
		}
	
		return 1;
	}
	
	/**
	* Update selected entry with the Qonto transaction
	*
	* @param string $target
	* @param array $qonto_trx
	* @param int $bank_account_id
	* @return int 1 on success, -1 on error
	*/
	public function update($target, $qonto_trx, $bank_account_id = 0)
	{
		global $conf, $user;
	
		if (empty($target) || empty($qonto_trx) || !is_array($qonto_trx)) {
			dol_syslog(__METHOD__ . ": Error on input parameters", LOG_ERR);
			return false;
		}

		// Explode target string into type & rowid
		$parts = explode('_', $target, 2);
		if (count($parts) == 2) {
			$type = $parts[0];
			$rowid = (int) $parts[1];
		} else {
			return false;
		}

		// Init SQL transaction
		$this->db->begin();

		// Check if Qonto ID already exists (REALTIME check, explicitly bypass cache)
		if ($this->isLinked($qonto_trx['id'], $bank_account_id, false) !== 0) {
			$this->db->rollback();
			dol_syslog(__METHOD__ . ": Transaction ID (".$qonto_trx['id'].") already linked.", LOG_WARNING);
			return -1;
		}

		// Init error counter
		$error = 0;

		// Lazy load QontoRepository functions
		if (!class_exists('QontoRepository')) {
			dol_include_once('/qontosync/class/qontorepository.class.php');
		}
		
		$qontoRepo = new QontoRepository($this->db);
		
		// Define operation type string for legacy code use below
		$op_type = isset($qonto_trx['operation_type']) ? $qonto_trx['operation_type'] : '';
		
		$mop = $qontoRepo->getPaymentModeId(
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
		if ($op_type == 'check') {
			$num_chq = isset($qonto_trx['check']['check_number']) ? $qonto_trx['check']['check_number'] : '';
		} elseif ($op_type == 'direct_debit_collection') {
			$num_chq = isset($qonto_trx['direct_debit_collection']['unique_mandate_reference']) ? $qonto_trx['direct_debit_collection']['unique_mandate_reference'] : '';
		} else {
			$num_chq = isset($qonto_trx['reference']) ? $qonto_trx['reference'] : '';
		}

		// Extract statement reference
		$ref_releve = '';
		if (isset($qonto_trx['settled_at'])) {
			$ref_releve = date('Y-m', strtotime($qonto_trx['settled_at']));
		}
		
		switch ($type) {
			case 'bank':
				// Attempt to link the transaction directly
				$run = $this->linkTransaction($rowid, $qonto_trx['id'], $ref_releve);
				if ($run < 0) {
					dol_syslog(__METHOD__ . ": Error, can not link the transaction", LOG_ERR);
					$error++;
				}
				break;

			case 'cinv':
				// Security check: ensure Customer Invoices module is enabled
				if (empty($conf->facture->enabled)) {
					dol_syslog(__METHOD__ . ": Customer Invoice module is disabled.", LOG_ERR);
					$error++;
					break;
				}

				if (!class_exists('Paiement')) {
					dol_include_once('/compta/paiement/class/paiement.class.php');
				}
				$payment = new Paiement($this->db);
				
				if (!class_exists('Facture')) {
					dol_include_once('/compta/facture/class/facture.class.php');
				}
				$invoice = new Facture($this->db);
				
				if ($invoice->fetch($rowid) <= 0) { $error++; break; }

				// Handle Credit Notes by applying a negative sign to the payment amount
				$pay_amount = abs((float)$qonto_trx['amount']);
				if ($invoice->type == 2 || $invoice->total_ttc < 0) {
					$pay_amount = -$pay_amount;
				}
				
				$payment->datepaye		= strtotime($qonto_trx['settled_at']);
				$payment->fk_account	= $bank_account_id;
				$payment->paiementid	= $mop;
				$payment->num_payment	= $num_chq;
				$payment->amounts		= array($invoice->id => $pay_amount);
				
				// Attempt to create a payment on the invoice (arg 1 = auto close)
				$run = $payment->create($user, 1);
				if ($run > 0) {
					$invoice->fetch($rowid);
					
					// Get customer name for emitter
					$invoice->fetch_thirdparty();
					$emitter_name = is_object($invoice->thirdparty) ? $invoice->thirdparty->name : '';

					// Attempt to push the payment to the bank					
					$bank_line = $payment->addPaymentToBank(
						$user,
						'payment',
						'(Payment) ' . $invoice->ref,
						$bank_account_id,
						$emitter_name,
						$emitter_bank_name
					);
					
					if($bank_line > 0) {
						if (!class_exists('AccountLine')) {
							dol_include_once('/compta/bank/class/account.class.php');
						}
						$line = new AccountLine($this->db);				
						
						if ($line->fetch($bank_line) > 0) {
							// Complete bank line with Qonto data
							$line->label	= isset($qonto_trx['label']) ? $qonto_trx['label'] : '(Payment) ' . $invoice->ref;
							$line->datev	= strtotime($qonto_trx['settled_at']);
							$line->dateo	= isset($qonto_trx['emitted_at']) ? strtotime($qonto_trx['emitted_at']) : strtotime($qonto_trx['settled_at']);
							
							$update = $line->update($user);
							
							if ($update > 0) {
								$run = $this->linkTransaction($bank_line, $qonto_trx['id'], $ref_releve);
								if ($run < 0) { $error++; }
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
				break;
				
			case 'sinv':
				// Security check: ensure Supplier Invoices module is enabled
				if (empty($conf->fournisseur->enabled)) {
					dol_syslog(__METHOD__ . ": Supplier Invoice module is disabled.", LOG_ERR);
					$error++;
					break;
				}
				
				if (!class_exists('PaiementFourn')) {
					dol_include_once('/fourn/class/paiementfourn.class.php');
				}
				$payment = new PaiementFourn($this->db);
				
				if (!class_exists('FactureFournisseur')) {
					dol_include_once('/fourn/class/fournisseur.facture.class.php');
				}
				$invoice = new FactureFournisseur($this->db);
				
				if ($invoice->fetch($rowid) <= 0) { $error++; break; }

				// Handle Credit Notes by applying a negative sign to the payment amount
				$pay_amount = abs((float)$qonto_trx['amount']);
				if ($invoice->type == 2 || $invoice->total_ttc < 0) {
					$pay_amount = -$pay_amount;
				}
				
				$payment->datepaye		= strtotime($qonto_trx['settled_at']);
				$payment->fk_account	= $bank_account_id;
				$payment->paiementid	= $mop;
				$payment->num_payment	= $num_chq;
				$payment->amounts		= array($invoice->id => $pay_amount);
				
				// Attempt to create a payment on the supplier invoice (arg 1 = auto close)
				$run = $payment->create($user, 1);
				if ($run > 0) {
					$invoice->fetch($rowid);
					
					// Get supplier name for emitter if not found in API
					$invoice->fetch_thirdparty();
					if (empty($emitter_name)) {
						$emitter_name = is_object($invoice->thirdparty) ? $invoice->thirdparty->name : '';
					}
					
					// Attempt to push the payment to the bank
					$bank_line = $payment->addPaymentToBank(
						$user,
						'payment_supplier',
						'(Payment) ' . $invoice->ref,
						$bank_account_id,
						$emitter_name,
						$emitter_bank_name
					);
					
					if($bank_line > 0) {
						if (!class_exists('AccountLine')) {
							dol_include_once('/compta/bank/class/account.class.php');
						}
						$line = new AccountLine($this->db);
						
						if ($line->fetch($bank_line) > 0) {
							// Complete bank line with Qonto data
							$line->label	= isset($qonto_trx['label']) ? $qonto_trx['label'] : '(Payment) ' . $invoice->ref;
							$line->datev	= strtotime($qonto_trx['settled_at']);
							$line->dateo	= isset($qonto_trx['emitted_at']) ? strtotime($qonto_trx['emitted_at']) : strtotime($qonto_trx['settled_at']);
							
							$update = $line->update($user);
							
							if ($update > 0) {
								$run = $this->linkTransaction($bank_line, $qonto_trx['id'], $ref_releve);
								if ($run < 0) { $error++; }
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
				break;
			
			case 'vat':
				// Security check: ensure Tax module is enabled
				if (empty($conf->tax->enabled)) {
					dol_syslog(__METHOD__ . ": Tax/VAT module is disabled.", LOG_ERR);
					$error++;
					break;
				}

				if (!class_exists('PaymentVat')) {
					dol_include_once('/compta/tva/class/paymentvat.class.php');
				}
				$payment = new PaymentVat($this->db);
				
				if (!class_exists('Tva')) {
					dol_include_once('/compta/tva/class/tva.class.php');
				}
				$vat = new Tva($this->db);

				if ($vat->fetch($rowid) <= 0) { $error++; break; }

				// Handle negative VAT (e.g., VAT refund)
				$pay_amount = abs((float)$qonto_trx['amount']);
				if ($vat->amount < 0) {
					$pay_amount = -$pay_amount;
				}
				
				$payment->datepaye			= strtotime($qonto_trx['settled_at']);
				$payment->fk_bank			= $bank_account_id;
				$payment->paiementtype		= $mop;
				$payment->num_payment		= $num_chq;
				$payment->amounts			= array($vat->id => $pay_amount);
				$payment->chid				= $rowid;
				
				// Attempt to create a payment on the vat (arg 1 = auto close)
				$run = $payment->create($user, 1);
				if ($run > 0) {
					$vat->fetch($rowid);
					
					// Get emitter name for emitter if not found in API
					$vat->fetch_thirdparty();
					if (empty($emitter_name)) {
						$emitter_name = is_object($vat->thirdparty) ? $vat->thirdparty->name : '';
					}
					
					// Attempt to push the payment to the bank
					$bank_line = $payment->addPaymentToBank(
						$user,
						'payment_vat',
						'(VATPayment) #' . $vat->ref,
						$bank_account_id,
						$emitter_name,
						$emitter_bank_name
					);
					
					// Workaround: PaymentVAT::addPaymentToBank() always returns 1 on success instead of bank_line_id
					if ($bank_line > 0) {
						$payment->fetch($payment->id);
						$bank_line = $payment->fk_bank;
					}
					
					if($bank_line > 0) {
						if (!class_exists('AccountLine')) {
							dol_include_once('/compta/bank/class/account.class.php');
						}
						$line = new AccountLine($this->db);
						
						if ($line->fetch($bank_line) > 0) {
							// Complete bank line with Qonto data
							$line->label	= isset($qonto_trx['label']) ? $qonto_trx['label'] : '(Payment) ' . $vat->ref;
							$line->datev	= strtotime($qonto_trx['settled_at']);
							$line->dateo	= isset($qonto_trx['emitted_at']) ? strtotime($qonto_trx['emitted_at']) : strtotime($qonto_trx['settled_at']);
							
							$update = $line->update($user);
							
							if ($update > 0) {
								$run = $this->linkTransaction($bank_line, $qonto_trx['id'], $ref_releve);
								if ($run < 0) { $error++; }
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
				break;
			
			default:
				break;
		}
	
		// Finalizing the transaction
		if ($error === 0) {
			$this->db->commit();
			dol_syslog(__METHOD__ . ": Transaction Qonto ID (".$qonto_trx['id'].") successfully matched with target ".$target, LOG_INFO);
			return 1;
		} else {
			$this->db->rollback();
			return -1;
		}
	}
}