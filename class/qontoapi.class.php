<?php

/**
 * Class to manage Qonto API calls with Mock support
 */
class QontoAPI
{
    private $db;
    private $login;
    private $key;
    private $mock;
    private $base_url = 'https://thirdparty.qonto.com/v2';

    /**
     * Constructor
     */
    public function __construct($db, $login = '', $key = '', $mock = false)
    {
        $this->db = $db;
        $this->login = $login;
        $this->key = $key;
        $this->mock = $mock;
    }

    /**
     * Fetch transactions
     *
     * @param string $iban      IBAN filter
     * @param string $date_from ISO date
     * @param string $date_to   ISO date
     * @return array            Array of transactions
     */
    public function fetchTransactions($iban, $date_from = null, $date_to = null)
    {
        if ($this->mock) {
            return $this->fetchMockTransactions($iban, $date_from, $date_to);
        }

        $params = array('iban' => $iban);
        if ($date_from) $params['settled_at_from'] = $date_from;
        if ($date_to) $params['settled_at_to'] = $date_to;

        $url = $this->base_url . '/transactions?' . http_build_query($params);

        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, array(
            'Authorization: ' . $this->login . ':' . $this->key,
            'Content-Type: application/json'
        ));

        $response = curl_exec($ch);
        $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($http_code >= 400) {
            return array('error' => $http_code, 'message' => $response);
        }

        $data = json_decode($response, true);
        return isset($data['transactions']) ? $data['transactions'] : array();
    }

    /**
     * Fetch transactions from Mock JSON file
     */
    private function fetchMockTransactions($iban, $date_from, $date_to)
    {
        $file = dirname(__DIR__) . '/assets/mock_transactions.json';
        if (!file_exists($file)) {
            return array();
        }

        $content = file_get_contents($file);
        $data = json_decode($content, true);

        if (!isset($data['transactions'])) {
            return array();
        }

        $filtered = array();
        foreach ($data['transactions'] as $txn) {
            // Basic filtering for simulation
            if (!empty($iban) && $txn['iban'] !== $iban) continue;

            $txn_date = strtotime($txn['settled_at']);
            if ($date_from && $txn_date < strtotime($date_from)) continue;
            if ($date_to && $txn_date > strtotime($date_to)) continue;

            $filtered[] = $txn;
        }

        return $filtered;
    }
}
