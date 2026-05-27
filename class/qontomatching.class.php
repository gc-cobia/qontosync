<?php
/*
 *  QontoSync dolibarr module 
 *  @file       qontomatching.class.php
 *  @author     Gaëtan COURTOIS
 *  @license    GPLv3
 */

require_once DOL_DOCUMENT_ROOT.'/core/class/extrafields.class.php';
require_once DOL_DOCUMENT_ROOT.'/compta/bank/class/account.class.php';
require_once DOL_DOCUMENT_ROOT.'/compta/facture/class/facture.class.php';
require_once DOL_DOCUMENT_ROOT.'/compta/paiement/class/paiement.class.php';
require_once DOL_DOCUMENT_ROOT.'/compta/tva/class/tva.class.php';
require_once DOL_DOCUMENT_ROOT.'/compta/tva/class/paymentvat.class.php';
require_once DOL_DOCUMENT_ROOT.'/fourn/class/fournisseur.facture.class.php';
require_once DOL_DOCUMENT_ROOT.'/fourn/class/paiementfourn.class.php';


class QontoMatching
{
	public $db;

	public function __construct($db)
	{
		$this->db = $db;
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
			dol_syslog("QontoMatching::fetchTransactions: Error on input parameters", LOG_ERR);
			return array();
		}

		# Tempory fast mode: only not fast
		
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
			$sql = "SELECT ef.fk_object ";
			$sql .= "FROM " . MAIN_DB_PREFIX . "bank_extrafields as ef ";
			$sql .= "JOIN " . MAIN_DB_PREFIX . "bank as b ON ef.fk_object = b.rowid ";
			$sql .= "WHERE ef.qonto_id = '" . $this->db->escape($t['id']) . "' ";
			$sql .= "AND b.fk_account = " . (int) $bank_id;

