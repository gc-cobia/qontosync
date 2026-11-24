<?php
/*
 * QontoSync dolibarr module
 * @file       ajax/supplierinvoices.ctrl.php
 * @author     Gaëtan COURTOIS
 * @license    GPLv3
 */

if (!defined('DOL_DOCUMENT_ROOT')) {
    http_response_code(403);
    exit('Access Forbidden');
}

/**
 * Controller for Qonto supplier invoices AJAX actions.
 */
class SupplierInvoicesController
{
    /** @var DoliDB */
    private $db;
    /** @var array<string, mixed> */
    private $data;
    /** @var array{http_code: int, error: string}|null */
    private $eiError = null;

    /**
     * SupplierInvoicesController constructor.
     *
     * @param DoliDB               $db   Database handler
     * @param array<string, mixed> $data Request payload data
     */
    public function __construct(DoliDB $db, array $data = [])
    {
        global $langs;

        $this->db = $db;
        $this->data = $data;

        $langs->loadLangs(['qontosync@qontosync', 'einvoicing@einvoicing', 'main', 'bills', 'suppliers', 'companies', 'errors']);

        if (!getDolGlobalInt('QONTOSYNC_EI_ENABLED')) {
            $this->eiError = ['http_code' => 400, 'error' => 'EINVOICING_NOT_ENABLED'];
        }
    }

    /**
     * Generate a stateless HMAC-SHA256 signature binding a Qonto supplier invoice ID
     * to the current Dolibarr entity and user.
     *
     * @param  string $qontoId Qonto supplier invoice UUID
     * @return string          Hexadecimal HMAC signature, or empty string if context is invalid
     */
    private function signInvoice(string $qontoId): string
    {
        global $conf, $user;

        $instanceId = !empty($conf->file->instance_unique_id) ? (string) $conf->file->instance_unique_id : '';
        $qontoId = trim($qontoId);

        if ($instanceId === '' || $qontoId === '') {
            return '';
        }

        return hash_hmac(
            'sha256',
            ((int) $conf->entity) . '|' . ((int) $user->id) . '|' . $qontoId,
            'qontosync:supp_inv:' . $instanceId
        );
    }

    /**
     * Verify a supplier invoice HMAC signature in constant time.
     *
     * @param  string $qontoId Qonto supplier invoice UUID
     * @param  string $sig     Client-supplied HMAC signature
     * @return bool            True if valid, false otherwise
     */
    private function verifyInvoiceSignature(string $qontoId, string $sig): bool
    {
        $qontoId = trim($qontoId);
        if ($qontoId === '' || $sig === '') {
            return false;
        }

        $expected = $this->signInvoice($qontoId);
        return $expected !== '' && hash_equals($expected, $sig);
    }

    /**
     * Generate a stateless HMAC-SHA256 signature binding a Qonto attachment ID
     * to the current Dolibarr entity and user.
     *
     * @param  string $attachmentId Qonto attachment UUID
     * @return string               Hexadecimal HMAC signature, or empty string if context is invalid
     */
    private function signAttachment(string $attachmentId): string
    {
        global $conf, $user;

        $instanceId = !empty($conf->file->instance_unique_id) ? (string) $conf->file->instance_unique_id : '';
        $attachmentId = trim($attachmentId);

        if ($instanceId === '' || $attachmentId === '') {
            return '';
        }

        return hash_hmac(
            'sha256',
            ((int) $conf->entity) . '|' . ((int) $user->id) . '|' . $attachmentId,
            'qontosync:supp_att:' . $instanceId
        );
    }

    /**
     * Verify an attachment HMAC signature in constant time.
     *
     * @param  string $attachmentId Qonto attachment UUID
     * @param  string $sig          Client-supplied HMAC signature
     * @return bool                 True if valid, false otherwise
     */
    private function verifyAttachmentSignature(string $attachmentId, string $sig): bool
    {
        $attachmentId = trim($attachmentId);
        if ($attachmentId === '' || $sig === '') {
            return false;
        }

        $expected = $this->signAttachment($attachmentId);
        return $expected !== '' && hash_equals($expected, $sig);
    }

