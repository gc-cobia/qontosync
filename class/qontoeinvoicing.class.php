<?php
/*
 * QontoSync dolibarr module
 * @file       class/qontoeinvoicing.class.php
 * @author     Gaëtan COURTOIS
 * @license    GPLv3
 */

if (!defined('DOL_DOCUMENT_ROOT')) {
    http_response_code(403);
    exit('Access Forbidden');
}

/**
 * Class QontoEInvoicing
 *
 * Orchestrates the import of Qonto supplier e-invoices (Factur-X PDF / CII XML)
 * by delegating parsing, thirdparty sync/creation, and supplier invoice creation
 * to the Dolibarr community module `einvoicing`.
 */
class QontoEInvoicing
{
    /** @var DoliDB */
    private $db;

    /** @var string */
    public $error = '';

    /** @var string[] */
    public $errors = [];

    /** @var string[] */
    public $warnings = [];

    /**
     * Constructor
     *
     * @param DoliDB $db Database handler
     */
    public function __construct($db)
    {
        $this->db = $db;
    }

    /**
     * Check if all required modules and settings are enabled for supplier e-invoice import
     *
     * @return bool True if ready, false otherwise (sets $this->error)
     */
    public function isReady(): bool
    {
        $this->error = '';

        if (!getDolGlobalInt('QONTOSYNC_EI_ENABLED')) {
            $this->error = 'QontoEInvoicingNotEnabled';
            dol_syslog(__METHOD__ . ': QontoSync e-invoicing is not enabled', LOG_WARNING);
            return false;
        }

        if (!isModEnabled('fournisseur')) {
            $this->error = 'ErrorModuleFournisseurNotEnabled';
            dol_syslog(__METHOD__ . ': Supplier module is not enabled', LOG_ERR);
            return false;
        }

        if (!isModEnabled('einvoicing')) {
            $this->error = 'QontoEInvoicingModuleRequired';
            dol_syslog(__METHOD__ . ': EInvoicing community module is not enabled', LOG_ERR);
            return false;
        }

        return true;
    }

    /**
     * Load required classes from the einvoicing module
     *
     * @return bool True if all classes were loaded, false otherwise
     */
    private function loadEInvoicingClasses(): bool
    {
        dol_include_once('/einvoicing/lib/einvoicing.lib.php');
        dol_include_once('/einvoicing/class/einvoicing.class.php');
        dol_include_once('/einvoicing/class/protocols/AbstractProtocol.class.php');
        dol_include_once('/einvoicing/class/protocols/ProtocolManager.class.php');
        dol_include_once('/einvoicing/class/providers/AbstractPDPProvider.class.php');
        dol_include_once('/einvoicing/class/utils/SupplierInvoiceHelper.class.php');

        if (!class_exists('ProtocolManager') || !class_exists('EInvoicing')) {
            $this->error = 'QontoEInvoicingModuleRequired';
            dol_syslog(__METHOD__ . ': Unable to load ProtocolManager or EInvoicing classes from einvoicing module', LOG_ERR);
            return false;
        }

        return true;
    }

