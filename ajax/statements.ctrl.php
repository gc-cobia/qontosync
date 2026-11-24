<?php
/*
 * QontoSync dolibarr module 
 * @file       ajax/statements.ctrl.php
 * @author     Gaëtan COURTOIS
 * @license    GPLv3
 */

if (!defined('DOL_DOCUMENT_ROOT')) {
    http_response_code(403);
    exit('Access Forbidden');
}

if (!trait_exists('QontoAccountTrait')) dol_include_once('/qontosync/class/qontoaccount.trait.php');

/**
 * Controller for bank statements AJAX actions.
 */
class StatementsController
{
    use QontoAccountTrait;

    /** @var DoliDB */
    private $db;
    /** @var array */
    private $data;
    /** @var array|null */
    private $accountError = null;

    /**
     * StatementsController constructor.
     * 
     * @param DoliDB $db Database handler
     */
    public function __construct(DoliDB $db, array $data = [])
    {
        $this->db = $db;
        $this->data = $data;

        $bank_rowid = (int) ($this->data['bank_rowid'] ?? 0);

        if ($bank_rowid <= 0) {
            $this->accountError = ['http_code' => 400, 'error' => 'NO_BANK_SELECTED'];
        } elseif (!$this->initAccount($bank_rowid)) {
            $this->accountError = ['http_code' => 400, 'error' => 'BANK_ACCOUNT_NOT_FOUND'];
        } elseif (!$this->isAccountAllowed()) {
            $this->accountError = ['http_code' => 403, 'error' => 'PERMISSION_DENIED'];
        }
    }

    /**
     * Get monthly bank statements comparison for a specific year and bank account
     * 
     * @param array $data Array containing year and bank_rowid
     * @return array Array containing http_code and statements or error code
     */
    public function getStatements(): array
    {
        global $user;

        if (!$user->hasRight('qontosync', 'statements', 'read')) {
            return ['http_code' => 403, 'error' => 'PERMISSION_DENIED'];
        }

        if ($this->accountError !== null) {
            return $this->accountError;
        }

        $year = (int) ($this->data['year'] ?? 0);

        if ($year <= 0 || $year > (int) date('Y')) {
            return ['http_code' => 400, 'error' => 'INVALID_PARAMETERS'];
        }

        if (!class_exists('QontoApi')) dol_include_once('/qontosync/class/qontoapi.class.php', 'QontoApi');
        $api = new QontoApi();

        $dateStart = sprintf("%04d-01-01", $year);
        $dateEnd   = sprintf("%04d-12-31", $year);

        $transactions = $api->getTransactions([
            'iban' => $this->account->iban,
            'settled_at_from' => $dateStart . "T00:00:00.000Z",
            'settled_at_to' => $dateEnd . "T23:59:59.999Z"
        ]);
        $qonto_statements_raw = $api->getStatements($this->account->iban, $year);

        if ($transactions === false) {
            return ['http_code' => 500, 'error' => 'ERROR_FETCHING_TRANSACTIONS'];
        }

        if (empty($transactions)) {
            return ['http_code' => 404, 'error' => 'NO_TRANSACTIONS_FOUND'];
        }

        if (!class_exists('QontoMatching')) dol_include_once('/qontosync/class/qontomatching.class.php', 'QontoMatching');
        $qontoMatching = new QontoMatching($this->db, $this->account);

        $months_data = $qontoMatching->matchStatements($year, $transactions, $qonto_statements_raw);

        $formatted_months = [];

        foreach ($months_data as $m => $m_data) {
            $is_future = ($year > (int) date('Y') || ($year == (int) date('Y') && $m > (int) date('n')));

            $diff_open = price2num($m_data['qonto_opening'] - $m_data['dolibarr_opening'], 'MT');
            $diff_close = price2num($m_data['qonto_closing'] - $m_data['dolibarr_closing'], 'MT');

            $formatted_months[] = [
                'month' => (int) $m,
                'label' => ucfirst($m_data['label']),
                'is_future' => (bool) $is_future,
                'dolibarr_opening' => price($m_data['dolibarr_opening']),
                'qonto_opening' => price($m_data['qonto_opening']),
                'diff_open' => (float) $diff_open,
                'diff_open_formatted' => price($diff_open),
                'dolibarr_closing' => price($m_data['dolibarr_closing']),
                'qonto_closing' => price($m_data['qonto_closing']),
                'diff_close' => (float) $diff_close,
                'diff_close_formatted' => price($diff_close),
                'in_dolibarr' => (bool) $m_data['in_dolibarr'],
                'in_qonto' => (bool) $m_data['in_qonto'],
                'dolibarr_url' => !empty($m_data['dolibarr_url']) ? $m_data['dolibarr_url'] : ''
            ];
        }

        return [
            'http_code' => 200,
            'bank_rowid' => (int) $this->account->id,
            'year' => (int) $year,
            'statements' => (array) $formatted_months
        ];
    }

    /**
     * Synchronize missing Qonto PDF statements into Dolibarr GED
     * 
     * @param array $data Array containing year and bank_rowid
     * @return array Associative array with http_code and sync result counts
     */
    public function updateStatements(): array
    {
        global $user;

        if (!$user->hasRight('qontosync', 'statements', 'import')) {
            return ['http_code' => 403, 'error' => 'PERMISSION_DENIED'];
        }

        if ($this->accountError !== null) {
            return $this->accountError;
        }

        $year = (int) ($this->data['year'] ?? 0);

        if ($year <= 0 || $year > (int) date('Y')) {
            return ['http_code' => 400, 'error' => 'INVALID_PARAMETERS'];
        }

        if (!class_exists('QontoApi')) dol_include_once('/qontosync/class/qontoapi.class.php', 'QontoApi');
        $api = new QontoApi();

        if (!class_exists('QontoGed')) dol_include_once('/qontosync/class/qontoged.class.php', 'QontoGed');
        $qontoGed = new QontoGed($this->db, $this->account);

        $sync_result = $qontoGed->syncStatementsGED($year, $api);

        if (!empty($sync_result['api_error'])) {
            return ['http_code' => 500, 'error' => 'ERROR_FETCHING_STATEMENTS'];
        }

        return [
            'http_code' => 200,
            'bank_rowid' => (int) $this->account->id,
            'year' => (int) $year,
            'success_count' => (int) $sync_result['success_count'],
            'error_count' => (int) $sync_result['error_count'],
            'already_exists' => (int) $sync_result['already_exists']
        ];
    }
}
