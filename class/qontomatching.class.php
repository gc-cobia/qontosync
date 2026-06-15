<?php
/*
 * QontoSync dolibarr module 
 * @file       qontomatching.class.php
 * @author     Gaëtan COURTOIS
 * @license    GPLv3
 */

dol_include_once('/core/class/extrafields.class.php');
dol_include_once('/compta/bank/class/account.class.php');
dol_include_once('/compta/facture/class/facture.class.php');
dol_include_once('/compta/paiement/class/paiement.class.php');
dol_include_once('/compta/tva/class/tva.class.php');
dol_include_once('/compta/tva/class/paymentvat.class.php');
dol_include_once('/fourn/class/fournisseur.facture.class.php');
dol_include_once('/fourn/class/paiementfourn.class.php');

dol_include_once('/qontosync/class/qontorepository.class.php');


class QontoMatching
{
	public $db;

	public function __construct($db)
	{
		$this->db = $db;
	}

	/**
	 * Verify if a transaction is already linked
	 *
	 * @param string $qonto_id Qonto transaction ID
	 * @param int $bank_id Bank account id
	 * @return int linked_rowid if linked, 0 if not linked, -1 if error (too many links)
	 */
	public function isLinked($qonto_id, $bank_id)
	{
		if (empty($qonto_id) || empty($bank_id)) {
			dol_syslog(__METHOD__ . ": Error on input parameters", LOG_ERR);
			return 0;
		}
		
		$sql = "SELECT ef.fk_object ";
		$sql .= "FROM " . MAIN_DB_PREFIX . "bank_extrafields as ef ";
		$sql .= "JOIN " . MAIN_DB_PREFIX . "bank as b ON ef.fk_object = b.rowid ";
		$sql .= "WHERE ef.qonto_id = '" . $this->db->escape($qonto_id) . "' ";
		$sql .= "AND b.fk_account = " . (int) $bank_id;
		
		$resql = $this->db->query($sql);
		
		if ($resql) {
			$num_rows = $this->db->num_rows($resql);
			
			if ($num_rows == 1) {
				$obj = $this->db->fetch_object($resql);
				$linked_id = $obj->fk_object;
				$this->db->free($resql);
				return $linked_id;
			} elseif ($num_rows > 1) {
				$this->db->free($resql);
				return -1;
			}
			$this->db->free($resql);
		}
		
		return 0;
	}

