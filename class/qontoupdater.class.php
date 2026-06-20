<?php
/*
 * QontoSync dolibarr module 
 * @file       class/qontoupdater.class.php
 * @author     Gaëtan COURTOIS
 * @license    GPLv3
 */

class QontoUpdater
{
    private $db;

    public function __construct($db) {
        $this->db = $db;
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
			
		// Calculate the real Qonto amount with its sign
		$qonto_amount = (float)$qonto_trx['amount'];
		if (isset($qonto_trx['side']) && $qonto_trx['side'] == 'debit') {
			$qonto_amount = -$qonto_amount;
		}
		
		// Amount verification
		$total_imputed = 0.0;
		foreach ($amounts_array as $doc_id => $amount) {
			// Keep the sign (+ for invoice, - for credit note)
			$total_imputed += (float)$amount;
		}
		
		// Adapt sign to the document type
		$total_imputed = ($type === 'sinv' || $type === 'vat') ? -$total_imputed : $total_imputed;
			
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
				// Security check: ensure Bank module is enabled
				if (empty($conf->banque->enabled)) {
					dol_syslog(__METHOD__ . ": Bank module is disabled.", LOG_ERR);
					$error++;
					break;
				}
				
				// Accounting security: 1 Qonto transaction = 1 single Dolibarr bank line
				if (count($amounts_array) > 1) {
					dol_syslog(__METHOD__ . ": Error, cannot link multiple bank lines to a single Qonto transaction.", LOG_ERR);
					$error++;
					break;
				}
				
				// Get the unique ID of the sent bank line
				reset($amounts_array);
				$doc_id = key($amounts_array);
				
				// Link directly to the existing bank line (no payment creation)
				$run = $this->linkTransaction($doc_id, $qonto_trx['id'], $ref_releve);
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

                // Declare payment and invoice classes
				if (!class_exists('Paiement')) require_once DOL_DOCUMENT_ROOT.'/compta/paiement/class/paiement.class.php';
				if (!class_exists('Facture')) require_once DOL_DOCUMENT_ROOT.'/compta/facture/class/facture.class.php';
				
				// Initialize payment
				$payment = new Paiement($this->db);
				$payment->datepaye      = strtotime($qonto_trx['settled_at']);
				$payment->fk_account    = $bank_account_id;
				$payment->paiementid    = $mop;
				$payment->num_payment   = $num_chq;
				
				$valid_amounts = array();
				
				foreach ($amounts_array as $doc_id => $amount) {
					$invoice = new Facture($this->db);
					if ($invoice->fetch($doc_id) > 0) {
						
						// Retrieve thirdparty info for the first document to populate the bank line
						if ($first_socid == 0) {
							$first_socid = $invoice->fk_soc;
							$invoice->fetch_thirdparty();
							$emitter_name = is_object($invoice->thirdparty) ? $invoice->thirdparty->name : '';
						} 

						// Handle Credit Notes by applying a negative sign to the payment amount
						$pay_amount = abs((float)$amount);
						if ($invoice->type == 2 || $invoice->total_ttc < 0) {
							$pay_amount = -$pay_amount;
						}
						
						$valid_amounts[$doc_id] = $pay_amount;
						$payment_label_ref .= $invoice->ref . ', ';
					} else {
						$error++;
					}
				}
				
				if ($error > 0) break;
				
				$payment->amounts = $valid_amounts;
				$payment_label_ref = rtrim($payment_label_ref, ', ');
				
				// Attempt to create a payment on the invoices (arg 1 = auto close)
				$run = $payment->create($user, 1);
				if ($run > 0) {
					// Attempt to push the payment to the bank
					$bank_line = $payment->addPaymentToBank(
						$user,
						'payment',
						'(Payment) ' . $payment_label_ref,
						$bank_account_id,
						$emitter_name,
						$emitter_bank_name
					);
					
					if($bank_line > 0) {
						if (!class_exists('AccountLine')) require_once DOL_DOCUMENT_ROOT.'/compta/bank/class/account.class.php';
						$line = new AccountLine($this->db);				
						
						if ($line->fetch($bank_line) > 0) {
							$line->label = isset($qonto_trx['label']) ? $qonto_trx['label'] : '(Payment) ' . $payment_label_ref;
							$line->datev = strtotime($qonto_trx['settled_at']);
							$line->dateo = isset($qonto_trx['emitted_at']) ? strtotime($qonto_trx['emitted_at']) : strtotime($qonto_trx['settled_at']);
							
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

				// Declare payment and invoice classes
				if (!class_exists('PaiementFourn')) require_once DOL_DOCUMENT_ROOT.'/fourn/class/paiementfourn.class.php';
				if (!class_exists('FactureFournisseur')) require_once DOL_DOCUMENT_ROOT.'/fourn/class/fournisseur.facture.class.php';
				
				// Initialize payment
				$payment = new PaiementFourn($this->db);
				$payment->datepaye      = strtotime($qonto_trx['settled_at']);
				$payment->fk_account    = $bank_account_id;
				$payment->paiementid    = $mop;
				$payment->num_payment   = $num_chq;
				
				$valid_amounts = array();
				
				foreach ($amounts_array as $doc_id => $amount) {
					$invoice = new FactureFournisseur($this->db);
					if ($invoice->fetch($doc_id) > 0) {
						
						// Retrieve thirdparty info for the first document to populate the bank line
						if ($first_socid == 0) {
							$first_socid = $invoice->fk_soc;
							$invoice->fetch_thirdparty();
							$emitter_name = is_object($invoice->thirdparty) ? $invoice->thirdparty->name : '';
						}

						// Handle Credit Notes by applying a negative sign to the payment amount
						$pay_amount = abs((float)$amount);
						if ($invoice->type == 2 || $invoice->total_ttc < 0) {
							$pay_amount = -$pay_amount;
						}
						
						$valid_amounts[$doc_id] = $pay_amount;
						$payment_label_ref .= $invoice->ref . ', ';
					} else {
						$error++;
					}
				}
				
				if ($error > 0) break;
				
				$payment->amounts = $valid_amounts;
				$payment_label_ref = rtrim($payment_label_ref, ', ');
				
				// Attempt to create a payment on the supplier invoices (arg 1 = auto close)
				$run = $payment->create($user, 1);
				if ($run > 0) {
					// Attempt to push the payment to the bank
					$bank_line = $payment->addPaymentToBank(
						$user,
						'payment_supplier',
						'(Payment) ' . $payment_label_ref,
						$bank_account_id,
						$emitter_name,
						$emitter_bank_name
					);
					
					if($bank_line > 0) {
						if (!class_exists('AccountLine')) require_once DOL_DOCUMENT_ROOT.'/compta/bank/class/account.class.php';
						$line = new AccountLine($this->db);				
						
						if ($line->fetch($bank_line) > 0) {
							$line->label = isset($qonto_trx['label']) ? $qonto_trx['label'] : '(Payment) ' . $payment_label_ref;
							$line->datev = strtotime($qonto_trx['settled_at']);
							$line->dateo = isset($qonto_trx['emitted_at']) ? strtotime($qonto_trx['emitted_at']) : strtotime($qonto_trx['settled_at']);
							
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

				// Declare payment and tax classes
				if (!class_exists('PaymentVat')) require_once DOL_DOCUMENT_ROOT.'/compta/tva/class/paymentvat.class.php';
				if (!class_exists('Tva')) require_once DOL_DOCUMENT_ROOT.'/compta/tva/class/tva.class.php';
				
				// Initialize payment
				$payment = new PaymentVat($this->db);
				$payment->datepaye      = strtotime($qonto_trx['settled_at']);
				$payment->fk_bank       = $bank_account_id;
				$payment->paiementtype  = $mop;
				$payment->num_payment   = $num_chq;
				
				$valid_amounts = array();
				
				foreach ($amounts_array as $doc_id => $amount) {
					$vat = new Tva($this->db);
					if ($vat->fetch($doc_id) > 0) {
						$pay_amount = abs((float)$amount);
						if ($vat->amount < 0) {
							$pay_amount = -$pay_amount;
						}
						$valid_amounts[$doc_id] = $pay_amount;
						$payment_label_ref .= $vat->ref . ', ';
					} else {
						$error++;
					}
				}
				
				if ($error > 0) break;
				
				$payment->amounts = $valid_amounts;
				
				// VAT payment assigns main reference to the first item
				reset($valid_amounts);
				$payment->chid = key($valid_amounts);
				$payment_label_ref = rtrim($payment_label_ref, ', ');
				
				// Attempt to create a payment on the tax (arg 1 = auto close)
				$run = $payment->create($user, 1);
				if ($run > 0) {
					$bank_line = $payment->addPaymentToBank(
						$user,
						'payment_vat',
						'(VATPayment) ' . $payment_label_ref,
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
						if (!class_exists('AccountLine')) require_once DOL_DOCUMENT_ROOT.'/compta/bank/class/account.class.php';
						$line = new AccountLine($this->db);
						
						if ($line->fetch($bank_line) > 0) {
							$line->label = isset($qonto_trx['label']) ? $qonto_trx['label'] : '(Payment) ' . $payment_label_ref;
							$line->datev = strtotime($qonto_trx['settled_at']);
							$line->dateo = isset($qonto_trx['emitted_at']) ? strtotime($qonto_trx['emitted_at']) : strtotime($qonto_trx['settled_at']);
							
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
}