<?php
/*
 * QontoSync dolibarr module 
 * @file       class/qontomatching.class.php
 * @author     Gaëtan COURTOIS
 * @license    GPLv3
 */

class QontoMatching
{
	public $db;
	private $links_cache = array(); // PHP cache to store already linked Qonto IDs
	private $multicurrency_enabled;

	public function __construct($db)
	{
		global $conf;
		$this->db = $db;
		$this->multicurrency_enabled = !empty($conf->multicurrency->enabled);
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
	 * @return int linked_rowid if linked, 0 if not linked, -1 if error (ex. too many links)
	 */
	public function isLinked($qonto_id, $bank_id, $cache = true)
	{
		if (empty($qonto_id) || empty($bank_id)) {
			dol_syslog(__METHOD__ . ": Error on input parameters", LOG_ERR);
			return -1;
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
	
		if ($op_type == 'income' || $op_type == 'transfer' || $op_type == 'swift_income' || $op_type == 'recall' || $op_type == 'account_remuneration') {
			return getDolGlobalInt('QONTOSYNC_PM_TRANSFER') ?: '';
		} elseif ($op_type == 'card' || $op_type == 'card_acquirer_payout') {
			return getDolGlobalInt('QONTOSYNC_PM_CARD') ?: '';
		} elseif ($op_type == 'direct_debit' || $op_type == 'direct_debit_collection' || $op_type == 'financing_installment') {
			return getDolGlobalInt('QONTOSYNC_PM_DD') ?: '';
		} elseif ($op_type == 'cheque' || $op_type == 'check') {
			return getDolGlobalInt('QONTOSYNC_PM_CHECK') ?: '';
		} elseif ($op_type == 'qonto_fee') {
			if ($side === 'credit') {
				return getDolGlobalInt('QONTOSYNC_PM_TRANSFER') ?: '';
			} else {
				return getDolGlobalInt('QONTOSYNC_PM_DD') ?: '';
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
	public function fetchTransactions($transaction, $bank_id)
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
		$unpaid_social = array();
		$unpaid_salary = array();
		$unpaid_expense = array();
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
			$t['local_amount'] = isset($t['local_amount']) ? (float)$t['local_amount'] : 0.0;

			// Correct the sign amount due to the side movement
			if (isset($t['side']) && $t['side'] == 'debit') {
				$t['amount'] = -$t['amount'];
				$t['local_amount'] = -$t['local_amount'];
			}

			$qonto_local_currency = isset($t['local_currency']) ? strtoupper($t['local_currency']) : '';

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
                    $unpaid_social     = $unpaid_docs['social'];
                    $unpaid_salary     = $unpaid_docs['salary'];  
                    $unpaid_expense    = $unpaid_docs['expense']; 
                  
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
					$match_base = (price2num((float) $c_inv->amount, 'MT') == price2num($t['amount'], 'MT'));

					$match_mc = false;
					if ($this->multicurrency_enabled) {
						$match_mc = (
							!empty($c_inv->mc_code) 
							&& $c_inv->mc_code != $conf->currency 
							&& strtoupper($c_inv->mc_code) === $qonto_local_currency 
							&& (price2num((float) $c_inv->mc_amount, 'MT')) == price2num($t['local_amount'], 'MT')
						);
					}

					if ($match_base || $match_mc) {
						$t["c_invoices"][] = clone $c_inv;
					}
				}
				
				// Compare transactions amount with unpaid suppliers invoices amount
				$t["s_invoices"] = array();
				foreach ($unpaid_s_invoices as $s_inv) {
					$match_base = (-(price2num((float) $s_inv->amount, 'MT')) == price2num($t['amount'], 'MT'));
					
					$match_mc = false;
					if ($this->multicurrency_enabled) {
						$match_mc = (
							!empty($s_inv->mc_code) 
							&& $s_inv->mc_code != $conf->currency 
							&& strtoupper($s_inv->mc_code) === $qonto_local_currency 
							&& -(price2num((float) $s_inv->mc_amount, 'MT')) == price2num($t['local_amount'], 'MT')
						);
					}

					if ($match_base || $match_mc) {
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

				// Compare transactions amount with unpaid Social Charges (Negative)
                $t["social"] = array();
                foreach ($unpaid_social as $soc) {
                    if (-(price2num((float) $soc->amount, 'MT')) == price2num($t['amount'], 'MT')) {
                        $t["social"][] = clone $soc;
                    }
                }
                // Compare transactions amount with unpaid Salaries (Negative)
                $t["salary"] = array();
                foreach ($unpaid_salary as $sal) {
                    if (-(price2num((float) $sal->amount, 'MT')) == price2num($t['amount'], 'MT')) {
                        $t["salary"][] = clone $sal;
                    }
                }
                // Compare transactions amount with unpaid Expense Reports (Negative)
                $t["expense"] = array();
                foreach ($unpaid_expense as $exp) {
                    if (-(price2num((float) $exp->amount, 'MT')) == price2num($t['amount'], 'MT')) {
                        $t["expense"][] = clone $exp;
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
	 * Prepare statements data by matching Qonto data with Dolibarr balances & VFS
	 *
	 * @param Account $account Bank account object
	 * @param int $year
	 * @param array $transactions Raw Qonto transactions
	 * @param array $qonto_statements_raw Raw Qonto statements
	 * @return array
	 */
	public function matchStatements($account, $year, $transactions, $qonto_statements_raw)
	{
		global $conf;

		$months_data = array();

		// 1. Retrieving pure Dolibarr balances via the Repository
		if (!class_exists('QontoRepository')) dol_include_once('/qontosync/class/qontorepository.class.php');
		$qontoRepo = new QontoRepository($this->db);
		$dolibarr_balances = $qontoRepo->getMonthlyBalances($account->id, $year);

		// 2. Breakdown of Qonto transactions by month
		$transactions_by_month = array_fill(1, 12, array());
		if (is_array($transactions)) {
			foreach ($transactions as $t) {
				if (!empty($t['settled_at'])) {
					$month_idx = (int)date('m', strtotime($t['settled_at']));
					if ($month_idx >= 1 && $month_idx <= 12) {
						$transactions_by_month[$month_idx][] = $t;
					}
				}
			}
		}

		// 3. Qonto balance calculation
		$qonto_month_opening = array();
		$qonto_month_closing = array();
		for ($m = 1; $m <= 12; $m++) {
			if (!empty($transactions_by_month[$m])) {
				$month_trxs = $transactions_by_month[$m];
				
				$newest_trx = $month_trxs[0];
				$qonto_month_closing[$m] = isset($newest_trx['settled_balance_cents']) ? ($newest_trx['settled_balance_cents'] / 100) : (float)$newest_trx['settled_balance'];
				
				$oldest_trx = $month_trxs[count($month_trxs) - 1];
				$oldest_balance = isset($oldest_trx['settled_balance_cents']) ? ($oldest_trx['settled_balance_cents'] / 100) : (float)$oldest_trx['settled_balance'];
				$oldest_amount = isset($oldest_trx['amount_cents']) ? ($oldest_trx['amount_cents'] / 100) : (float)$oldest_trx['amount'];
				
				if (isset($oldest_trx['side']) && $oldest_trx['side'] == 'debit') {
					$oldest_amount = -$oldest_amount;
				}
				$qonto_month_opening[$m] = $oldest_balance - $oldest_amount;
			}
		}
		
		// Reports of empty months (Qonto)
		$last_known_balance = null;
		for ($m = 1; $m <= 12; $m++) {
			if (isset($qonto_month_closing[$m])) {
				$last_known_balance = $qonto_month_closing[$m];
			} elseif ($last_known_balance !== null) {
				$qonto_month_opening[$m] = $last_known_balance;
				$qonto_month_closing[$m] = $last_known_balance;
			}
		}
		$next_known_balance = null;
		for ($m = 12; $m >= 1; $m--) {
			if (isset($qonto_month_opening[$m])) {
				$next_known_balance = $qonto_month_opening[$m];
			} elseif ($next_known_balance !== null) {
				$qonto_month_opening[$m] = $next_known_balance;
				$qonto_month_closing[$m] = $next_known_balance;
			}
		}

		// 4. Qonto API statement indexing
		$indexed_statements = array();
		if (is_array($qonto_statements_raw)) {
			foreach ($qonto_statements_raw as $stmt) {
				if (!empty($stmt['period'])) {
					$indexed_statements[$stmt['period']] = $stmt;
				}
			}
		}

		// 5. Final assembly
		$format_setting = getDolGlobalString('QONTOSYNC_STATEMENT_FORMAT') !== '' ? getDolGlobalString('QONTOSYNC_STATEMENT_FORMAT') : '{Y}-{m}';

		for ($m = 1; $m <= 12; $m++) {
			$period_key = sprintf("%02d-%04d", $m, $year);
			$month_label = dol_print_date(strtotime(sprintf("%04d-%02d-01", $year, $m)), "%B");
			$filter_start_month = sprintf("%04d-%02d-01", $year, $m);

			$has_statement_qonto = isset($indexed_statements[$period_key]);
			
			// Vérification GED
			$time_month = strtotime($filter_start_month);
			$raw_ref = preg_replace_callback('/\{([^}]+)\}/', function($matches) use ($time_month) {
				return date($matches[1], $time_month);
			}, $format_setting);
			$clean_ref = dol_sanitizeFileName($raw_ref);
			$ref_releve = substr($clean_ref, 0, 50);
			
			$expected_filename = $ref_releve . '.pdf';
			
			// Path compliant with the Dolibarr standard
			$file_path = $conf->bank->dir_output . '/' . $account->id . '/statement/' . $ref_releve . '/' . $expected_filename;

			$in_dolibarr = file_exists($file_path);

			$file_param = urlencode($account->id) . '/statement/' . urlencode($ref_releve) . '/' . urlencode($expected_filename);
			$dolibarr_url = DOL_URL_ROOT . '/document.php?modulepart=bank&file=' . $file_param;

			$months_data[$m] = array(
				'label'            => $month_label,
				'dolibarr_opening' => isset($dolibarr_balances[$m]['opening']) ? $dolibarr_balances[$m]['opening'] : 0,
				'dolibarr_closing' => isset($dolibarr_balances[$m]['closing']) ? $dolibarr_balances[$m]['closing'] : 0,
				'qonto_opening'    => isset($qonto_month_opening[$m]) ? $qonto_month_opening[$m] : 0,
				'qonto_closing'    => isset($qonto_month_closing[$m]) ? $qonto_month_closing[$m] : 0,
				'in_qonto'         => $has_statement_qonto,
				'in_dolibarr'      => $in_dolibarr,
				'dolibarr_url'     => $dolibarr_url,
				'ref_releve'       => $ref_releve,
				'statement_url'    => $has_statement_qonto ? $indexed_statements[$period_key]['file']['file_url'] : ''
			);
		}

		return $months_data;
	}
}