    /**
     * Fetch and categorize supplier invoices from Qonto API matched against Dolibarr.
     *
     * @return array<string, mixed> Associative array with http_code and formatted supplier invoices or error code
     */
    public function getSupplierInvoices(): array
    {
        global $user, $langs;

        if (!$user->hasRight('qontosync', 'supplier_invoices', 'read')) {
            return ['http_code' => 403, 'error' => 'PERMISSION_DENIED'];
        }

        if ($this->eiError !== null) {
            return $this->eiError;
        }

        if (!class_exists('QontoApi')) dol_include_once('/qontosync/class/qontoapi.class.php', 'QontoApi');
        $api = new QontoApi();

        $rawInvoices = $api->getSupplierInvoices();

        if ($rawInvoices === false) {
            return [
                'http_code' => 500,
                'error' => 'ERROR_FETCHING_SUPPLIER_INVOICES',
                'error_detail' => !empty($api->error) ? $langs->transnoentities($api->error) : '',
            ];
        }

        if (!class_exists('QontoMatching')) dol_include_once('/qontosync/class/qontomatching.class.php', 'QontoMatching');
        $qontoMatching = new QontoMatching($this->db);

        $invoices = $qontoMatching->matchSupplierInvoices($rawInvoices);

        $einvoicingModEnabled = isModEnabled('einvoicing');
        $canImport = (bool) $user->hasRight('qontosync', 'supplier_invoices', 'import');
        $canReadPdf = (bool) $user->hasRight('qontosync', 'supplier_invoices', 'read_pdf');

        $nbToProcess = 0;
        $formattedInvoices = [];

        foreach ($invoices as $inv) {
            $qontoId = trim((string) ($inv['id'] ?? ''));
            $rawDate = $inv['issue_date'] ?? ($inv['created_at'] ?? '');
            $dateOp = !empty($rawDate) ? strtotime((string) $rawDate) : false;
            $dateFormatted = $dateOp ? dol_print_date($dateOp, 'day') : '-';
            $ref = (string) ($inv['invoice_number'] ?? ($inv['reference'] ?? $qontoId));
            $supplier = (string) ($inv['supplier_name'] ?? ($inv['seller']['name'] ?? ($inv['counterparty']['name'] ?? '-')));
            $status = (string) ($inv['status'] ?? '');

            $amount = 0.0;
            $currency = 'EUR';
            if (isset($inv['total_amount']['value'])) {
                $amount = (float) $inv['total_amount']['value'];
                $currency = (string) ($inv['total_amount']['currency'] ?? 'EUR');
            } elseif (isset($inv['amount'])) {
                $amount = (float) $inv['amount'];
                $currency = (string) ($inv['currency'] ?? 'EUR');
            }

            $matchStatus = (string) ($inv['match_status'] ?? 'not_found');
            $facId = !empty($inv['dolibarr_rowid']) ? (int) $inv['dolibarr_rowid'] : 0;
            $attId = !empty($inv['attachment_id'])
                ? trim((string) $inv['attachment_id'])
                : (!empty($inv['display_attachment_id']) ? trim((string) $inv['display_attachment_id']) : '');

            if ($matchStatus !== 'linked' && $attId !== '' && $einvoicingModEnabled) {
                $nbToProcess++;
            }

            $invoiceSig = $this->signInvoice($qontoId);
            $canImportRow = ($canImport && $matchStatus !== 'linked' && $attId !== '' && $einvoicingModEnabled && $qontoId !== '' && $invoiceSig !== '');
            $attSig = ($canReadPdf && $attId !== '') ? $this->signAttachment($attId) : '';
            $canReadPdfRow = ($canReadPdf && $attId !== '' && $attSig !== '');

            $formattedInvoices[] = [
                'id' => $qontoId,
                'sig' => $invoiceSig,
                'date_formatted' => $dateFormatted,
                'ref' => $ref,
                'supplier' => $supplier,
                'amount' => $amount,
                'amount_formatted' => price($amount),
                'currency' => $currency,
                'status' => $status,
                'match_status' => $matchStatus,
                'dolibarr_rowid' => $facId,
                'dolibarr_url' => ($facId > 0) ? (DOL_URL_ROOT . '/fourn/facture/card.php?facid=' . $facId) : '',
                'can_import' => $canImportRow,
                'can_read_pdf' => $canReadPdfRow,
                'attachment_id' => $canReadPdfRow ? $attId : '',
                'attachment_sig' => $canReadPdfRow ? $attSig : '',
            ];
        }

        return [
            'http_code' => 200,
            'einvoicing_mod_enabled' => $einvoicingModEnabled,
            'can_import' => $canImport,
            'can_read_pdf' => $canReadPdf,
            'nb_to_process' => (int) $nbToProcess,
            'invoices' => $formattedInvoices,
        ];
    }

