<?php

/**
 * Client API Qonto - Logique de connexion et collecte uniquement
 */
class Qonto
{
	public $db;
	public $error;
	public $errors = array();

	public function __construct($db)
	{
		$this->db = $db;
	}

	public function fetchTransactions($iban, $month, $year)
	{
		global $conf;

		$apiKey = $conf->global->QONTOSYNC_API_KEY;
		$slug   = $conf->global->QONTOSYNC_SLUG;

		if (empty($apiKey) || empty($slug)) {
			$this->error = "QontoSyncErrorSetupMissing";
			dol_syslog("QontoSync: Échec - Identifiants API manquants dans la configuration", LOG_WARNING);
			return -1;
		}

		try {
			$startDate = new DateTime("$year-$month-01T00:00:00Z");
			$endDate   = clone $startDate;
			$endDate->modify('last day of this month')->setTime(23, 59, 59);
		} catch (Exception $e) {
			$this->error = "QontoSyncErrorInvalidDate";
			dol_syslog("QontoSync: Échec - Format de date invalide pour $month/$year", LOG_ERR);
			return -1;
		}

		// Nettoyage strict de l'IBAN (espaces, tirets, mise en majuscules)
		$iban_clean = strtoupper(str_replace(array(' ', '-'), '', $iban));
		dol_syslog("QontoSync: Lancement de la requête API pour l'IBAN " . $iban_clean . " sur la période " . $startDate->format('Y-m'), LOG_DEBUG);

		$url = "https://thirdparty.qonto.com/v2/transactions";
		$queryParams = http_build_query(array(
			'slug'            => $slug,
			'iban'            => $iban_clean,
			'settled_at_from' => $startDate->format('Y-m-d\TH:i:s\Z'),
			'settled_at_to'   => $endDate->format('Y-m-d\TH:i:s\Z'),
			'status[]'        => 'completed'
		));

		$ch = curl_init($url . '?' . $queryParams);
		curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
		curl_setopt($ch, CURLOPT_TIMEOUT, 20); // Timeout de 20 secondes max
		curl_setopt($ch, CURLOPT_HTTPHEADER, array(
			'Authorization: ' . $slug . ':' . $apiKey,
			'Accept: application/json'
		));

		$response = curl_exec($ch);
		$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
		$curlErr  = curl_error($ch);
		curl_close($ch);

		if ($response === false) {
			$this->error = "QontoSyncErrorCurl";
			$this->errors = array($curlErr);
			dol_syslog("QontoSync: Erreur de connexion cURL : " . $curlErr, LOG_ERR);
			return -1;
		}

		$data = json_decode($response, true);

		if ($httpCode !== 200) {
			$this->error = "QontoSyncErrorAPI";
			$this->errors = isset($data['errors']) ? $data['errors'] : array("HTTP Code $httpCode");
			dol_syslog("QontoSync: Erreur API (Code " . $httpCode . ") - " . json_encode($this->errors), LOG_ERR);
			return -1;
		}

		$count = isset($data['transactions']) ? count($data['transactions']) : 0;
		dol_syslog("QontoSync: Succès de l'API. " . $count . " transactions récupérées.", LOG_DEBUG);

		return isset($data['transactions']) ? $data['transactions'] : array();
	}
}
