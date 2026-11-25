<?php
/*
 * QontoSync dolibarr module 
 * @file       class/qontoapi.class.php
 * @author     Gaëtan COURTOIS
 * @license    GPLv3
 */

if (!defined('DOL_DOCUMENT_ROOT')) {
    http_response_code(403);
    exit('Access Forbidden');
}

/**
 * Client class for interacting with Qonto REST API v2.
 */
class QontoApi
{
	/** @var string */
	const BASE_URL_PROD = "https://thirdparty.qonto.com";
	/** @var string */
	const BASE_URL_SANDBOX = "https://thirdparty-sandbox.staging.qonto.co";

	/** @var string */
	public $error = '';
	/** @var string */
	public $traceId = '';
	/** @var string */
	private $apiKey;
	/** @var string */
	private $slug;
	/** @var bool */
	private $sandbox;
	/** @var string */
	private $sandboxToken;
	/** @var string */
	private $baseUrl;

	/**
	 * QontoApi constructor (falls back to Dolibarr module constants when empty/null).
	 *
	 * @param string        $apiKey       API key
	 * @param string        $slug         Organization slug
	 * @param bool|int|null $sandbox      Sandbox mode
	 * @param string        $sandboxToken Sandbox token
	 */
	public function __construct(string $apiKey = '', string $slug = '', $sandbox = null, string $sandboxToken = '')
	{
		// Note: We do not call dolDecrypt() manually here. QONTOSYNC_API_KEY (_KEY) and
		// QONTOSYNC_SANDBOX_TOKEN (_TOKEN) are automatically encrypted at rest by
		// dolibarr_set_const() and automatically decrypted into $conf->global on boot
		// by Conf::setValues(), so getDolGlobalString() already returns plaintext
		// (including for keys previously stored with manual dolEncrypt()).
		$savedApiKey = getDolGlobalString('QONTOSYNC_API_KEY');
		if (!empty($savedApiKey) && preg_match('/^(dolcrypt|dolobfuscation)[^:]*:/', $savedApiKey)) {
			dol_syslog(__METHOD__ . ": Unable to decrypt the API key (key is corrupted or instance_unique_id has been modified)", LOG_ERR);
			$savedApiKey = '';
		}

		$savedSandboxToken = getDolGlobalString('QONTOSYNC_SANDBOX_TOKEN');
		if (!empty($savedSandboxToken) && preg_match('/^(dolcrypt|dolobfuscation)[^:]*:/', $savedSandboxToken)) {
			dol_syslog(__METHOD__ . ": Unable to decrypt the Sandbox token (token is corrupted or instance_unique_id has been modified)", LOG_ERR);
			$savedSandboxToken = '';
		}

		$apiKeyVal = !empty($apiKey) ? $apiKey : $savedApiKey;
		$slugVal = !empty($slug) ? $slug : getDolGlobalString('QONTOSYNC_SLUG');
		$sandboxTokenVal = !empty($sandboxToken) ? $sandboxToken : $savedSandboxToken;

		$this->apiKey = trim($apiKeyVal);
		$this->slug = trim($slugVal);
    	$this->sandbox = ($sandbox !== null) ? (bool) $sandbox : getDolGlobalBool('QONTOSYNC_SANDBOX');
		$this->sandboxToken = trim($sandboxTokenVal);
		$this->baseUrl = $this->sandbox ? self::BASE_URL_SANDBOX : self::BASE_URL_PROD;

		// Check if slug or API key is missing
		if (empty($this->slug) || empty($this->apiKey)) {
			$this->error = "ErrorConfigParameterMissing";
			dol_syslog(__METHOD__ . ": Configuration parameter missing (slug or API key)", LOG_ERR);
		} else {
			dol_syslog(__METHOD__ . ": API initialized. Sandbox mode: " . ($this->sandbox ? 'YES' : 'NO'), LOG_DEBUG);
		}
	}