    /**
     * Import a single supplier invoice from Qonto API payload using the einvoicing module
     *
     * @param array         $qontoInvoice Raw supplier invoice array from Qonto API
     * @param QontoApi|null $api          Optional QontoApi instance
     * @return array{res:int,created?:int,message?:string,businessmessage?:string,action?:string}
     */
    public function importSupplierInvoice(array $qontoInvoice, ?QontoApi $api = null): array
    {
        $this->error = '';
        $this->errors = [];
        $this->warnings = [];

        if (!$this->isReady()) {
            return ['res' => -1, 'message' => $this->error];
        }

        $qontoUuid = trim((string) ($qontoInvoice['id'] ?? ''));
        $attachmentId = !empty($qontoInvoice['attachment_id'])
            ? trim((string) $qontoInvoice['attachment_id'])
            : (!empty($qontoInvoice['display_attachment_id']) ? trim((string) $qontoInvoice['display_attachment_id']) : '');
        $displayAttachmentId = !empty($qontoInvoice['display_attachment_id'])
            ? trim((string) $qontoInvoice['display_attachment_id'])
            : '';

        if ($attachmentId === '') {
            $this->error = 'QontoEInvoicingNoAttachment';
            dol_syslog(__METHOD__ . ': No attachment_id found for Qonto supplier invoice ' . $qontoUuid, LOG_ERR);
            return ['res' => -1, 'message' => $this->error];
        }

        if ($api === null) {
            if (!class_exists('QontoApi')) {
                dol_include_once('/qontosync/class/qontoapi.class.php', 'QontoApi');
            }
            $api = new QontoApi();
        }

        if (!class_exists('QontoGed')) {
            dol_include_once('/qontosync/class/qontoged.class.php', 'QontoGed');
        }
        $qontoGed = new QontoGed($this->db);

        // 1. Download main e-invoice file (Factur-X PDF or CII/UBL XML)
        $fileContent = $qontoGed->downloadAttachment($attachmentId, $api);
        if ($fileContent === false || $fileContent === '') {
            $this->error = !empty($qontoGed->error) ? $qontoGed->error : 'ErrorFileNotFound';
            dol_syslog(__METHOD__ . ': Failed to download attachment ' . $attachmentId . ' for invoice ' . $qontoUuid, LOG_ERR);
            return ['res' => -1, 'message' => $this->error];
        }

        $mainIsPdf = (strncmp(ltrim($fileContent), '%PDF-', 5) === 0);

        // Case A: Primary attachment is a PDF (typically Factur-X)
        // Try importing it directly first to avoid downloading display_attachment_id unnecessarily
        if ($mainIsPdf) {
            $res = $this->importFromContent($fileContent, $qontoUuid, null);
            if (!empty($res['res']) && (int) $res['res'] > 0) {
                return $res;
            }

            // Lazy fallback: only download display_attachment_id if primary PDF was not a valid Factur-X
            if ($displayAttachmentId !== '' && $displayAttachmentId !== $attachmentId) {
                $primaryError = $this->error;
                $primaryErrors = $this->errors;
                $primaryWarnings = $this->warnings;

                $downloadedSecond = $qontoGed->downloadAttachment($displayAttachmentId, $api);
                if ($downloadedSecond !== false && $downloadedSecond !== '') {
                    $secondIsPdf = (strncmp(ltrim($downloadedSecond), '%PDF-', 5) === 0);
                    if (!$secondIsPdf) {
                        // attachment_id was the plain PDF and display_attachment_id is the XML
                        return $this->importFromContent($downloadedSecond, $qontoUuid, $fileContent);
                    }

                    // Both are PDFs: try the second PDF in case it is the Factur-X container
                    $fallbackRes = $this->importFromContent($downloadedSecond, $qontoUuid, null);
                    if (!empty($fallbackRes['res']) && (int) $fallbackRes['res'] > 0) {
                        return $fallbackRes;
                    }
                }

                // Restore original error from the primary attachment
                $this->error = $primaryError;
                $this->errors = $primaryErrors;
                $this->warnings = $primaryWarnings;
            }

            return $res;
        }

        // Case B: Primary attachment is XML (CII or UBL)
        // Skip downloading display_attachment_id if the XML already embeds its own readable PDF in base64 (BT-125)
        $hasEmbeddedPdf = (
            stripos($fileContent, 'EmbeddedDocumentBinaryObject') !== false
            || stripos($fileContent, 'AttachmentBinaryObject') !== false
        ) && stripos($fileContent, 'application/pdf') !== false;

        $readableViewContent = null;
        if (!$hasEmbeddedPdf && $displayAttachmentId !== '' && $displayAttachmentId !== $attachmentId) {
            $downloadedReadable = $qontoGed->downloadAttachment($displayAttachmentId, $api);
            if ($downloadedReadable !== false && $downloadedReadable !== '' && strncmp(ltrim($downloadedReadable), '%PDF-', 5) === 0) {
                $readableViewContent = $downloadedReadable;
            }
        }

        return $this->importFromContent($fileContent, $qontoUuid, $readableViewContent);
    }

