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
	 * Load existing links into the PHP cache for a given bank account.
	 * * @param int $bank_id
	 * @param bool $force_reload
	 */
	public function loadLinksCache($bank_id, $force_reload = false)
	{
		if (empty($bank_id)) {
			dol_syslog(__METHOD__ . ": Error on input parameters", LOG_ERR);
			return -1;
		}

		if ($force_reload || !isset($this->links_cache[$bank_id])) {
			if (!class_exists('QontoRepository')) dol_include_once('/qontosync/class/qontorepository.class.php');
            $qontoRepo = new QontoRepository($this->db);
			$this->links_cache[$bank_id] = $qontoRepo->getLinkedQontoIds($bank_id);
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
			if (!class_exists('QontoRepository')) dol_include_once('/qontosync/class/qontorepository.class.php');
            $qontoRepo = new QontoRepository($this->db);
			$result = $qontoRepo->getLinkedQontoIds($bank_id, $qonto_id);
			return isset($result[$qonto_id]) ? $result[$qonto_id] : 0;
		}
	}

	/**
	 * Determine the Dolibarr payment method ID based on the Qonto transaction type
	 *
	 * @param string $op_type   Qonto operation type (e.g., 'card', 'transfer', etc.)
	 * @param string $side      Optional: Qonto side ('credit' or 'debit')
	 * @return string|int       Dolibarr payment method ID, empty string, or -1 if empty input
	 */
	public function getPaymentModeId($op_type, $side = '')
	{
		if (empty($op_type)) {
			dol_syslog(__METHOD__ . ": Error on input parameters", LOG_ERR);
			return -1;
		}
	
		if ($op_type == 'income' || $op_type == 'transfer') {
			return getDolGlobalInt('QONTOSYNC_PM_TRANSFER') ? getDolGlobalInt('QONTOSYNC_PM_TRANSFER') : '';
		} elseif ($op_type == 'card' || $op_type == 'card_acquirer_payout') {
			return getDolGlobalInt('QONTOSYNC_PM_CARD') ? getDolGlobalInt('QONTOSYNC_PM_CARD') : '';
		} elseif ($op_type == 'direct_debit' || $op_type == 'direct_debit_collection') {
			return getDolGlobalInt('QONTOSYNC_PM_DD') ? getDolGlobalInt('QONTOSYNC_PM_DD') : '';
		} elseif ($op_type == 'check') {
			return getDolGlobalInt('QONTOSYNC_PM_CHECK') ? getDolGlobalInt('QONTOSYNC_PM_CHECK') : '';
		} elseif ($op_type == 'qonto_fee') {
			if ($side === 'credit') {
				return getDolGlobalInt('QONTOSYNC_PM_TRANSFER');
			} else {
				return getDolGlobalInt('QONTOSYNC_PM_DD');
			}
		}
	
		return '';
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
		if (!class_exists('QontoRepository')) dol_include_once('/qontosync/class/qontorepository.class.php');
		$qontoRepo = new QontoRepository($this->db);
		
		/**
		* Items will be loaded only if at least one transaction is unlinked
		*/
		$unlinked_bank_lines = array();
		$unpaid_c_invoices = array();
		$unpaid_s_invoices = array();
		$unpaid_vat = array();
		$items_loaded = false;
		
		$entries = array();
		
		// Load tolerance setting from configuration (default to 7 days if not set)
		$tolerance_days = isset($conf->global->QONTOSYNC_TOLERANCE_DAYS) ? (int)$conf->global->QONTOSYNC_TOLERANCE_DAYS : 7;
		
		// 1. Pre-load the links cache for the whole account
		$this->loadLinksCache($bank_id);

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
				$t["linked_rowid"] = (int) $link_status;
				$t["trx_status"] = "linked";
			} else {
				$t["trx_status"] = "unlinked";
				
				// Lazy load unpaid items ONCE if not already loaded only if unlinked transaction exist
				if (!$items_loaded) {
					$unlinked_bank_lines = $qontoRepo->getUnlinkedBankLines($bank_id);
					$unpaid_docs = $qontoRepo->getUnpaidDocuments('all');
		
					$unpaid_c_invoices = $unpaid_docs['c_invoices'];
					$unpaid_s_invoices = $unpaid_docs['s_invoices'];
					$unpaid_vat        = $unpaid_docs['vat'];
					
					$items_loaded = true;
				}
								
				// Normalize Qonto date to midnight to calculate full days difference
				$qonto_time = strtotime(date('Y-m-d', strtotime($t['settled_at'])));
				
				// Compare transactions  with unlinked bank lines
				foreach ($unlinked_bank_lines as $b_line) {
					// Normalize Dolibarr bank line date to midnight
					$bank_time = strtotime(date('Y-m-d', $this->db->jdate($b_line->datev)));
					
					// Calculate the absolute difference in days (86400 seconds = 1 day)
					$diff_days = abs($qonto_time - $bank_time) / 86400;
					
					// Check if the amount matches AND if it falls within the tolerance range parameter
					if ($diff_days <= $tolerance_days && price2num((float) $b_line->amount, 'MT') == price2num($t['amount'], 'MT')) {
						$t["trx"][] = clone $b_line;
					}
				}
							
				// Compare transactions amount with unpaid customers invoices amount
				$t["c_invoices"] = array();
				foreach ($unpaid_c_invoices as $c_inv) {
					if (price2num((float) $c_inv->amount, 'MT') == price2num($t['amount'], 'MT')) {
						$t["c_invoices"][] = clone $c_inv;
					}
				}
				
				// Compare transactions amount with unpaid suppliers invoices amount
				$t["s_invoices"] = array();
				foreach ($unpaid_s_invoices as $s_inv) {
					if (-(price2num((float) $s_inv->amount, 'MT')) == price2num($t['amount'], 'MT')) {
						$t["s_invoices"][] = clone $s_inv;
					}
				}

				// Compare transactions amount with unpaid VAT
				$t["vat"] = array();
				foreach ($unpaid_vat as $vat) {
					if (-(price2num((float) $vat->amount, 'MT')) == price2num($t['amount'], 'MT')) {
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
}