	/**
	 * Send an authenticated GET request to the Qonto API via getURLContent().
	 *
	 * @param string        $endpoint API endpoint path (e.g. "/v2/organization")
	 * @return string|false           Raw JSON response string, or false on error
	 */
	private function sendRequest(string $endpoint)
	{
		if (!function_exists('getURLContent')) require_once DOL_DOCUMENT_ROOT.'/core/lib/geturl.lib.php';

		$this->traceId = ''; // Init trace ID

		// 1. Guard clause: If a configuration error was already detected, skip execution
		if (!empty($this->error)) {
			dol_syslog(__METHOD__ . ": Request aborted due to previous error: " . $this->error, LOG_WARNING);
			return false;
		}

		// 2. Build the full URL
		$url = $this->baseUrl . $endpoint;
		dol_syslog(__METHOD__ . ": Sending GET request to " . $url, LOG_DEBUG);

		// 3. Build the HTTP headers dynamically using credentials and staging token if Sandbox is active
		$headers = [
			"Authorization: " . $this->slug . ":" . $this->apiKey,
			"Accept: application/json",
			"User-Agent: Dolibarr QontoSync"
		];
		if ($this->sandbox && !empty($this->sandboxToken)) {
			$headers[] = "X-Qonto-Staging-Token: " . $this->sandboxToken;
		}

		// 4. Capture the response headers (Trace-ID) through a header callback
		$responseHeaders = [];
		$curlOptions = [
			CURLOPT_HEADERFUNCTION => function ($ch, $headerLine) use (&$responseHeaders) {
				$len = strlen($headerLine);
				$parts = explode(':', $headerLine, 2);
				if (count($parts) === 2) {
					$responseHeaders[strtolower(trim($parts[0]))] = trim($parts[1]);
				}
				return $len;
			}
		];

		// 5. Execute the HTTP request through the Dolibarr core function
		//    GET, no redirection, HTTPS only, external URL only, SSL check forced, timeouts 10s/30s
		$result = getURLContent($url, 'GET', '', 0, $headers, ['https'], 0, 1, 10, 30, $curlOptions);

		// 6. Transport-level errors (DNS, timeout, SSRF/URL refused by the core...).
		//    getURLContent() sets curl_error_no != 0 in those cases.
		if (!empty($result['curl_error_no'])) {
			$this->error = !empty($result['curl_error_msg']) ? $result['curl_error_msg'] : 'cURL error ' . $result['curl_error_no'];
			dol_syslog(__METHOD__ . ": cURL error: " . $this->error, LOG_ERR);
			return false;
		}

		$httpcode = (int) $result['http_code'];
		$response = (string) $result['content'];

		// 7. Handle API-level response status codes
		if ($httpcode == 200) {
			if (!empty($responseHeaders['x-tyk-trace-id'])) {
				$this->traceId = $responseHeaders['x-tyk-trace-id'];
			}
			dol_syslog(__METHOD__ . ": Request successful (200). Trace-ID: " . $this->traceId, LOG_DEBUG);
			return $response;
		}

		// Try to extract the Trace-ID from the HTTP header
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
		dol_syslog(__METHOD__ . ": API Error HTTP " . $httpcode . " on URL " . $url, LOG_ERR);
		dol_syslog(__METHOD__ . ": Trace-ID: " . $this->traceId, LOG_ERR);
		dol_syslog(__METHOD__ . ": API error response (truncated): " . substr($response, 0, 200), LOG_ERR);

		return false;
	}

	/**
	 * Get transactions from Qonto API (auto-paginated).
	 *
	 * @param array        $parameters Query parameters (iban, settled_at_from, settled_at_to, status, sort_by, etc.)
	 * @return array|false             Array of transactions, or false on error
	 */
	public function getTransactions(array $parameters = [])
	{
		// Clean the IBAN by removing spaces if provided
		if (!empty($parameters['iban'])) {
			$parameters['iban'] = str_replace(' ', '', $parameters['iban']);
		}

		$base_endpoint = "/v2/transactions";
		$base_endpoint .= "?" . http_build_query($parameters);

		$all_transactions = [];
		$current_page = 1;

		do {
			$endpoint = $base_endpoint . "&page=" . $current_page;
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
				$current_page = (int) $data['meta']['next_page'];
			} else {
				$current_page = null;
			}
			
		} while ($current_page !== null);

		dol_syslog(__METHOD__ . ": fetched " . count($all_transactions) . " transactions.", LOG_DEBUG);