    /**
     * Bridge QontoSync supplier e-invoicing parameters to einvoicing global constants in memory ($conf->global).
     * This ensures QontoSync supplier settings apply during import even when einvoicing is in TESTPDP ("None") mode
     * without writing EINVOICING_* constants to the database.
     *
     * @return void
     */
    protected function applySupplierConfigOverrides(): void
    {
        global $conf;

        $autoThirdparty = (int) getDolGlobalInt('QONTOSYNC_EI_SUPP_AUTO_THIRDPARTY');
        $completeThirdparty = (int) getDolGlobalInt('QONTOSYNC_EI_SUPP_COMPLETE_THIRDPARTY');
        $autoProduct = (int) getDolGlobalInt('QONTOSYNC_EI_SUPP_AUTO_PRODUCT');
        $freeLines = (!$autoProduct && !getDolGlobalInt('MAIN_DISABLE_FREE_LINES'))
            ? (int) getDolGlobalInt('QONTOSYNC_EI_SUPP_FREE_LINES')
            : 0;
        $catchallProduct = !$autoProduct
            ? (int) getDolGlobalInt('QONTOSYNC_EI_SUPP_CATCHALL_PRODUCT')
            : 0;
        $catchallProductId = (int) getDolGlobalInt('QONTOSYNC_EI_SUPP_CATCHALL_PRODUCT_ID');

        $conf->global->EINVOICING_PDP = 'Qonto';
        $conf->global->EINVOICING_THIRDPARTIES_AUTO_GENERATION = (string) $autoThirdparty;
        $conf->global->EINVOICING_THIRDPARTIES_COMPLETE_INFO = (string) $completeThirdparty;
        $conf->global->EINVOICING_PRODUCTS_AUTO_GENERATION = (string) $autoProduct;
        $conf->global->EINVOICING_IMPORT_AS_FREE_LINES = (string) $freeLines;
        $conf->global->EINVOICING_DEFAULT_PRODUCT_CATCHALL = (string) $catchallProduct;
        $conf->global->EINVOICING_DEFAULT_PRODUCT_CATCHALL_ID = $catchallProductId > 0 ? (string) $catchallProductId : '';
    }