    /**
     * Import or link a single Qonto supplier invoice via QontoEInvoicing.
     *
     * @return array<string, mixed> Associative array with http_code and import result or error code
     */
    public function importInvoice(): array
    {
        global $user, $langs;

        if (!$user->hasRight('qontosync', 'supplier_invoices', 'import')) {
            return ['http_code' => 403, 'error' => 'PERMISSION_DENIED'];
        }

        if ($this->eiError !== null) {
            return $this->eiError;
        }

        $qontoId = trim((string) ($this->data['qonto_id'] ?? ''));
        $sig = is_string($this->data['sig'] ?? null) ? $this->data['sig'] : '';

        if ($qontoId === '') {
            return ['http_code' => 400, 'error' => 'INVALID_PARAMETERS'];
        }

        if (!$this->verifyInvoiceSignature($qontoId, $sig)) {
            dol_syslog(__METHOD__ . ': Invalid HMAC signature for Qonto supplier invoice ' . $qontoId . ' by user ' . $user->id, LOG_WARNING);
            return ['http_code' => 403, 'error' => 'PERMISSION_DENIED'];
        }

        if (!class_exists('QontoEInvoicing')) dol_include_once('/qontosync/class/qontoeinvoicing.class.php', 'QontoEInvoicing');
        $einvoicingService = new QontoEInvoicing($this->db);

        if (!$einvoicingService->isReady()) {
            return [
                'http_code' => 400,
                'error' => 'EINVOICING_NOT_READY',
                'error_message' => $langs->transnoentities($einvoicingService->error ?: 'QontoEInvoicingImportError'),
            ];
        }

        if (!class_exists('QontoApi')) dol_include_once('/qontosync/class/qontoapi.class.php', 'QontoApi');
        $api = new QontoApi();

        $rawInvoices = $api->getSupplierInvoices();

        if ($rawInvoices === false) {
            return [
                'http_code' => 500,
                'error' => 'ERROR_FETCHING_SUPPLIER_INVOICES',
                'error_detail' => !empty($api->error) ? $langs->transnoentities($api->error) : '',
            ];
        }

        $targetInvoice = null;
        foreach ($rawInvoices as $inv) {
            if (isset($inv['id']) && (string) $inv['id'] === $qontoId) {
                $targetInvoice = $inv;
                break;
            }
        }

        if ($targetInvoice === null) {
            return ['http_code' => 404, 'error' => 'INVOICE_NOT_FOUND'];
        }

        try {
            $resImport = $einvoicingService->importSupplierInvoice($targetInvoice, $api);
        } catch (\Throwable $e) {
            dol_syslog(__METHOD__ . ' exception: ' . $e->getMessage() . ' in ' . $e->getFile() . ':' . $e->getLine(), LOG_ERR);
            return [
                'http_code' => 500,
                'error' => 'IMPORT_ERROR',
                'error_message' => $langs->transnoentities('QontoEInvoicingImportError'),
            ];
        }

        if (!empty($resImport['res']) && (int) $resImport['res'] > 0) {
            $facId = (int) $resImport['res'];
            $created = !empty($resImport['created']);

            return [
                'http_code' => 200,
                'fac_id' => $facId,
                'created' => $created,
                'dolibarr_url' => DOL_URL_ROOT . '/fourn/facture/card.php?facid=' . $facId,
                'message' => $created
                    ? $langs->transnoentities('QontoEInvoicingInvoiceImported', $facId)
                    : $langs->transnoentities('QontoEInvoicingInvoiceLinked', $facId),
                'warnings' => array_values($einvoicingService->warnings),
            ];
        }

        $errMsg = $einvoicingService->error ?: ($resImport['message'] ?? 'QontoEInvoicingImportError');
        $response = [
            'http_code' => 400,
            'error' => 'IMPORT_ERROR',
            'error_message' => $langs->transnoentities($errMsg),
        ];

        if (!empty($resImport['action'])) {
            $response['pending_action_html'] = $resImport['action'];
        }

        return $response;
    }

