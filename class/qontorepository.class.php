<?php
/*
 * QontoSync dolibarr module 
 * @file       class/qontorepository.class.php
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

class QontoRepository
{
	public $db;
    private $multicurrency_enabled;

	public function __construct($db)
	{
        global $conf;

		$this->db = $db;
        $this->multicurrency_enabled = !empty($conf->multicurrency->enabled);
	}

    /**
     * Verify that the bank account is accessible within the current user's entity scope
     * (Multi-Company Security)
     *
     * @param int $bank_id Bank account ID to be verified
     * @return bool True if the account is authorized, False otherwise.
     */
    public function isAccountAllowed($bank_id)
    {
        global $conf;

        if (empty($bank_id) || $bank_id <= 0) {
            return false;
        }

        $sql = "SELECT entity FROM " . MAIN_DB_PREFIX . "bank_account WHERE rowid = " . (int) $bank_id;
        $resql = $this->db->query($sql);

        if (!$resql || $this->db->num_rows($resql) == 0) {
            dol_syslog(__METHOD__ . ": Bank account not found: " . $bank_id, LOG_ERR);
            return false;
        }

        $obj = $this->db->fetch_object($resql);
        $this->db->free($resql);

        $account_entity = (int) $obj->entity;

        if ($account_entity === (int) $conf->entity) {
            return true;
        }

        if (function_exists('getEntity')) {
            $allowed_entities = explode(',', getEntity('bank_account'));
            return in_array((string) $account_entity, $allowed_entities);
        }

        return false;
    }
	
	/**
	 * Retrieve unpaid documents (Customers/Suppliers invoices/credit notes, VAT)
	 * Array will not return the total amount but the remain amount to pay
	 *
	 * @param string $type Document type ('all', 'cinv', 'sinv', 'vat')
	 * @return array Multidimensional array with unpaid documents
	 */
	public function getUnpaidDocuments($type = 'all')
	{
        global $conf;

		$documents = array(
            'c_invoices' => array(),
            's_invoices' => array(),
            'vat'        => array(),
            'social'     => array(),
            'salary'     => array(),
            'expense'    => array()
        );
		
		// Dynamic boundary recovery
		$max_months = getDolGlobalInt('QONTOSYNC_MAX_MONTHS');
		if (empty($max_months) || $max_months <= 0) {
		    $max_months = 24; // Safety value (fallback)
		}
		
		// Calculating the deadline (-X months from today)
		$date_limit = $this->db->idate(strtotime("-" . $max_months . " months"));
		
		// 1. Fetch unpaid customers invoices
		if (!empty($conf->facture->enabled) && ($type === 'all' || $type === 'cinv')) {
			$sql_c_inv = "SELECT f.rowid, f.ref, f.total_ttc, f.type, f.datef, s.nom as thirdparty_name ";

            // If multicurrency is enabled
            if ($this->multicurrency_enabled) {
                $sql_c_inv .= ", f.multicurrency_code, f.multicurrency_tx, f.multicurrency_total_ttc, ";
                $sql_c_inv .= "(SELECT SUM(multicurrency_amount) FROM " . MAIN_DB_PREFIX . "paiement_facture WHERE fk_facture = f.rowid) as already_paid_mc ";
            }

			$sql_c_inv .= "FROM " . MAIN_DB_PREFIX . "facture as f ";
			$sql_c_inv .= "LEFT JOIN " . MAIN_DB_PREFIX . "societe as s ON f.fk_soc = s.rowid ";
			$sql_c_inv .= "WHERE f.entity IN (" . getEntity('facture') . ") AND f.fk_statut = 1 AND f.paye = 0 ";
			$sql_c_inv .= "AND f.datef >= '" . $date_limit . "'";

			$resql_c_inv = $this->db->query($sql_c_inv);
			if ($resql_c_inv) {
				if (!class_exists('Facture')) require_once DOL_DOCUMENT_ROOT.'/compta/facture/class/facture.class.php';
				$fact = new Facture($this->db);							
				
				while ($obj_c_inv = $this->db->fetch_object($resql_c_inv)) {

					$fact->id = $obj_c_inv->rowid;
					$fact->ref = $obj_c_inv->ref;
					$fact->total_ttc = $obj_c_inv->total_ttc;
					$fact->type = $obj_c_inv->type;
					$fact->date = $this->db->jdate($obj_c_inv->datef);
					
					$remain = $fact->getRemainToPay();						
					if ($remain != 0) {
                        // If multicurrency is enabled
                        $mc_code = !empty($this->multicurrency_enabled) ? $obj_c_inv->multicurrency_code : '';
                        $mc_tx = (!empty($this->multicurrency_enabled) && $obj_c_inv->multicurrency_tx > 0) ? (float)$obj_c_inv->multicurrency_tx : 1;
                        if ($mc_code && $mc_code != $conf->currency) {
                            $mc_total = (float)$obj_c_inv->multicurrency_total_ttc;
                            $mc_paid = (float)$obj_c_inv->already_paid_mc;
                            $mc_remain = $mc_total - $mc_paid;
                        } else {
                            $mc_remain = $remain;
                        }

						$documents['c_invoices'][] = (object) array(
                            'rowid'         => $fact->id,
                            'label'         => $fact->ref,
                            'amount'        => $remain,
                            'mc_amount'     => $mc_remain,
                            'mc_code'       => $mc_code,
                            'mc_tx'         => $mc_tx,
                            'datev'         => $fact->date,
                            'thirdparty'    => $obj_c_inv->thirdparty_name,
                            'inv_type'      => (int)$obj_c_inv->type
						);
					}
				}
				$this->db->free($resql_c_inv);
			}
		}

		// 2. Fetch unpaid suppliers invoices
		if (!empty($conf->fournisseur->enabled) && ($type === 'all' || $type === 'sinv')) {
			$sql_s_inv = "SELECT f.rowid, f.ref, f.ref_supplier, f.total_ttc, f.type, f.datef, s.nom as thirdparty_name ";
            
            // If multicurrency is enabled
            if ($this->multicurrency_enabled) {
                $sql_s_inv .= ", f.multicurrency_code, f.multicurrency_tx, f.multicurrency_total_ttc, ";
                $sql_s_inv .= "(SELECT SUM(multicurrency_amount) FROM " . MAIN_DB_PREFIX . "paiementfourn_facturefourn WHERE fk_facturefourn = f.rowid) as already_paid_mc ";
            }

			$sql_s_inv .= "FROM " . MAIN_DB_PREFIX . "facture_fourn as f ";
			$sql_s_inv .= "LEFT JOIN " . MAIN_DB_PREFIX . "societe as s ON f.fk_soc = s.rowid ";
			$sql_s_inv .= "WHERE f.entity IN (" . getEntity('facture_fourn') . ") AND f.fk_statut = 1 AND f.paye = 0 ";
			$sql_s_inv .= "AND f.datef >= '" . $date_limit . "'";

			$resql_s_inv = $this->db->query($sql_s_inv);
			if ($resql_s_inv) {
				if (!class_exists('FactureFournisseur')) require_once DOL_DOCUMENT_ROOT.'/fourn/class/fournisseur.facture.class.php';
				$fact_fourn = new FactureFournisseur($this->db);
				
				while ($obj_s_inv = $this->db->fetch_object($resql_s_inv)) {
					$fact_fourn->id = $obj_s_inv->rowid;
					$fact_fourn->ref = $obj_s_inv->ref;
					$fact_fourn->total_ttc = $obj_s_inv->total_ttc;
					$fact_fourn->type = $obj_s_inv->type;
					$fact_fourn->date = $this->db->jdate($obj_s_inv->datef);
					
					$remain = $fact_fourn->getRemainToPay();
					if ($remain != 0) {
                        // If multicurrency is enabled
                        $mc_code = !empty($this->multicurrency_enabled) ? $obj_s_inv->multicurrency_code : '';
                        $mc_tx = (!empty($this->multicurrency_enabled) && $obj_s_inv->multicurrency_tx > 0) ? (float)$obj_s_inv->multicurrency_tx : 1;
                        if ($mc_code && $mc_code != $conf->currency) {
                            $mc_total = (float)$obj_s_inv->multicurrency_total_ttc;
                            $mc_paid = (float)$obj_s_inv->already_paid_mc;
                            $mc_remain = $mc_total - $mc_paid;
                        } else {
                            $mc_remain = $remain;
                        }

						$documents['s_invoices'][] = (object) array(
							'rowid'        => $fact_fourn->id,
							'label'        => $fact_fourn->ref,
							'amount'       => $remain,
                            'mc_amount'     => $mc_remain,
                            'mc_code'       => $mc_code,
                            'mc_tx'         => $mc_tx,
							'datev'        => $fact_fourn->date,
							'thirdparty'   => $obj_s_inv->thirdparty_name,
							'ref_supplier' => $obj_s_inv->ref_supplier,
							'inv_type'     => (int)$obj_s_inv->type
						);
					}
				}
				$this->db->free($resql_s_inv);
			}
		}

		// 3. Fetch unpaid VAT declarations
		if (!empty($conf->tax->enabled) && ($type === 'all' || $type === 'vat')) {
			$sql_vat = "SELECT rowid, label, amount, datev FROM " . MAIN_DB_PREFIX . "tva WHERE entity IN (" . getEntity('tva') . ") AND paye = 0";

			$resql_vat = $this->db->query($sql_vat);
			if ($resql_vat) {
				while ($obj_vat = $this->db->fetch_object($resql_vat)) {
					$documents['vat'][] = (object) array(
						'rowid'    => $obj_vat->rowid,
						'label'    => $obj_vat->label ? $obj_vat->label : 'VAT',
						'amount'   => (float)$obj_vat->amount,
						'datev'    => $this->db->jdate($obj_vat->datev),
						'inv_type' => 0
					);
				}
				$this->db->free($resql_vat);
			}
		}

        // 4. Fetch unpaid Social/Tax Charges
        if (!empty($conf->tax->enabled) && ($type === 'all' || $type === 'social')) {
            $sql_soc = "SELECT rowid, libelle as label, amount, date_ech FROM " . MAIN_DB_PREFIX . "chargesociales WHERE entity IN (" . getEntity('tax') . ") AND paye = 0 AND date_ech >= '" . $date_limit . "'";
            $resql_soc = $this->db->query($sql_soc);
            if ($resql_soc) {
                while ($obj_soc = $this->db->fetch_object($resql_soc)) {
                    $documents['social'][] = (object) array(
                        'rowid' => $obj_soc->rowid,
                        'label' => $obj_soc->label,
                        'amount' => (float)$obj_soc->amount,
                        'datev' => $this->db->jdate($obj_soc->date_ech),
                        'inv_type' => 0);
                }
                $this->db->free($resql_soc);
            }
        }

        // 5. Fetch unpaid Salaries
        if (!empty($conf->salaries->enabled) && ($type === 'all' || $type === 'salary')) {
            // Get datesp (start) and dateep (end)
            $sql_sal = "SELECT s.rowid, s.label, s.amount, s.datesp, s.dateep, u.lastname, u.firstname ";
            $sql_sal .= "FROM " . MAIN_DB_PREFIX . "salary as s ";
            $sql_sal .= "LEFT JOIN " . MAIN_DB_PREFIX . "user as u ON s.fk_user = u.rowid ";
            $sql_sal .= "WHERE s.entity IN (" . getEntity('salary') . ") AND s.paye = 0 AND s.dateep >= '" . $date_limit . "'";
            
            $resql_sal = $this->db->query($sql_sal);
            if ($resql_sal) {
                while ($obj_sal = $this->db->fetch_object($resql_sal)) {
                    
                    // date formatting
                    $start = dol_print_date($this->db->jdate($obj_sal->datesp), 'day');
                    $end = dol_print_date($this->db->jdate($obj_sal->dateep), 'day');
                    
                    $fallback_name = trim($obj_sal->firstname . ' ' . $obj_sal->lastname);
                    
                    // Base du libellé (celui saisi en base, ou "Salaire" par défaut)
                    $base_label = !empty($obj_sal->label) ? $obj_sal->label : "Salaire";
                    
                    // Construction d'un libellé toujours clair : "Libellé - Prénom Nom (01/05/2026 - 31/05/2026)"
                    $display_label = $base_label;
                    if (!empty($fallback_name)) {
                        $display_label .= " - " . $fallback_name;
                    }
                    $display_label .= " (" . $start . " - " . $end . ")";
                    
                    $documents['salary'][] = (object) array(
                        'rowid' => $obj_sal->rowid, 
                        'label' => $display_label, 
                        'amount' => (float)$obj_sal->amount, 
                        'datev' => $this->db->jdate($obj_sal->dateep), 
                        'inv_type' => 0
                    );
                }
                $this->db->free($resql_sal);
            }
        }

        // 6. Fetch unpaid Expense Reports
        if (!empty($conf->expensereport->enabled) && ($type === 'all' || $type === 'expense')) {
            $sql_exp = "SELECT rowid, ref as label, total_ttc as amount, date_debut ";
            $sql_exp .= "FROM " . MAIN_DB_PREFIX . "expensereport ";
            $sql_exp .= "WHERE entity IN (" . getEntity('expensereport') . ") AND fk_statut = 5 AND date_debut >= '" . $date_limit . "'";
            
            $resql_exp = $this->db->query($sql_exp);
            if ($resql_exp) {
                while ($obj_exp = $this->db->fetch_object($resql_exp)) {
                    $documents['expense'][] = (object) array('rowid' => $obj_exp->rowid, 'label' => $obj_exp->label, 'amount' => (float)$obj_exp->amount, 'datev' => $this->db->jdate($obj_exp->date_debut), 'inv_type' => 0);
                }
                $this->db->free($resql_exp);
            }
        }

		return $documents;
	}
	
	/**
     * Retrieve unlinked bank lines for a specific bank account
     *
     * @param int $bank_id Bank account id
     * @return array Array of unlinked bank lines objects
     */
    public function getUnlinkedBankLines($bank_id)
    {
        if (empty($bank_id)) {
			dol_syslog(__METHOD__ . ": Error on input parameters", LOG_ERR);
            return array();
        }

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

        return $unlinked_bank_lines;
    }

	/**
     * Fetch linked Qonto IDs from the database for a specific bank account.
     *
     * @param int $bank_id
     * @param string|null $qonto_id
     * @return array Associative array [qonto_id => fk_object]
     */
    public function getLinkedQontoIds($bank_id, $qonto_id = null)
    {
        if (empty($bank_id)) {
            dol_syslog(__METHOD__ . ": Error on input parameters", LOG_ERR);
            return array();
        }

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
     * Retrieve details of documents linked to a bank entry
     *
     * @param int $bank_id Bank account id
     * @return array Array of linked documents details
     */
    public function getLinkedDetails($bank_id)
    {
        if (empty($bank_id)) {
            dol_syslog(__METHOD__ . ": Error on input parameters", LOG_ERR);
            return array();
        }

        $details = array();
        
        // Searches for payments associated with this bank line
        $sql = "SELECT url_id, type FROM " . MAIN_DB_PREFIX . "bank_url WHERE fk_bank = " . (int)$bank_id;
        $res = $this->db->query($sql);
        
        if ($res) {
            while ($obj = $this->db->fetch_object($res)) {
                // Trace the payment back to the corresponding documents
                if ($obj->type == 'payment') {
                    $sql2 = "SELECT f.rowid, f.ref, pf.amount, 'cinv' as doc_type, pf.fk_paiement as payment_id 
                             FROM " . MAIN_DB_PREFIX . "paiement_facture as pf 
                             JOIN " . MAIN_DB_PREFIX . "facture as f ON pf.fk_facture = f.rowid 
                             WHERE pf.fk_paiement = " . (int)$obj->url_id;
                             
                    $res2 = $this->db->query($sql2);
                    if ($res2) {
                        while ($doc = $this->db->fetch_object($res2)) {
                            $details[] = clone $doc;
                        }
                        $this->db->free($res2);
                    }
                } elseif ($obj->type == 'payment_supplier') {
                    $sql2 = "SELECT f.rowid, f.ref, f.ref_supplier, pf.amount, 'sinv' as doc_type, pf.fk_paiementfourn as payment_id 
                             FROM " . MAIN_DB_PREFIX . "paiementfourn_facturefourn as pf 
                             JOIN " . MAIN_DB_PREFIX . "facture_fourn as f ON pf.fk_facturefourn = f.rowid 
                             WHERE pf.fk_paiementfourn = " . (int)$obj->url_id;
                             
                    $res2 = $this->db->query($sql2);
                    if ($res2) {
                        while ($doc = $this->db->fetch_object($res2)) {
                            $details[] = clone $doc;
                        }
                        $this->db->free($res2);
                    }
                } elseif ($obj->type == 'payment_vat') {
                    $sql2 = "SELECT t.rowid, t.label as ref, pv.amount, 'vat' as doc_type, pv.rowid as payment_id 
                             FROM " . MAIN_DB_PREFIX . "payment_vat as pv 
                             JOIN " . MAIN_DB_PREFIX . "tva as t ON pv.fk_tva = t.rowid 
                             WHERE pv.rowid = " . (int)$obj->url_id;
                             
                    $res2 = $this->db->query($sql2);
                    if ($res2) {
                        while ($doc = $this->db->fetch_object($res2)) {
                            $details[] = clone $doc;
                        }
                        $this->db->free($res2);
                    }
                } elseif ($obj->type == 'payment_sc') {
                    $sql2 = "SELECT c.rowid, c.ref, pc.amount, 'social' as doc_type, pc.rowid as payment_id 
                             FROM " . MAIN_DB_PREFIX . "paiementcharge as pc 
                             JOIN " . MAIN_DB_PREFIX . "chargesociales as c ON pc.fk_charge = c.rowid 
                             WHERE pc.rowid = " . (int)$obj->url_id;
                             
                    $res2 = $this->db->query($sql2);
                    if ($res2) {
                        while ($doc = $this->db->fetch_object($res2)) {
                            $details[] = clone $doc;
                        }
                        $this->db->free($res2);
                    }
                } elseif ($obj->type == 'payment_expensereport') {
                    $sql2 = "SELECT e.rowid, e.ref, pe.amount, 'expense' as doc_type, pe.rowid as payment_id 
                             FROM " . MAIN_DB_PREFIX . "payment_expensereport as pe 
                             JOIN " . MAIN_DB_PREFIX . "expensereport as e ON pe.fk_expensereport = e.rowid 
                             WHERE pe.rowid = " . (int)$obj->url_id;
                             
                    $res2 = $this->db->query($sql2);
                    if ($res2) {
                        while ($doc = $this->db->fetch_object($res2)) {
                            $details[] = clone $doc;
                        }
                        $this->db->free($res2);
                    }
                } elseif ($obj->type == 'payment_salary') {
                    $sql2 = "SELECT s.rowid, s.label as ref, ps.amount, 'salary' as doc_type, ps.rowid as payment_id 
                             FROM " . MAIN_DB_PREFIX . "payment_salary as ps 
                             JOIN " . MAIN_DB_PREFIX . "salary as s ON ps.fk_salary = s.rowid 
                             WHERE ps.rowid = " . (int)$obj->url_id;
                             
                    $res2 = $this->db->query($sql2);
                    if ($res2) {
                        while ($doc = $this->db->fetch_object($res2)) {
                            $details[] = clone $doc;
                        }
                        $this->db->free($res2);
                    }
                }
            }
            $this->db->free($res);
        }
        
        // Looking for Miscellaneous Payments (Direct link via fk_bank)
        $sql_var = "SELECT rowid as payment_id, label as ref, amount, 'various' as doc_type 
                    FROM " . MAIN_DB_PREFIX . "payment_various 
                    WHERE fk_bank = " . (int)$bank_id;
        $res_var = $this->db->query($sql_var);
        
        if ($res_var) {
            while ($doc = $this->db->fetch_object($res_var)) {
                $details[] = clone $doc;
            }
            $this->db->free($res_var);
        }
        
        return $details;
    }

    /**
	 * Retrieve Dolibarr opening and closing balances for each month of a given year
	 *
	 * @param int $bank_id Bank account id
	 * @param int $year Year to analyze
	 * @return array Array of months (1 to 12) with opening and closing balances
	 */
    public function getMonthlyBalances($bank_id, $year)
    {
        if (empty($bank_id) || empty($year)) {
            dol_syslog(__METHOD__ . ": Error on input parameters", LOG_ERR);
            return array();
        }
       
        // Load the native Dolibarr Account class
        if (!class_exists('Account')) require_once DOL_DOCUMENT_ROOT . '/compta/bank/class/account.class.php';
        $account = new Account($this->db);
        $account->fetch($bank_id);

        $balances = array();

        for ($m = 1; $m <= 12; $m++) {
            // Defining the month's boundaries
            $filter_start_month = sprintf("%04d-%02d-01", $year, $m);
            $filter_end_month = date("Y-m-t", strtotime($filter_start_month));

             // 1. OPENING balance for the month (Balance as of the day before the 1st of the month)
            $veille_start = date("Y-m-d", strtotime("-1 day", strtotime($filter_start_month)));
            $opening = $account->solde(1, $veille_start);

            // 2. Month-end CLOSING balance (Balance as of the last day of the month)
            $closing = $account->solde(1, $filter_end_month);

            $balances[$m] = array(
                'opening' => (float)$opening,
                'closing' => (float)$closing
            );
        }

        return $balances;
    }

    /**
     * Returns the total already paid in the invoice currency (multi-currency)
     *
     * @param int    $doc_id Invoice ID
     * @param string $type   'cinv' (customer) or 'sinv' (supplier)
     * @return float         Total amount paid in foreign currency
     */
    public function getMulticurrencyPaidAmount($doc_id, $type = 'cinv')
    {
        $table = ($type === 'cinv') ? 'paiement_facture' : 'paiementfourn_facturefourn';
        $field = ($type === 'cinv') ? 'fk_facture' : 'fk_facturefourn';

        $sql = "SELECT SUM(multicurrency_amount) as amount_paid_devise ";
        $sql .= "FROM " . MAIN_DB_PREFIX . $table . " ";
        $sql .= "WHERE " . $field . " = " . (int)$doc_id;

        $resql = $this->db->query($sql);
        
        if ($resql && $obj = $this->db->fetch_object($resql)) {
            $amount = (float)$obj->amount_paid_devise;
            $this->db->free($resql);
            return $amount;
        }
        
        return 0.0;
    }
}