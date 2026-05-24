<?php
/*
 *  QontoSync dolibarr module 
 *  @file       qontoapi.class.php
 *  @author     Gaëtan COURTOIS
 *  @license    GPLv3
 */

class QontoApi
{
	// Set default URL for Qonto API
	const BASE_URL_PROD = "https://thirdparty.qonto.com";
	const BASE_URL_SANDBOX = "https://thirdparty-sandbox.staging.qonto.co";

	/**
	 * @var string
	 */
	public $error = '';
	private $apiKey;
	private $slug;
	private $sandbox;
	private $sandboxToken;
	private $baseUrl;

	/**
	 * QontoApi constructor.
	 *
	 * @param string $apiKey
	 * @param string $slug
	 * @param bool|int|null $sandbox
	 * @param string $sandboxToken
	 */
	public function __construct($apiKey = '', $slug = '', $sandbox = null, $sandboxToken = '')
	{
		global $conf;
		
		$apiKeyVal = !empty($apiKey) ? $apiKey : getDolGlobalString('QONTOSYNC_API_KEY');
		$slugVal = !empty($slug) ? $slug : getDolGlobalString('QONTOSYNC_SLUG');
		$sandboxTokenVal = !empty($sandboxToken) ? $sandboxToken : getDolGlobalString('QONTOSYNC_SANDBOX_TOKEN');

		$this->apiKey = trim($apiKeyVal);
		$this->slug = trim($slugVal);
		$this->sandbox = ($sandbox !== null) ? (bool)$sandbox : (bool)getDolGlobalString('QONTOSYNC_SANDBOX');
		$this->sandboxToken = trim($sandboxTokenVal);
		$this->baseUrl = $this->sandbox ? self::BASE_URL_SANDBOX : self::BASE_URL_PROD;

		// Check if slug or API key is missing
		if (empty($this->slug) || empty($this->apiKey)) {
			$this->error = "ErrorConfigParameterMissing";
		}
	}

	/**
	 * Send a GET request to the Qonto API.
	 *
	 * @param string $endpoint The API endpoint path (e.g. "/v2/organization")
	 * @return string|bool The raw JSON response string, or false on error
	 */
	private function sendRequest($endpoint)
	{
		// 1. Guard clause: If a configuration error was already detected, skip execution
		if (!empty($this->error)) {
			return false;
		}

		// 2. Build the full URL
		$url = $this->baseUrl . $endpoint;

		// 3. Build the HTTP headers dynamically using credentials and staging token if Sandbox is active
		$headers = array(
			"Authorization: " . $this->slug . ":" . $this->apiKey,
			"Accept: application/json",
			"User-Agent: Dolibarr QontoSync"
		);
		if ($this->sandbox && !empty($this->sandboxToken)) {
			$headers[] = "X-Qonto-Staging-Token: " . $this->sandboxToken;
		}

		// 4. Initialize the cURL session
		$ch = curl_init();
		curl_setopt($ch, CURLOPT_URL, $url);
		curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
		curl_setopt($ch, CURLOPT_TIMEOUT, 15);
		curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
		
		// 5. Execute the HTTP request
		$response = curl_exec($ch);

		// 6. Handle transport-level errors (e.g. DNS, connection timeout, server unreachable)
		if ($response === false) {
			$this->error = curl_error($ch);
			curl_close($ch);
			return false;
		}

		// 7. Retrieve the HTTP status code and close the cURL resource
		$httpcode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
		curl_close($ch);

		// 8. Handle API-level response status codes
		if ($httpcode == 200) {
			return $response;
		} else {
			// Format the HTTP code as a clean translation key pattern
			$this->error = "ErrorHTTPCode" . $httpcode;
			return false;
		}
	}

	/**
	 * Get transactions from Qonto API
	 *
	 * @param string $iban IBAN of the bank account
	 * @param string $dateStart ISO date YYYY-MM-DD
	 * @param string $dateEnd ISO date YYYY-MM-DD
	 * @return array|bool Array of transactions or false on error
	 */
	public function getTransactions($iban, $dateStart, $dateEnd)
	{
		$base_endpoint = "/v2/transactions?iban=" . urlencode($iban) . "&settled_at_from=" . urlencode($dateStart . "T00:00:00.000Z") . "&settled_at_to=" . urlencode($dateEnd . "T23:59:59.999Z");
		
		$all_transactions = array();
		$current_page = 1;

		do {
			$endpoint = $base_endpoint . "&current_page=" . $current_page;
			$response = $this->sendRequest($endpoint);
			
			if ($response === false) {
				return false;
			}

			$data = json_decode($response, true);
			
			if (isset($data['transactions']) && is_array($data['transactions'])) {
				$all_transactions = array_merge($all_transactions, $data['transactions']);
			}

			// Check if there is a next page according to Qonto API meta object
			if (isset($data['meta']) && !empty($data['meta']['next_page'])) {
				$current_page = (int)$data['meta']['next_page'];
			} else {
				$current_page = null;
			}
			
		} while ($current_page !== null);

		return $all_transactions;
	}

	/**
	 * Test the connection to the Qonto API without retrieving or processing the list of accounts.
	 *
	 * @return bool True if connection succeeded, false otherwise
	 */
	public function testConnection()
	{
		$response = $this->sendRequest("/v2/organization");
		return $response !== false;
	}
}
