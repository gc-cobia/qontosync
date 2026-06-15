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
	public $traceId = '';
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
			dol_syslog("QontoSync: Configuration parameter missing (slug or API key)", LOG_ERR);
		} else {
			dol_syslog("QontoSync: API initialized. Sandbox mode: " . ($this->sandbox ? 'YES' : 'NO'), LOG_DEBUG);
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
		// Init trace ID
		$this->traceId = '';

		// 1. Guard clause: If a configuration error was already detected, skip execution
		if (!empty($this->error)) {
			dol_syslog("QontoSync: Request aborted due to previous error: " . $this->error, LOG_WARNING);
			return false;
		}

		// 2. Build the full URL
		$url = $this->baseUrl . $endpoint;
		dol_syslog("QontoSync: Sending GET request to " . $url, LOG_DEBUG);

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

		// 4.1 Capture trace ID in headers
		$responseHeaders = array();
		curl_setopt($ch, CURLOPT_HEADERFUNCTION, function($ch, $headerLine) use (&$responseHeaders) {
			$len = strlen($headerLine);
			$parts = explode(':', $headerLine, 2);
			if (count($parts) === 2) {
				$key = strtolower(trim($parts[0]));
				$value = trim($parts[1]);
				$responseHeaders[$key] = $value;
			}
			return $len;
		});
		
		// 5. Execute the HTTP request
		$response = curl_exec($ch);

		// 6. Handle transport-level errors (e.g. DNS, connection timeout, server unreachable)
		if ($response === false) {
			$this->error = curl_error($ch);
			dol_syslog("QontoSync: cURL error: " . $this->error, LOG_ERR);
			curl_close($ch);
			return false;
		}

		// 7. Retrieve the HTTP status code and close the cURL resource
		$httpcode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
		curl_close($ch);

		// 8. Handle API-level response status codes
		if ($httpcode == 200) {
			// Capture trace ID in headers
			if (!empty($responseHeaders['x-tyk-trace-id'])) {
				$this->traceId = $responseHeaders['x-tyk-trace-id'];
			}
			dol_syslog("QontoSync: Request successful (200). Trace-ID: " . $this->traceId, LOG_DEBUG);
			return $response;
		} else {
			// Tenter d'extraire le Trace-ID depuis l'en-tête HTTP
			if (!empty($responseHeaders['x-tyk-trace-id'])) {
				$this->traceId = $responseHeaders['x-tyk-trace-id'];
			} else {
				// If absent from the header, look in the JSON body of the error
				$data = json_decode($response, true);
				if (is_array($data) && isset($data['trace_id'])) {
					$this->traceId = $data['trace_id'];
				}
			}

			// Format the HTTP code as a clean translation key pattern
			$this->error = "ErrorHTTPCode" . $httpcode;

			// If a Trace-ID has been intercepted, add it to the error message for the interface
			if (!empty($this->traceId)) {
				$this->error .= " (Trace-ID: " . $this->traceId . ")";
			}
			
			// Log in Dolibarr (SYSLOG)
			dol_syslog("QontoSync: API Error HTTP " . $httpcode . " on URL " . $url, LOG_ERR);
			dol_syslog("QontoSync: Trace-ID: " . $this->traceId, LOG_ERR);
			dol_syslog("QontoSync: Raw API Response: " . $response, LOG_ERR);
			
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
		// Clean the IBAN by removing spaces
		$clean_iban = str_replace(' ', '', $iban);
		$base_endpoint = "/v2/transactions?iban=" . urlencode($clean_iban) . "&settled_at_from=" . urlencode($dateStart . "T00:00:00.000Z") . "&settled_at_to=" . urlencode($dateEnd . "T23:59:59.999Z");
		
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

		dol_syslog("QontoSync: getTransactions fetched " . count($all_transactions) . " transactions.", LOG_DEBUG);

		return $all_transactions;
	}
	
	/**
	 * Get a single transaction from Qonto API by its ID
	 *
	 * @param string $transaction_id ID of the transaction
	 * @return array|bool Array of the transaction data or false on error
	 */
	public function getTransaction($transaction_id)
	{
		$endpoint = "/v2/transactions/" . urlencode(trim($transaction_id));
		
		$response = $this->sendRequest($endpoint);
		
		if ($response === false) {
			return false;
		}

		$data = json_decode($response, true);
		
		if (isset($data['transaction'])) {
        dol_syslog("QontoSync: getTransaction ' . $transaction_id . ' from Qonto.", LOG_DEBUG);
			return $data['transaction'];
		}

		return false;
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
