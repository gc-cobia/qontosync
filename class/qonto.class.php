<?php

/**
 * Client API Qonto - Logique de connexion et collecte uniquement
 */
class Qonto
{
	/**
	 * @var DoliDB Database handler
	 */
	public $db;

	/**
	 * @var string Libellé de l'erreur (clé de traduction)
	 */
	public $error;

	/**
	 * @var array Détails des erreurs renvoyées par l'API
	 */
	public $errors = array();

	/**
	 * Constructeur
	 *
	 * @param DoliDB $db Database handler
	 */
	public function __construct($db)
	{
		$this->db = $db;
	}

	/**
	 * Récupère les transactions brutes depuis l'API Qonto
	 *
	 * @param string $iban         IBAN du compte
	 * @param int    $month        Mois (1-12)
	 * @param int    $year         Année (YYYY)
	 * @return array|int           Tableau de transactions ou -1 en cas d'échec
	 */
	public function fetchTransactions($iban, $month, $year)
	{
		global $conf;

		$apiKey = $conf->global->QONTOSYNC_API_KEY;
		$slug   = $conf->global->QONTOSYNC_SLUG;

		if (empty($apiKey) || empty($slug)) {
			$this->error = "QontoSyncErrorSetupMissing";
			return -1;
		}

		// Préparation de la plage temporelle (ISO 8601)
		try {
			$startDate = new DateTime("$year-$month-01T00:00:00Z");
			$endDate   = clone $startDate;
			$endDate->modify('last day of this month')->setTime(23, 59, 59);
		} catch (Exception $e) {
			$this->error = "QontoSyncErrorInvalidDate";
			return -1;
		}

		$url = "https://thirdparty.qonto.com/v2/transactions";
		$queryParams = http_build_query(array(
			'slug'            => $slug,
			'iban'            => str_replace(' ', '', $iban),
			'settled_at_from' => $startDate->format('Y-m-d\TH:i:s\Z'),
			'settled_at_to'   => $endDate->format('Y-m-d\TH:i:s\Z'),
			'status[]'        => 'completed'
		));

		$ch = curl_init($url . '?' . $queryParams);
		curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
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
			return -1;
		}

		$data = json_decode($response, true);

		if ($httpCode !== 200) {
			$this->error = "QontoSyncErrorAPI";
			$this->errors = isset($data['errors']) ? $data['errors'] : array("HTTP Code $httpCode");
			return -1;
		}

		return isset($data['transactions']) ? $data['transactions'] : array();
	}
}
