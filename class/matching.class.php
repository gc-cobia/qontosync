<?php

/**
 * Logique métier pour le rapprochement (Matching)
 */
class Matching
{
	/** @var DoliDB Database handler */
	public $db;
	/** @var string Erreur */
	public $error;

	/**
	 * @param DoliDB $db Database handler
	 */
	public function __construct($db)
	{
		$this->db = $db;
	}

	/**
	 * Lie une transaction Qonto à une écriture bancaire Dolibarr via l'extrafield
	 *
	 * @param int    $bank_line_id  ID de l'écriture (rowid dans llx_bank)
	 * @param string $qonto_id      ID unique de la transaction Qonto
	 * @return int                  1 si OK, -1 si KO
	 */
	public function linkTransaction($bank_line_id, $qonto_id)
	{
		$this->db->begin();

		$sql = "UPDATE " . MAIN_DB_PREFIX . "bank_extrafields";
		$sql .= " SET qonto_id = '" . $this->db->escape($qonto_id) . "'";
		$sql .= " WHERE fk_object = " . (int) $bank_line_id;

		$resql = $this->db->query($sql);

		if ($resql) {
			$this->db->commit();
			return 1;
		} else {
			$this->error = $this->db->lasterror();
			$this->db->rollback();
			return -1;
		}
	}
}
