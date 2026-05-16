<?php

/**
 * Logique métier pour le rapprochement (Matching)
 */
class Matching
{
	public $db;
	public $error;

	public function __construct($db)
	{
		$this->db = $db;
	}

	public function linkTransaction($bank_line_id, $qonto_id)
	{
		dol_syslog("QontoSync: Tentative de liaison - bank_line_id=" . $bank_line_id . " / qonto_id=" . $qonto_id, LOG_DEBUG);
		
		$this->db->begin();

		$sql = "UPDATE " . MAIN_DB_PREFIX . "bank_extrafields";
		$sql .= " SET qonto_id = '" . $this->db->escape($qonto_id) . "'";
		$sql .= " WHERE fk_object = " . (int) $bank_line_id;

		$resql = $this->db->query($sql);

		if ($resql) {
			$this->db->commit();
			dol_syslog("QontoSync: Liaison réussie en base de données.", LOG_DEBUG);
			return 1;
		} else {
			$this->error = $this->db->lasterror();
			$this->db->rollback();
			dol_syslog("QontoSync: Erreur SQL lors de la liaison - " . $this->error, LOG_ERR);
			return -1;
		}
	}
}