    /**
     * Import a supplier invoice directly from raw file content (Factur-X PDF or CII XML) via einvoicing
     *
     * @param string      $fileContent         Raw binary PDF (Factur-X) or XML (CII) content
     * @param string      $qontoUuid           Qonto supplier invoice UUID (stored in llx_facture_fourn.ref_ext)
     * @param string|null $readableViewContent Optional readable PDF content when $fileContent is pure XML
     * @return array{res:int,created?:int,message?:string,businessmessage?:string,action?:string}
     */
    public function importFromContent(string $fileContent, string $qontoUuid = '', ?string $readableViewContent = null): array
    {
        global $conf, $user, $langs;

        $this->error = '';
        $this->errors = [];
        $this->warnings = [];

        if (!$this->isReady() || !$this->loadEInvoicingClasses()) {
            return ['res' => -1, 'message' => $this->error];
        }

        // Apply QontoSync supplier configuration overrides to $conf->global in memory
        $this->applySupplierConfigOverrides();

        $langs->loadLangs(['qontosync@qontosync', 'einvoicing@einvoicing', 'bills', 'suppliers', 'companies']);

        // Ensure einvoicing temp directory is configured
        if (empty($conf->einvoicing)) {
            $conf->einvoicing = new stdClass();
        }
        if (empty($conf->einvoicing->dir_temp)) {
            $conf->einvoicing->dir_temp = DOL_DATA_ROOT . '/einvoicing/temp';
        }

        // Set import_key timestamp expected by einvoicing protocols
        if (class_exists('AbstractPDPProvider')) {
            AbstractPDPProvider::$EINVOICING_LAST_IMPORT_KEY = dol_print_date(dol_now(), '%Y%m%d%H%M%S');
        }

        try {
            if (strncmp($fileContent, "\xEF\xBB\xBF", 3) === 0) {
                $fileContent = substr($fileContent, 3);
            }

            // Detect protocol (FACTURX, CII or UBL) from raw content
            $protocol = null;
            $protocolInfo = ProtocolManager::getProtocolFromContent($fileContent);
            if (!empty($protocolInfo['success']) && is_object($protocolInfo['protocol_object'])) {
                /** @var CIIProtocol|FacturXProtocol $protocol */
                $protocol = $protocolInfo['protocol_object'];
            } else {
                $detected = $protocolInfo['detected_protocol_name'] ?? '';
                if (
                    $detected === 'UBL'
                    || strpos($fileContent, 'urn:oasis:names:specification:ubl:schema:xsd:Invoice-2') !== false
                    || strpos($fileContent, 'urn:oasis:names:specification:ubl:schema:xsd:CreditNote-2') !== false
                ) {
                    if (!class_exists('QontoUBLProtocol')) {
                        dol_include_once('/qontosync/class/qontoublprotocol.class.php', 'QontoUBLProtocol');
                    }
                    if (class_exists('QontoUBLProtocol')) {
                        $protocol = new QontoUBLProtocol($this->db);
                    }
                }
            }

            if (!is_object($protocol)) {
                $detected = $protocolInfo['detected_protocol_name'] ?? '';
                if (($protocolInfo['error_code'] ?? 0) === ProtocolManager::EXCEPTION_UNSUPPORTED_FORMAT && $detected !== '') {
                    $this->error = $langs->trans('QontoEInvoicingUnsupportedProtocol', $detected);
                } else {
                    $this->error = $langs->trans('QontoEInvoicingUnknownFormat');
                }
                dol_syslog(__METHOD__ . ': Protocol detection failed for Qonto UUID ' . $qontoUuid . ' (code=' . ($protocolInfo['error_code'] ?? 0) . ', detected=' . $detected . ')', LOG_ERR);
                return ['res' => -1, 'message' => $this->error];
            }

            // Delegate thirdparty sync/creation, supplier invoice creation, line mapping, and GED attachments to einvoicing
            $flowId = $qontoUuid !== '' ? $qontoUuid : ('qonto-' . uniqid());
            $res = $protocol->createSupplierInvoiceFromSource($fileContent, $readableViewContent, $flowId);
        } catch (\Throwable $e) {
            $rawMsg = $e->getMessage();
            if (stripos($rawMsg, 'No invoice attachment found in the PDF') !== false) {
                $this->error = $langs->trans('QontoEInvoicingPdfNotFacturX');
            } else {
                $this->error = $rawMsg . ' (' . basename($e->getFile()) . ':' . $e->getLine() . ')';
            }
            $this->errors[] = $this->error;
            dol_syslog(__METHOD__ . ': Exception during einvoicing import for Qonto UUID ' . $qontoUuid . ': ' . $rawMsg . ' in ' . $e->getFile() . ':' . $e->getLine(), LOG_ERR);
            return ['res' => -1, 'message' => $this->error];
        }

        if (!is_array($res) || !isset($res['res']) || (int) $res['res'] <= 0) {
            // Rewrite backtopage URL in suggested actions to return to QontoSync supplier_invoices.php
            if (!empty($res['action']) && is_string($res['action'])) {
                $einvoicingListUrl = urlencode(dol_buildpath('/einvoicing/document_list.php', 1));
                $qontoListUrl = urlencode(dol_buildpath('/qontosync/supplier_invoices.php', 1));
                $res['action'] = str_replace($einvoicingListUrl, $qontoListUrl, $res['action']);
            }

            $errorMsg = !empty($res['businessmessage'])
                ? $res['businessmessage']
                : (!empty($res['message']) ? $res['message'] : $langs->trans('QontoEInvoicingImportError'));

            $this->error = $errorMsg;
            $this->errors[] = $errorMsg;
            dol_syslog(__METHOD__ . ': einvoicing import failed for Qonto UUID ' . $qontoUuid . ': ' . ($res['message'] ?? $errorMsg), LOG_ERR);

            return is_array($res) ? $res : ['res' => -1, 'message' => $errorMsg];
        }

        $facId = (int) $res['res'];
        $created = (int) ($res['created'] ?? 1);

        // Fetch the native Dolibarr supplier invoice object to update note_private, link ref_ext, and optionally auto-validate
        if (!class_exists('FactureFournisseur')) {
            require_once DOL_DOCUMENT_ROOT . '/fourn/class/fournisseur.facture.class.php';
        }
        $supplierInvoice = new FactureFournisseur($this->db);
        if ($supplierInvoice->fetch($facId) > 0) {
            // Replace the hardcoded "Imported from PDP" private note set by einvoicing with our translated note
            if ($created === 1 || $supplierInvoice->note_private === 'Imported from PDP') {
                $privateNote = $langs->trans('QontoEInvoicingImportedPrivateNote');
                if ($supplierInvoice->note_private !== '' && $supplierInvoice->note_private !== 'Imported from PDP') {
                    $privateNote = $supplierInvoice->note_private . "\n" . $privateNote;
                }
                $supplierInvoice->update_note($privateNote, '_private');
            }

            // Link the Dolibarr supplier invoice to the Qonto UUID via update_ref_ext()
            if ($qontoUuid !== '') {
                if ($supplierInvoice->update_ref_ext($qontoUuid) <= 0) {
                    $this->warnings[] = $langs->trans('QontoEInvoicingWarningLinkFailed', $facId, $qontoUuid);
                    dol_syslog(__METHOD__ . ': update_ref_ext failed for supplier invoice #' . $facId . ': ' . $supplierInvoice->error, LOG_WARNING);
                }
            }

            // Optional automatic validation of the newly imported supplier invoice
            if (getDolGlobalInt('QONTOSYNC_EI_SUPP_AUTO_VALIDATE') && (int) $supplierInvoice->statut === FactureFournisseur::STATUS_DRAFT) {
                if (class_exists('SupplierInvoiceHelper') && SupplierInvoiceHelper::totalsMismatchBlocks($facId)) {
                    $this->warnings[] = $langs->trans('EInvoiceTotalsMismatchCannotAutoValidate', $supplierInvoice->ref);
                } else {
                    $valRes = $supplierInvoice->validate($user);
                    if ($valRes < 0) {
                        $this->warnings[] = $langs->trans('QontoEInvoicingWarningAutoValidateFailed', $supplierInvoice->ref, $supplierInvoice->error);
                        dol_syslog(__METHOD__ . ': Auto-validation failed for supplier invoice #' . $facId . ': ' . $supplierInvoice->error, LOG_WARNING);
                    }
                }
            }
        } elseif ($qontoUuid !== '') {
            $this->warnings[] = $langs->trans('QontoEInvoicingWarningLinkFailed', $facId, $qontoUuid);
        }

        return [
            'res'     => $facId,
            'created' => $created,
            'message' => $res['message'] ?? '',
        ];
    }

