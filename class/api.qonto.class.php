<?php

/**
 * Class to manage Qonto API calls
 */
class QontoAPI
{
    /** @var DoliDB Database connector */
    private $db;
    /** @var string Qonto Organization Login/Slug */
    private $login;
    /** @var string Qonto Secret Key */
    private $key;
    /** @var string Base URL for Qonto API */
    private $base_url = 'https://thirdparty.qonto.com/v2';

    /**
     * Constructor
     * 
     * @param DoliDB $db    Database connector
     * @param string $login Qonto Login
     * @param string $key   Qonto Key
     */
    public function __construct($db, $login, $key)
    {
        $this->db = $db;
        $this->login = $login;
        $this->key = $key;
    }

    /**
     * Test the connection to Qonto API
     * 
     * @return int 1 if OK, <0 if error
     */
    public function testConnection()
    {
        $result = $this->call('/organization');
        if (is_array($result) && !empty($result['organization'])) {
            return 1;
        }
        return -1;
    }

    /**
     * Fetch transactions for a specific bank account
     * 
     * @param string $bank_account_id Qonto internal ID for the bank account
     * @param string $iban            IBAN for the bank account
     * @param string $date_from       Filter transactions from this date (ISO 8601)
     * @param string $date_to         Filter transactions to this date (ISO 8601)
     * @return array|int              Array of transactions or <0 if error
     */
    public function fetchTransactions($bank_account_id = '', $iban = '', $date_from = null, $date_to = null)
    {
        $params = array();
        if ($bank_account_id) $params['bank_account_id'] = $bank_account_id;
        if ($iban) $params['iban'] = $iban;

        if ($date_from) {
            $params['settled_at_from'] = $date_from;
        }
        if ($date_to) {
            $params['settled_at_to'] = $date_to;
        }

        return $this->call('/transactions', $params);
    }

    /**
     * Generic call to Qonto API
     * 
     * @param string $endpoint API endpoint (e.g. /transactions)
     * @param array  $params   Query parameters
     * @return array|int       Decoded JSON response or <0 if error
     */
    private function call($endpoint, $params = array())
    {
        $url = $this->base_url . $endpoint;
        if (!empty($params)) {
            $url .= '?' . http_build_query($params);
        }

        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, array(
            'Authorization: ' . $this->login . ':' . $this->key,
            'Content-Type: application/json'
        ));

        $response = curl_exec($ch);
        $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        
        if (curl_errno($ch)) {
            dol_syslog("QontoAPI::call cURL Error: " . curl_error($ch), LOG_ERR);
            return -1;
        }

        curl_close($ch);

        if ($http_code >= 400) {
            dol_syslog("QontoAPI::call API Error (HTTP $http_code): " . $response, LOG_ERR);
            return -$http_code;
        }

        return json_decode($response, true);
    }
}