		return $all_transactions;
	}
	
	/**
	 * Get a single transaction from Qonto API by its ID.
	 *
	 * @param string       $transaction_id ID of the transaction
	 * @return array|false                 Transaction data array, or false on error
	 */
	public function getTransaction(string $transaction_id)
	{
		$endpoint = "/v2/transactions/" . urlencode(trim($transaction_id));
		
		$response = $this->sendRequest($endpoint);
		
		if ($response === false) {
			return false;
		}

		$data = json_decode($response, true);
		
		if (isset($data['transaction'])) {
			dol_syslog(__METHOD__ . ": retrieve transaction " . $transaction_id . " from Qonto.", LOG_DEBUG);
			return $data['transaction'];
		}

		return false;
	}

	/**
	 * Test the connection to the Qonto API via /v2/organization.
	 *
	 * @return bool True if connection succeeded, false otherwise
	 */
	public function testConnection(): bool
	{
		$response = $this->sendRequest("/v2/organization");
		dol_syslog(__METHOD__ . ": Testing the Qonto connection.", LOG_DEBUG);
		return $response !== false;
	}

	/**
	 * Get statements list from Qonto API filtered by IBAN and year (auto-paginated).
	 *
	 * @param string       $iban IBAN of the bank account
	 * @param int          $year Year
	 * @return array|false       Array of statements, or false on error
	 */
	public function getStatements(string $iban, int $year)
	{
		$all_statements = [];
		$current_page = 1;

		// Clean the IBAN by removing spaces
		$clean_iban = str_replace(' ', '', $iban);

		$period_from	= "01-" . (int) $year;
		$period_to		= "12-" . (int) $year;

		$base_endpoint = "/v2/statements?per_page=100&ibans[]=" . urlencode($clean_iban) . "&period_from=" . $period_from . "&period_to=" . $period_to;

		do {
			$endpoint = $base_endpoint . "&page=" . $current_page;
			$response = $this->sendRequest($endpoint);
			
			if ($response === false) {
				return false;
			}

			$data = json_decode($response, true);
			
			if (isset($data['statements']) && is_array($data['statements'])) {
				$all_statements = array_merge($all_statements, $data['statements']);
			}

			// Check if there is a next page according to Qonto API meta object
			if (isset($data['meta']) && !empty($data['meta']['next_page'])) {
				$current_page = (int) $data['meta']['next_page'];
			} else {
				$current_page = null;
			}
			
		} while ($current_page !== null);

		dol_syslog(__METHOD__ . ": fetched " . count($all_statements) . " statements for year " . $year, LOG_DEBUG);

		return $all_statements;
	}

	/**
	 * Retrieve a specific statement from Qonto API by its ID.
	 *
	 * @param string       $statement_id ID of the statement
	 * @return array|false               Statement data array, or false on error
	 */
	public function getStatementById(string $statement_id)
	{
		$endpoint = "/v2/statements/" . urlencode(trim($statement_id));
		$response = $this->sendRequest($endpoint);
		
		if ($response === false) {
			return false;
		}

		$data = json_decode($response, true);
		
		if (isset($data['statement'])) {
			return $data['statement'];
		}

		return false;
	}

	/**
	 * Get e-invoicing settings from Qonto API (not compatible with sandbox).
	 *
	 * @return array|false Settings array (sending_status, receiving_status), or false on error
	 */
	public function getEInvoicingSettings()
	{
		if ($this->sandbox) {
			dol_syslog(__METHOD__ . ": This endpoint is not compatible with sandbox." , LOG_WARNING);
			return false;
		}

		$endpoint = "/v2/einvoicing/settings";
		$response = $this->sendRequest($endpoint);

		if ($response === false) {
			return false;
		}

		$data = json_decode($response, true);

		if (!is_array($data)) {			
			return false;
		}

		dol_syslog(__METHOD__ . ": Retrieve e-invoicing settings from Qonto." , LOG_DEBUG);

		return $data;
	}

	/**
	 * Get supplier invoices list from Qonto API (auto-paginated).
	 *
	 * @param array        $parameters Query parameters (filter[status], per_page, etc.)
	 * @return array|false             Array of supplier invoices, or false on error
	 */
	public function getSupplierInvoices(array $parameters = [])
	{
		$base_endpoint = "/v2/supplier_invoices";
		$base_endpoint .= "?" . http_build_query($parameters);

		$invoices = [];
		$current_page = 1;

		do {
			$endpoint = $base_endpoint . "&page=" . $current_page;
			$response = $this->sendRequest($endpoint);
			
			if ($response === false) {
				return false;
			}

			$data = json_decode($response, true);
			
			if (isset($data['supplier_invoices']) && is_array($data['supplier_invoices'])) {
				$invoices = array_merge($invoices, $data['supplier_invoices']);
			}

			// Check if there is a next page according to Qonto API meta object
			if (isset($data['meta']) && !empty($data['meta']['next_page'])) {
				$current_page = (int) $data['meta']['next_page'];
			} else {
				$current_page = null;
			}
			
		} while ($current_page !== null);

		dol_syslog(__METHOD__ . ": Retrieve supplier invoices from Qonto." , LOG_DEBUG);

		return $invoices;
	}

	/**
	 * Retrieve an attachment and its temporary download URL.
	 *
	 * @param string       $attachment_id UUID of the attachment
	 * @return array|false                Attachment data array, or false on error
	 */
	public function getAttachment(string $attachment_id)
    {
        if (empty($attachment_id)) {
            return false;
        }

        $endpoint = "/v2/attachments/" . urlencode(trim($attachment_id));
		$response = $this->sendRequest($endpoint);

        if ($response === false) {
            return false;
        }

		$data = json_decode($response, true);

        if (isset($data['attachment']) && is_array($data['attachment'])) {
            $attachment =$data['attachment'];

			if (!empty($attachment['url'])) {
                $attachment['url'] = str_replace(array('\\u0026', '\u0026'), '&', $attachment['url']);
            }

			dol_syslog(__METHOD__ . ": Attachment URL retrieved for ID " . $attachment_id, LOG_DEBUG);
            return $attachment;
        }

        return false;
    }
}