    /**
     * Batch synchronize all unlinked Qonto supplier invoices into Dolibarr.
     *
     * @return array<string, mixed> Associative array with http_code and batch import counts
     */
    public function importAll(): array
    {
        global $user, $langs;

        if (!$user->hasRight('qontosync', 'supplier_invoices', 'import')) {
            return ['http_code' => 403, 'error' => 'PERMISSION_DENIED'];
        }

        if ($this->eiError !== null) {
            return $this->eiError;
        }

        if (!class_exists('QontoEInvoicing')) dol_include_once('/qontosync/class/qontoeinvoicing.class.php', 'QontoEInvoicing');
        $einvoicingService = new QontoEInvoicing($this->db);

        if (!$einvoicingService->isReady()) {
            return [
                'http_code' => 400,
                'error' => 'EINVOICING_NOT_READY',
                'error_message' => $langs->transnoentities($einvoicingService->error ?: 'QontoEInvoicingImportError'),
            ];
        }

        if (!class_exists('QontoApi')) dol_include_once('/qontosync/class/qontoapi.class.php', 'QontoApi');
        $api = new QontoApi();

        try {
            $nbSuccess = $einvoicingService->autoSyncSupplierInvoices([], $api);
        } catch (\Throwable $e) {
            dol_syslog(__METHOD__ . ' exception: ' . $e->getMessage() . ' in ' . $e->getFile() . ':' . $e->getLine(), LOG_ERR);
            return [
                'http_code' => 500,
                'error' => 'IMPORT_ERROR',
                'error_message' => $langs->transnoentities('QontoEInvoicingImportError'),
            ];
        }

        if ($nbSuccess < 0) {
            return [
                'http_code' => 500,
                'error' => 'IMPORT_ERROR',
                'error_message' => $langs->transnoentities($einvoicingService->error ?: 'QontoEInvoicingImportError'),
            ];
        }

        $nbErrors = count($einvoicingService->errors);

        return [
            'http_code' => 200,
            'success_count' => (int) $nbSuccess,
            'error_count' => (int) $nbErrors,
            'warnings' => array_values($einvoicingService->warnings),
            'errors' => array_values($einvoicingService->errors),
            'message' => $langs->transnoentities('QontoEInvoicingBatchCompleted', $nbSuccess, $nbErrors),
        ];
    }

    /**
     * Resolve a temporary pre-signed HTTPS attachment URL for a verified attachment ID.
     *
     * @return array<string, mixed> Associative array with http_code and url or error code
     */
    public function getAttachmentUrl(): array
    {
        global $user;

        if (!$user->hasRight('qontosync', 'supplier_invoices', 'read_pdf')) {
            return ['http_code' => 403, 'error' => 'PERMISSION_DENIED'];
        }

        if ($this->eiError !== null) {
            return $this->eiError;
        }

        $attachmentId = trim((string) ($this->data['attachment_id'] ?? ''));
        $sig = is_string($this->data['sig'] ?? null) ? $this->data['sig'] : '';

        if ($attachmentId === '') {
            return ['http_code' => 400, 'error' => 'INVALID_PARAMETERS'];
        }

        if (!$this->verifyAttachmentSignature($attachmentId, $sig)) {
            dol_syslog(__METHOD__ . ': Invalid attachment signature for ID ' . $attachmentId . ' by user ' . $user->id, LOG_WARNING);
            return ['http_code' => 403, 'error' => 'PERMISSION_DENIED'];
        }

        if (!class_exists('QontoApi')) dol_include_once('/qontosync/class/qontoapi.class.php', 'QontoApi');
        $api = new QontoApi();

        $attachment = $api->getAttachment($attachmentId);

        if (!empty($attachment['url'])) {
            $parsedUrl = parse_url((string) $attachment['url']);
            if (is_array($parsedUrl) && !empty($parsedUrl['scheme']) && strtolower($parsedUrl['scheme']) === 'https' && !empty($parsedUrl['host'])) {
                return [
                    'http_code' => 200,
                    'url' => (string) $attachment['url'],
                ];
            }
            dol_syslog(__METHOD__ . ': Refused non-HTTPS attachment URL for ID ' . $attachmentId, LOG_ERR);
        }

        return ['http_code' => 404, 'error' => 'ERROR_FILE_NOT_FOUND'];
    }
}
