<?php
/*
 * QontoSync dolibarr module 
 * @file       qontorepository.class.php
 * @author     Gaëtan COURTOIS
 * @license    GPLv3
 */

class QontoRepository
{
	public $db;

	public function __construct($db)
	{
		$this->db = $db;
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
			'vat'        => array()
		);

		// 1. Fetch unpaid customers invoices
		if (!empty($conf->facture->enabled) && ($type === 'all' || $type === 'cinv')) {
			$sql_c_inv = "SELECT f.rowid, f.ref, f.total_ttc, f.type, f.datef, s.nom as thirdparty_name ";
			$sql_c_inv .= "FROM " . MAIN_DB_PREFIX . "facture as f ";
			$sql_c_inv .= "LEFT JOIN " . MAIN_DB_PREFIX . "societe as s ON f.fk_soc = s.rowid ";
			$sql_c_inv .= "WHERE f.entity IN (" . getEntity('facture') . ") AND f.fk_statut = 1 AND f.paye = 0";

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
						$documents['c_invoices'][] = (object) array(
						'rowid'         => $fact->id,
						'label'         => $fact->ref,
						'amount'        => $remain,
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
			$sql_s_inv .= "FROM " . MAIN_DB_PREFIX . "facture_fourn as f ";
			$sql_s_inv .= "LEFT JOIN " . MAIN_DB_PREFIX . "societe as s ON f.fk_soc = s.rowid ";
			$sql_s_inv .= "WHERE f.entity IN (" . getEntity('facture_fourn') . ") AND f.fk_statut = 1 AND f.paye = 0";

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
						$documents['s_invoices'][] = (object) array(
							'rowid'        => $fact_fourn->id,
							'label'        => $fact_fourn->ref,
							'amount'       => $remain,
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
}