    /**
     * Synchronize all unlinked supplier invoices from Qonto via the einvoicing module
     *
     * @param array         $qonto_invoices Optional list of Qonto supplier invoices already fetched
     * @param QontoApi|null $api            Optional QontoApi instance
     * @return int                          Number of processed invoices if OK (>= 0), -1 on fatal error
     */
    public function autoSyncSupplierInvoices(array $qonto_invoices = [], ?QontoApi $api = null): int
    {
        if (!$this->isReady()) {
            return -1;
        }

        if ($api === null) {
            if (!class_exists('QontoApi')) {
                dol_include_once('/qontosync/class/qontoapi.class.php', 'QontoApi');
            }
            $api = new QontoApi();
        }

        $rawInvoices = !empty($qonto_invoices) ? $qonto_invoices : $api->getSupplierInvoices();
        if (!is_array($rawInvoices)) {
            $this->error = $api->error;
            dol_syslog(__METHOD__ . ': Failed to fetch supplier invoices from Qonto', LOG_ERR);
            return -1;
        }

        if (!class_exists('QontoMatching')) {
            dol_include_once('/qontosync/class/qontomatching.class.php', 'QontoMatching');
        }
        $matching = new QontoMatching($this->db);
        
        $invoices = $matching->matchSupplierInvoices($rawInvoices);

        $processed = 0;
        $batchErrors = [];
        $batchWarnings = [];

        foreach ($invoices as $invoice) {
            $status = $invoice['match_status'] ?? 'not_found';
            if ($status === 'linked') {
                continue;
            }

            $attId = !empty($invoice['attachment_id'])
                ? $invoice['attachment_id']
                : (!empty($invoice['display_attachment_id']) ? $invoice['display_attachment_id'] : '');

            if (!empty($attId)) {
                $res = $this->importSupplierInvoice($invoice, $api);
                if (!empty($res['res']) && (int) $res['res'] > 0) {
                    $processed++;
                    if (!empty($this->warnings)) {
                        $batchWarnings = array_merge($batchWarnings, $this->warnings);
                    }
                } else {
                    $uuid = trim((string) ($invoice['id'] ?? ''));
                    $ref = $invoice['invoice_number'] ?? ($invoice['reference'] ?? $uuid);
                    $errMsg = $this->error ?: ($res['message'] ?? 'Import error');
                    $batchErrors[] = $ref . ' : ' . $errMsg;
                }
            }
        }

        $this->errors = $batchErrors;
        $this->warnings = $batchWarnings;

        return $processed;
    }
}