	/**
	 * Get transactions linked or to link to the extrafield qonto_id
	 *
	 * @param array $transaction Qonto transactions array
	 * @param int $bank_id Bank account id
	 * @return array Array transactions with trx status and linked_rowid or trx_status = "ErrorTooManyTrx" or trx_status = "unlinked" with trx array
	 */
	public function fetchTransactions($transaction, $bank_id, $fast_mod = false)
	{
		if (empty($transaction) || !is_array($transaction) || empty($bank_id)) {
			dol_syslog(__METHOD__ . ": Error on input parameters", LOG_ERR);
			return array();
		}

		# Tempory fast mode: only not fast
		
		// Load utils functions
		$qontoRepo = new QontoRepository($this->db);
		
		/**
		* Invoices will be loaded only if at least one transaction is unlinked
		*/
		$unpaid_c_invoices = array();
		$unpaid_s_invoices = array();
		$unpaid_vat = array();
		$items_loaded = false;
		
		$entries = array();

		foreach($transaction as $t) {
			if (empty($t['id'])) {
				continue;
			}
			
			$t['linked_rowid'] = 0;
			$t['trx'] = array();
			
			$t['amount'] = isset($t['amount']) ? (float)$t['amount'] : 0.0;
			
			// Correct the sign amount due to the side mouvement
			if (isset($t['side']) && $t['side'] == 'debit') {
				$t['amount'] = -$t['amount'];
			}

			// Verify if transaction is already linked in the selected bank account
			$link_status = $this->isLinked($t['id'], $bank_id);
			
			if ($link_status === -1) {
				$t["trx_status"] = "errorTooManyTrx";
			} elseif ($link_status > 0) {
				$t["linked_rowid"] = (int)$link_status;
				$t["trx_status"] = "linked";
			} else {
				$t["trx_status"] = "unlinked";
				
				// Load unpaid items ONCE if not already loaded
				if (!$items_loaded) {
					$unpaid_docs = $qontoRepo->getUnpaidDocuments('all');
		
					$unpaid_c_invoices = $unpaid_docs['c_invoices'];
					$unpaid_s_invoices = $unpaid_docs['s_invoices'];
					$unpaid_vat        = $unpaid_docs['vat'];
					
					$items_loaded = true;
				}
				
				// Looking for bank line with same amout and not linked
				$sql_bank = "SELECT b.rowid, b.amount, b.label, b.datev ";
				$sql_bank .= "FROM " . MAIN_DB_PREFIX . "bank as b ";
				$sql_bank .= "LEFT JOIN " . MAIN_DB_PREFIX . "bank_extrafields as ef ON ef.fk_object = b.rowid ";
				$sql_bank .= "WHERE b.amount = " . (float)$t['amount'] . " ";
				$sql_bank .= "AND b.fk_account = " . (int)$bank_id . " ";
				$sql_bank .= "AND (ef.qonto_id IS NULL OR ef.qonto_id = '')";

				$resql_bank = $this->db->query($sql_bank);
				if ($resql_bank) {
					while ($obj = $this->db->fetch_object($resql_bank)) {
						$t["trx"][] = $obj;
					}
					$this->db->free($resql_bank);
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
						$t["c_invoices"][] = $c_inv;
					}
				}
				
				// Compare transactions amount with unpaid suppliers invoices amount
				foreach ($unpaid_s_invoices as $s_inv) {
					if (price2num(abs((float)$s_inv->amount), 'MT') == price2num($qonto_amount, 'MT')) {
						$t["s_invoices"][] = $s_inv;
					}
				}

				// Compare transactions amount with unpaid VAT
				foreach ($unpaid_vat as $vat) {
					if (price2num(abs((float)$vat->amount), 'MT') == price2num($qonto_amount, 'MT')) {
						$t["vat"][] = $vat;
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
	 * Link a Qonto transaction ID to a Dolibarr bank entrie
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
		
		$bank = new AccountLine($this->db);
		$res = $bank->fetch($bank_line_id);
		
		// Verify if the bank line exists
		if ($res <= 0) {
			return -1;
		}
		
		// Load extrafields definitions
		$extrafields = new ExtraFields($this->db);
		$extrafields->fetch_name_optionals_label($bank->table_element);
		$bank->fetch_optionals();
		
		// Assign the value to the extrafield
		$bank->array_options['options_qonto_id'] = $qonto_id;
	
		// Try to update the specific field
		$res = $bank->updateExtraField('qonto_id');
		
		// If the result is 0 (the line does not exist yet in the extrafields table) or < 0 (error)
		if ($res <= 0) {
			// Force the creation of the complete line
			$res = $bank->insertExtraFields();
			
			if ($res <= 0) {
				dol_syslog(__METHOD__ . ": Error creating the bank line extrafield: ".$bank->error, LOG_ERR);
				return -1;
			}
		}
		
		// Update the bank line with the statement rapprochement
		$bank->num_releve = $ref_releve;
		$res = $bank->update_conciliation($user, null, 1);
		if ($res <= 0) {
			dol_syslog(__METHOD__ . ": Error updating the bank line statement: ".$bank->error, LOG_ERR);
			return -1;
		}
	
		return 1;
	}
	
	/**
	* Updating selected entrie with the qonto transaction
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

		// Explode target type & rowid
		$parts = explode('_', $target, 2);
		if (count($parts) == 2) {
			$type = $parts[0];
			$rowid = (int) $parts[1];
		} else {
			return false;
		}

		// Init SQL transaction
		$this->db->begin();

		// Check if Qonto ID already exists
		if ($this->isLinked($qonto_trx['id'], $bank_account_id) !== 0) {
			$this->db->rollback();
			dol_syslog(__METHOD__ . ": Transaction ID (".$qonto_trx['id'].") already linked.", LOG_WARNING);
			return -1;
		}

		// Init error counter
		$error = 0;

		// Load utils functions
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
				// Trying to link the transaction
				$run = $this->linkTransaction($rowid, $qonto_trx['id'], $ref_releve);
				if ($run < 0) {
					dol_syslog(__METHOD__ . ": Error, can not link the transaction", LOG_ERR);
					$error++;
				}
				
				break;

			case 'cinv':
				$payment = new Paiement($this->db);
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
				
				// Trying to create a payment on the invoice (arg 1 = auto close)
				$run = $payment->create($user, 1);
				if ($run > 0) {
					$invoice->fetch($rowid);
					
					// Get customer name for emitter
					$invoice->fetch_thirdparty();
					$emitter_name = is_object($invoice->thirdparty) ? $invoice->thirdparty->name : '';

					// Trying to push the payment to bank					
					$bank_line = $payment->addPaymentToBank(
						$user,
						'payment',
						'(Payment) ' . $invoice->ref,
						$bank_account_id,
						$emitter_name,
						$emitter_bank_name
					);
					
					
					if($bank_line > 0) {
					
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
								dol_syslog("QontoMatching::update: Error updating bank line: " . $line->error, LOG_ERR);
								$error++;
							}
						} else {
							dol_syslog("QontoMatching::update: Error fetching bank line: " . $line->error, LOG_ERR);
							$error++;
						}
					} else {
						dol_syslog("QontoMatching::update: Error pushing payment to the bank: ".$payment->error, LOG_ERR);
						$error++;
					}
				} else {
					dol_syslog("QontoMatching::update: Error creating the payment: ".$payment->error, LOG_ERR);
					$error++;
				}
				
				break;
				
			case 'sinv':
				$payment = new PaiementFourn($this->db);
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
				
				// Trying to create a payment on the supplier invoice (arg 1 = auto close)
				$run = $payment->create($user, 1);
				if ($run > 0) {
					$invoice->fetch($rowid);
					
					// Get supplier name for emitter if not found in API
					$invoice->fetch_thirdparty();
					if (empty($emitter_name)) {
						$emitter_name = is_object($invoice->thirdparty) ? $invoice->thirdparty->name : '';
					}
					
					// Trying to push the payment to bank
					$bank_line = $payment->addPaymentToBank(
						$user,
						'payment_supplier',
						'(Payment) ' . $invoice->ref,
						$bank_account_id,
						$emitter_name,
						$emitter_bank_name
					);
					
					if($bank_line > 0) {
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
								dol_syslog("QontoMatching::update: Error updating bank line: " . $line->error, LOG_ERR);
								$error++;
							}
						} else {
							dol_syslog("QontoMatching::update: Error fetching bank line: " . $line->error, LOG_ERR);
							$error++;
						}
					} else {
						dol_syslog("QontoMatching::update: Error pushing payment to the bank: ".$payment->error, LOG_ERR);
						$error++;
					}
				} else {
					dol_syslog("QontoMatching::update: Error creating the payment: ".$payment->error, LOG_ERR);
					$error++;
				}
				
				break;
			
			case 'vat':
				$payment = new PaymentVat($this->db);
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
				
				// Trying to create a payment on the vat (arg 1 = auto close)
				$run = $payment->create($user, 1);
				if ($run > 0) {
					$vat->fetch($rowid);
					
					// Get emitter name for emitter if not found in API
					$vat->fetch_thirdparty();
					if (empty($emitter_name)) {
						$emitter_name = is_object($vat->thirdparty) ? $vat->thirdparty->name : '';
					}
					
					// Trying to push the payment to bank
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
								dol_syslog("QontoMatching::update: Error updating bank line: " . $line->error, LOG_ERR);
								$error++;
							}
						} else {
							dol_syslog("QontoMatching::update: Error fetching bank line: " . $line->error, LOG_ERR);
							$error++;
						}
					} else {
						dol_syslog("QontoMatching::update: Error pushing payment to the bank: ".$payment->error, LOG_ERR);
						$error++;
					}
				} else {
					dol_syslog("QontoMatching::update: Error creating the payment: ".$payment->error, LOG_ERR);
					$error++;
				}
				
				break;
			
			default:
				break;
		}
	
		// Finalizing the transaction
		if ($error === 0) {
			$this->db->commit();
			dol_syslog("QontoMatching::update: Transaction Qonto ID (".$qonto_trx['id'].") successfully matched with target ".$target, LOG_INFO);
			return 1;
		} else {
			$this->db->rollback();
			return -1;
		}
	}
}