			$resql = $this->db->query($sql);
			if ($resql) {
				$num_rows = $this->db->num_rows($resql);
				if ($num_rows > 0) {
					if ($num_rows > 1) {
						$t["trx_status"] = "errorTooManyTrx";
					} else {
						$obj = $this->db->fetch_object($resql);
						$t["linked_rowid"] = (int)$obj->fk_object;
						$t["trx_status"] = "linked";
					}
				} else {
					$t["trx_status"] = "unlinked";
					
					// Load unpaid items ONCE if not already loaded
					if (!$items_loaded) {
						
						// Looking for unpaid customers invoices
						$sql_c_inv = "SELECT rowid, ref, total_ttc, type, datef FROM " . MAIN_DB_PREFIX . "facture WHERE entity IN (" . getEntity('facture') . ") AND fk_statut = 1 AND paye = 0";
						$resql_c_inv = $this->db->query($sql_c_inv);
						if ($resql_c_inv) {
							while ($obj_c_inv = $this->db->fetch_object($resql_c_inv)) {
								$fact = new Facture($this->db);
								$fact->id = $obj_c_inv->rowid;
								$fact->ref = $obj_c_inv->ref;
								$fact->total_ttc = $obj_c_inv->total_ttc;
								$fact->type = $obj_c_inv->type;
								$fact->date = $this->db->jdate($obj_c_inv->datef);
								
								$remain = $fact->getRemainToPay();						
								if ($remain != 0) {
									$unpaid_c_invoices[] = (object) array(
										'rowid'  => $fact->id,
										'label'  => $fact->ref,
										'amount' => $remain,
										'datev'  => $fact->date
									);
								}
								
							}
							$this->db->free($resql_c_inv);
						}
						
						// Looking for unpaid suppliers invoices
						$sql_s_inv = "SELECT rowid, ref, total_ttc, type, datef FROM " . MAIN_DB_PREFIX . "facture_fourn WHERE entity IN (" . getEntity('facture_fourn') . ") AND fk_statut = 1 AND paye = 0";
						$resql_s_inv = $this->db->query($sql_s_inv);
						if ($resql_s_inv) {
							while ($obj_s_inv = $this->db->fetch_object($resql_s_inv)) {
								$fact_fourn = new FactureFournisseur($this->db);
								$fact_fourn->id = $obj_s_inv->rowid;
								$fact_fourn->ref = $obj_s_inv->ref;
								$fact_fourn->total_ttc = $obj_s_inv->total_ttc;
								$fact_fourn->type = $obj_s_inv->type;
								$fact_fourn->date = $this->db->jdate($obj_s_inv->datef);
								
								$remain = $fact_fourn->getRemainToPay();
								if ($remain != 0) {
									$unpaid_s_invoices[] = (object) array(
										'rowid'  => $fact_fourn->id,
										'label'  => $fact_fourn->ref,
										'amount' => $remain,
										'datev'  => $fact_fourn->date
									);
								}
							}
							$this->db->free($resql_s_inv);
						}

						// Looking for unpaid VAT
						$sql_vat = "SELECT rowid, label, amount, datev FROM " . MAIN_DB_PREFIX . "tva WHERE entity IN (" . getEntity('tva') . ") AND paye = 0";
						$resql_vat = $this->db->query($sql_vat);
						if ($resql_vat) {
							while ($obj_vat = $this->db->fetch_object($resql_vat)) {
								$unpaid_vat[] = (object) array(
									'rowid'  => $obj_vat->rowid,
									'label'  => $obj_vat->label ? $obj_vat->label : 'VAT',
									'amount' => $obj_vat->amount,
									'datev'  => $this->db->jdate($obj_vat->datev)
								);
							}
						$this->db->free($resql_vat);
						}
						

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
				$this->db->free($resql);
			}
			
			$t["c_invoices"] = array();
			$t["s_invoices"] = array();
			$t["vat"] = array();
			
			if ($t["trx_status"] === "unlinked" && is_array($unpaid_c_invoices)) {
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
			dol_syslog("QontoMatching::linkTransaction: Error on input parameters", LOG_ERR);
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
				dol_syslog("QontoMatching::linkTransaction: Error creating the bank line extrafield: ".$bank->error, LOG_ERR);
				return -1;
			}
		}
		
		// Update the bank line with the statement rapprochement
        $bank->num_releve = $ref_releve;
		$res = $bank->update_conciliation($user, null, 1);
		if ($res <= 0) {
			dol_syslog("QontoMatching::linkTransaction: Error updating the bank line statement: ".$bank->error, LOG_ERR);
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
			dol_syslog("QontoMatching::update: Error on input parameters", LOG_ERR);
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
		$sql_check = "SELECT rowid FROM " . MAIN_DB_PREFIX . "bank_extrafields WHERE qonto_id = '" . $this->db->escape($qonto_trx['id']) . "'";
		$resql_check = $this->db->query($sql_check);
		if ($resql_check && $this->db->num_rows($resql_check) > 0) {
			$this->db->rollback();
			dol_syslog("QontoMatching::update: Transaction ID (".$qonto_trx['id'].") already linked.", LOG_WARNING);
			return -1;
		}

		// Init error counter
		$error = 0;

		// Get operation type
		$op_type = isset($qonto_trx['operation_type']) ? $qonto_trx['operation_type'] : '';

		if ($op_type == 'income' || $op_type == 'transfer') {
			$mop = getDolGlobalInt('QONTOSYNC_PM_TRANSFER') ? getDolGlobalInt('QONTOSYNC_PM_TRANSFER') : '';
		} elseif ($op_type == 'card' || $op_type == 'card_acquirer_payout') {
			$mop = getDolGlobalInt('QONTOSYNC_PM_CARD') ? getDolGlobalInt('QONTOSYNC_PM_CARD') : '';
		} elseif ($op_type == 'direct_debit' || $op_type == 'direct_debit_collection' ) {
			$mop = getDolGlobalInt('QONTOSYNC_PM_DD') ? getDolGlobalInt('QONTOSYNC_PM_DD') : '';
		} elseif ($op_type == 'check') {
			$mop = getDolGlobalInt('QONTOSYNC_PM_CHECK') ? getDolGlobalInt('QONTOSYNC_PM_CHECK') : '';
		} else {
			$mop = '';
		}
		

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
					dol_syslog("QontoMatching::update: Error, can not link the transaction", LOG_ERR);
					$error++;
				}
				
				break;

			case 'cinv':
				$payment = new Paiement($this->db);
				$invoice = new Facture($this->db);
				
				if ($invoice->fetch($rowid) <= 0) { $error++; break; }
				
				$payment->datepaye		= strtotime($qonto_trx['settled_at']);
				$payment->fk_account	= $bank_account_id;
				$payment->paiementid	= $mop;
				$payment->num_payment	= $num_chq;
				$payment->amounts		= array($invoice->id => abs((float)$qonto_trx['amount']));
				
				// Trying to create a payment on the invoice
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
				
				$payment->datepaye		= strtotime($qonto_trx['settled_at']);
				$payment->fk_account	= $bank_account_id;
				$payment->paiementid	= $mop;
				$payment->num_payment	= $num_chq;
				$payment->amounts		= array($invoice->id => abs((float)$qonto_trx['amount']));
				
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
				
				$payment->datepaye			= strtotime($qonto_trx['settled_at']);
				$payment->fk_bank			= $bank_account_id;
				$payment->paiementtype		= $mop;
				$payment->num_payment		= $num_chq;
				$payment->amounts			= array($vat->id => abs((float)$qonto_trx['amount']));
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