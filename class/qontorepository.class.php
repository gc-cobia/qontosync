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
	 *
	 * @param string $type Document type ('all', 'cinv', 'sinv', 'vat')
	 * @return array Multidimensional array with unpaid documents
	 */
	public function getUnpaidDocuments($type = 'all')
	{
		global $conf; // Fetch global configuration to check if modules are enabled

		$documents = array(
			'c_invoices' => array(),
			's_invoices' => array(),
			'vat'        => array()
		);

		// 1. Fetch unpaid customers invoices (requires Facture module to be enabled)
		if (!empty($conf->facture->enabled) && ($type === 'all' || $type === 'cinv')) {
			$sql_c_inv = "SELECT f.rowid, f.ref, f.total_ttc, f.type, f.datef, s.nom as thirdparty_name ";
			$sql_c_inv .= "FROM " . MAIN_DB_PREFIX . "facture as f ";
			$sql_c_inv .= "LEFT JOIN " . MAIN_DB_PREFIX . "societe as s ON f.fk_soc = s.rowid ";
			$sql_c_inv .= "WHERE f.entity IN (" . getEntity('facture') . ") AND f.fk_statut = 1 AND f.paye = 0";

			$resql_c_inv = $this->db->query($sql_c_inv);
			if ($resql_c_inv) {
				// Lazy load the Customer Invoice class
				if (!class_exists('Facture')) {
					dol_include_once('/compta/facture/class/facture.class.php');
				}
				$fact = new Facture($this->db);							
				
				while ($obj_c_inv = $this->db->fetch_object($resql_c_inv)) {
					// Hydrate the object manually to use Dolibarr core functions
					$fact->id = $obj_c_inv->rowid;
					$fact->ref = $obj_c_inv->ref;
					$fact->total_ttc = $obj_c_inv->total_ttc;
					$fact->type = $obj_c_inv->type;
					$fact->date = $this->db->jdate($obj_c_inv->datef);
					
					// Get the exact remaining amount to pay
					$remain = $fact->getRemainToPay();						
					if ($remain != 0) {
						$documents['c_invoices'][] = (object) array(
							'rowid'         => $fact->id,
							'label'         => $fact->ref,
							'amount'        => $remain,
							'datev'         => $fact->date,
							'thirdparty'    => $obj_c_inv->thirdparty_name
						);
					}
				}
				$this->db->free($resql_c_inv);
			}
		}

		// 2. Fetch unpaid suppliers invoices (requires Fournisseur module to be enabled)
		if (!empty($conf->fournisseur->enabled) && ($type === 'all' || $type === 'sinv')) {
			$sql_s_inv = "SELECT f.rowid, f.ref, f.ref_supplier, f.total_ttc, f.type, f.datef, s.nom as thirdparty_name ";
			$sql_s_inv .= "FROM " . MAIN_DB_PREFIX . "facture_fourn as f ";
			$sql_s_inv .= "LEFT JOIN " . MAIN_DB_PREFIX . "societe as s ON f.fk_soc = s.rowid ";
			$sql_s_inv .= "WHERE f.entity IN (" . getEntity('facture_fourn') . ") AND f.fk_statut = 1 AND f.paye = 0";

			$resql_s_inv = $this->db->query($sql_s_inv);
			if ($resql_s_inv) {
				// Lazy load the Supplier Invoice class
				if (!class_exists('FactureFournisseur')) {
					dol_include_once('/fourn/class/fournisseur.facture.class.php');
				}
				$fact_fourn = new FactureFournisseur($this->db);
				
				while ($obj_s_inv = $this->db->fetch_object($resql_s_inv)) {
					// Hydrate the object manually
					$fact_fourn->id = $obj_s_inv->rowid;
					$fact_fourn->ref = $obj_s_inv->ref;
					$fact_fourn->total_ttc = $obj_s_inv->total_ttc;
					$fact_fourn->type = $obj_s_inv->type;
					$fact_fourn->date = $this->db->jdate($obj_s_inv->datef);
					
					// Get the exact remaining amount to pay
					$remain = $fact_fourn->getRemainToPay();
					if ($remain != 0) {
						$documents['s_invoices'][] = (object) array(
							'rowid'        => $fact_fourn->id,
							'label'        => $fact_fourn->ref,
							'amount'       => $remain,
							'datev'        => $fact_fourn->date,
							'thirdparty'   => $obj_s_inv->thirdparty_name,
							'ref_supplier' => $obj_s_inv->ref_supplier
						);
					}
				}
				$this->db->free($resql_s_inv);
			}
		}

		// 3. Fetch unpaid VAT declarations (requires Tax module to be enabled)
		if (!empty($conf->tax->enabled) && ($type === 'all' || $type === 'vat')) {
			$sql_vat = "SELECT rowid, label, amount, datev FROM " . MAIN_DB_PREFIX . "tva WHERE entity IN (" . getEntity('tva') . ") AND paye = 0";
			
			$resql_vat = $this->db->query($sql_vat);
			if ($resql_vat) {
				while ($obj_vat = $this->db->fetch_object($resql_vat)) {
					$documents['vat'][] = (object) array(
						'rowid'  => $obj_vat->rowid,
						'label'  => $obj_vat->label ? $obj_vat->label : 'VAT',
						'amount' => $obj_vat->amount,
						'datev'  => $this->db->jdate($obj_vat->datev)
					);
				}
				$this->db->free($resql_vat);
			}
		}

		return $documents;
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
			// Fees are usually debits (Direct Debit equivalent), but handles refunds (credit) as transfers
			if ($side === 'credit') {
				return getDolGlobalInt('QONTOSYNC_PM_TRANSFER');
			} else {
				return getDolGlobalInt('QONTOSYNC_PM_DD');
			}
		}
	
		return '';
	}
}