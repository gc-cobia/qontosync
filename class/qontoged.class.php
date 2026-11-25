<?php
/*
 * QontoSync dolibarr module
 * @file       class/qontoged.class.php
 * @author     Gaëtan COURTOIS
 * @license    GPLv3
 */

if (!defined('DOL_DOCUMENT_ROOT')) {
    http_response_code(403);
    exit('Access Forbidden');
}

if (!trait_exists('QontoAccountTrait')) dol_include_once('/qontosync/class/qontoaccount.trait.php');


/**
 * Class QontoGed
 *
 * Centralizes external file downloads (pre-signed S3 URLs for attachments and bank statements)
 * and Dolibarr GED synchronization.
 */
class QontoGed
{
    use QontoAccountTrait;

    /** @var int Maximum download size in bytes (20 MiB) */
    const MAX_DOWNLOAD_SIZE = 20971520;

    /** @var DoliDB|null */
    private $db;
    /** @var string */
    public $error = '';
    /** @var array */
    public $errors = [];

    /**
     * Constructor
     *
     * @param DoliDB|null $db          Database handler
     * @param mixed       $accountOrId Optional Account object or bank account ID
     */
    public function __construct(DoliDB $db, $accountOrId = null)
    {
        $this->db = $db;
        if ($accountOrId !== null) $this->initAccount($accountOrId);
    }

    /**
     * Download a file from a pre-signed URL using Dolibarr's getURLContent().
     *
     * Delegates SSRF protection, protocol restrictions, proxy settings, and redirects to getURLContent(),
     * while enforcing a fallback maximum download size (20 MiB) when MAIN_SECURITY_MAXFILESIZE_DOWNLOADED is unset.
     *
     * @param string $url Target HTTPS URL
     * @return string|false Raw file content on success, or false on error
     */
    private function downloadUrl(string $url)
    {
        $this->error = '';
        $url = trim($url);

        if ($url === '') {
            $this->error = 'ErrorBadUrl';
            dol_syslog(__METHOD__ . ': URL is empty', LOG_ERR);
            return false;
        }

        if (!function_exists('getURLContent')) require_once DOL_DOCUMENT_ROOT . '/core/lib/geturl.lib.php';

        $hasDolibarrLimit = (getDolGlobalInt('MAIN_SECURITY_MAXFILESIZE_DOWNLOADED') > 0);
        $sizeLimitExceeded = false;
        $curlOptions = [];

        if (!$hasDolibarrLimit) {
            $progressCallback = function ($ch, $downloadTotal, $downloadedBytes, $uploadTotal, $uploadedBytes) use (&$sizeLimitExceeded) {
                if (($downloadTotal > 0 && $downloadTotal > self::MAX_DOWNLOAD_SIZE) || $downloadedBytes > self::MAX_DOWNLOAD_SIZE) {
                    $sizeLimitExceeded = true;
                    return 1;
                }
                return 0;
            };

            $curlOptions[CURLOPT_MAXFILESIZE] = self::MAX_DOWNLOAD_SIZE;
            $curlOptions[CURLOPT_NOPROGRESS] = false;
            if (defined('CURLOPT_XFERINFOFUNCTION')) {
                $curlOptions[CURLOPT_XFERINFOFUNCTION] = $progressCallback;
            } elseif (defined('CURLOPT_PROGRESSFUNCTION')) {
                $curlOptions[CURLOPT_PROGRESSFUNCTION] = $progressCallback;
            }
        }

        $result = getURLContent($url, 'GET', '', 1, ['User-Agent: Dolibarr QontoSync'], ['https'], 0, 1, 10, 60, $curlOptions);

        if (!is_array($result) || !empty($result['curl_error_no'])) {
            $curlErrNo = is_array($result) ? (int) ($result['curl_error_no'] ?? 0) : 0;
            $curlErrMsg = is_array($result) ? (string) ($result['curl_error_msg'] ?? '') : 'Invalid getURLContent response';

            if ($sizeLimitExceeded || $curlErrNo === 63 || (defined('CURLE_FILESIZE_EXCEEDED') && $curlErrNo === CURLE_FILESIZE_EXCEEDED)) {
                $this->error = 'ErrorFileSizeTooLarge';
                dol_syslog(__METHOD__ . ': Download aborted because file exceeded max allowed size', LOG_ERR);
            } else {
                $this->error = 'ErrorFileNotFound';
                dol_syslog(__METHOD__ . ': cURL error while downloading URL (' . $curlErrNo . '): ' . $curlErrMsg, LOG_ERR);
            }
            return false;
        }

        $httpCode = (int) ($result['http_code'] ?? 0);
        if ($httpCode !== 200) {
            $this->error = 'ErrorFileNotFound';
            dol_syslog(__METHOD__ . ': HTTP error ' . $httpCode . ' while downloading file', LOG_ERR);
            return false;
        }

        $content = (string) ($result['content'] ?? '');
        if ($content === '') {
            $this->error = 'ErrorFileNotFound';
            dol_syslog(__METHOD__ . ': Empty content received while downloading file', LOG_ERR);
            return false;
        }

        if (!$hasDolibarrLimit && strlen($content) > self::MAX_DOWNLOAD_SIZE) {
            $this->error = 'ErrorFileSizeTooLarge';
            dol_syslog(__METHOD__ . ': Downloaded content exceeded max size of ' . self::MAX_DOWNLOAD_SIZE . ' bytes', LOG_ERR);
            return false;
        }

        return $content;
    }

    /**
     * Download the raw binary/XML content of a Qonto attachment by its ID
     *
     * @param string        $attachment_id UUID of the attachment
     * @param QontoApi|null $api           Optional QontoApi instance
     * @return string|false                Raw file content or false on error
     */
    public function downloadAttachment($attachment_id, $api = null)
    {
        $this->error = '';

        if ($api === null) {
            if (!class_exists('QontoApi')) dol_include_once('/qontosync/class/qontoapi.class.php', 'QontoApi');
            $api = new QontoApi();
        }

        $attachment = $api->getAttachment($attachment_id);
        if (empty($attachment) || empty($attachment['url'])) {
            dol_syslog(__METHOD__ . ': Cannot fetch attachment metadata or URL for ID ' . $attachment_id . (!empty($api->error) ? ' (' . $api->error . ')' : ''), LOG_ERR);
            return false;
        }

        dol_syslog(__METHOD__ . ': Downloading attachment content for ID ' . $attachment_id, LOG_DEBUG);

        return $this->downloadUrl($attachment['url']);
    }

    /**
     * Download missing statements from Qonto to Dolibarr GED
     *
     * @param int           $year    Year to analyze
     * @param QontoApi|null $api     Instantiated Qonto API object
     * @return array                 Array with success, error, skipped counts and api_error flag
     */
    public function syncStatementsGED($year, $api = null): array
    {
        global $conf;

        $result = [
            'success_count'  => 0,
            'error_count'    => 0,
            'already_exists' => 0,
            'api_error'      => false,
            'valid_refs'     => []
        ];

        if (!$this->isAccountAllowed()) {
            dol_syslog(__METHOD__ . ": Bank account NOT allowed for current entity scope", LOG_ERR);
            $result['api_error'] = true;
            return $result;
        }

        if ($api === null) {
            if (!class_exists('QontoApi')) dol_include_once('/qontosync/class/qontoapi.class.php', 'QontoApi');
            $api = new QontoApi();
        }

        // 1. Retrieving the list of statements from the Qonto API
        $qonto_statements_raw = $api->getStatements($this->account->iban, $year);

        if ($qonto_statements_raw === false) {
            dol_syslog(__METHOD__ . ': Failed to retrieve statements from Qonto API for IBAN ' . $this->account->iban . (!empty($api->error) ? ' (' . $api->error . ')' : ''), LOG_ERR);
            $result['api_error'] = true;
            return $result;
        }

        // 2. Indexing of statements by period ("05-2024")
        $indexed_statements = array();
        if (is_array($qonto_statements_raw)) {
            foreach ($qonto_statements_raw as $stmt) {
                if (!empty($stmt['period'])) {
                    $indexed_statements[$stmt['period']] = $stmt;
                }
            }
        }

        // 3. Format definition
        $format_setting = getDolGlobalString('QONTOSYNC_STATEMENT_FORMAT') !== '' ? getDolGlobalString('QONTOSYNC_STATEMENT_FORMAT') : '{Y}-{m}';

        // 4. Download loop
        for ($m = 1; $m <= 12; $m++) {
            $period_key = sprintf("%02d-%04d", $m, $year);

            if (isset($indexed_statements[$period_key])) {
                $stmt = $indexed_statements[$period_key];
                $statement_url = $stmt['file']['file_url'] ?? '';

                // Calculating the exact file name
                $filter_start_month = sprintf("%04d-%02d-01", $year, $m);
                $time_month = strtotime($filter_start_month);
                $raw_ref = preg_replace_callback('/\{([^}]+)\}/', function ($matches) use ($time_month) {
                    return date($matches[1], $time_month);
                }, $format_setting);
                $clean_ref = dol_sanitizeFileName($raw_ref);
                $ref_releve = substr($clean_ref, 0, 50);

                $dir_output = $conf->bank->dir_output . '/' . $this->account->id . '/statement/' . $ref_releve;
                $filename = $ref_releve . '.pdf';
                $dest_file_path = $dir_output . '/' . $filename;

                // If the file already exists locally, it is ignored.
                if (file_exists($dest_file_path)) {
                    $result['already_exists']++;
                    $result['valid_refs'][] = $ref_releve;
                    continue;
                }

                // Secure download via getURLContent() with %PDF- verification
                if (!empty($statement_url)) {
                    $content = $this->downloadUrl($statement_url);
                    $stmtHost = (string) parse_url($statement_url, PHP_URL_HOST);

                    if ($content === false) {
                        $result['error_count']++;
                        dol_syslog(__METHOD__ . ': Failed to download statement for period ' . $period_key . ($stmtHost !== '' ? ' from host ' . $stmtHost : ''), LOG_ERR);
                        continue;
                    }

                    if (strncmp($content, '%PDF-', 5) !== 0) {
                        $this->error = 'ErrorInvalidPdfContent';
                        $result['error_count']++;
                        dol_syslog(__METHOD__ . ': Statement rejected for period ' . $period_key . ($stmtHost !== '' ? ' from host ' . $stmtHost : '') . ' - invalid PDF signature', LOG_ERR);
                        continue;
                    }

                    if (!file_exists($dir_output)) {
                        dol_mkdir($dir_output);
                    }
                    if (!is_dir($dir_output)) {
                        $this->error = 'ErrorFailToCreateFile';
                        $result['error_count']++;
                        dol_syslog(__METHOD__ . ': Unable to create statement directory ' . $dir_output, LOG_ERR);
                        continue;
                    }

                    $written = @file_put_contents($dest_file_path, $content, LOCK_EX);
                    if ($written === false || $written !== strlen($content)) {
                        @unlink($dest_file_path);
                        $this->error = 'ErrorFailToCreateFile';
                        $result['error_count']++;
                        dol_syslog(__METHOD__ . ': Failed to write downloaded statement to ' . $dest_file_path, LOG_ERR);
                        continue;
                    }

                    $umask = function_exists('getDolGlobalString') ? getDolGlobalString('MAIN_UMASK') : (!empty($conf->global->MAIN_UMASK) ? (string) $conf->global->MAIN_UMASK : '');
                    if ($umask !== '') {
                        @chmod($dest_file_path, octdec($umask));
                    }

                    $result['success_count']++;
                    $result['valid_refs'][] = $ref_releve;
                    dol_syslog(__METHOD__ . ': Statement downloaded successfully for period ' . $period_key . ' to ' . $dest_file_path, LOG_INFO);
                }
            }
        }

        return $result;
    }

    /**
     * Validate an attached file (filename and base64 content) before processing
     *
     * @param array $attached_file Array with 'filename' and 'content' (Base64)
     * @return int                 >0 if OK, <0 if KO
     */
    public function validateAttachment(array $attached_file): int
    {
        if (empty($attached_file['filename']) || empty($attached_file['content'])) {
            dol_syslog(__METHOD__ . ': Missing filename or content in attached file', LOG_ERR);
            return -1;
        }

        if (!function_exists('dol_sanitizeFileName')) {
            require_once DOL_DOCUMENT_ROOT . '/core/lib/files.lib.php';
        }

        $clean_filename = dol_sanitizeFileName(trim($attached_file['filename']));
        if (empty($clean_filename)) {
            dol_syslog(__METHOD__ . ': Sanitized filename is empty', LOG_ERR);
            return -1;
        }

        if (!function_exists('isAFileWithExecutableContent')) {
            require_once DOL_DOCUMENT_ROOT . '/core/lib/functions.lib.php';
        }

        if (isAFileWithExecutableContent($clean_filename) && !getDolGlobalString('MAIN_DOCUMENT_IS_OUTSIDE_WEBROOT_SO_NOEXE_NOT_REQUIRED')) {
            dol_syslog(__METHOD__ . ': Upload rejected - executable or forbidden extension: ' . $clean_filename, LOG_WARNING);
            return -1;
        }

        $binary_content = base64_decode($attached_file['content'], true);
        if ($binary_content === false || $binary_content === '') {
            dol_syslog(__METHOD__ . ': Base64 decode failed or empty content for file ' . $clean_filename, LOG_ERR);
            return -1;
        }

        return 1;
    }

    /**
     * Save an attached file for a Various Payment into Dolibarr GED
     *
     * @param int   $payment_id    PaymentVarious ID (rowid)
     * @param array $attached_file Array with 'filename' and 'content' (Base64)
     * @return int                 >0 if OK, <0 if KO
     */
    public function saveVariousPaymentAttachment(int $payment_id, array $attached_file): int
    {
        global $conf;

        if ($payment_id <= 0) {
            dol_syslog(__METHOD__ . ': Invalid payment ID: ' . $payment_id, LOG_ERR);
            return -1;
        }

        if ($this->validateAttachment($attached_file) <= 0) {
            return -1;
        }

        if (!function_exists('dol_sanitizeFileName')) {
            require_once DOL_DOCUMENT_ROOT . '/core/lib/files.lib.php';
        }

        $clean_filename = dol_sanitizeFileName(trim($attached_file['filename']));
        $binary_content = base64_decode($attached_file['content'], true);

        $bank_dir_output = !empty($conf->bank->dir_output) ? $conf->bank->dir_output : (DOL_DATA_ROOT . '/bank');
        $upload_dir = $bank_dir_output . '/' . dol_sanitizeFileName((string) $payment_id);

        if (!file_exists($upload_dir)) {
            if (!dol_mkdir($upload_dir)) {
                dol_syslog(__METHOD__ . ': Unable to create upload directory: ' . $upload_dir, LOG_ERR);
                return -1;
            }
        }

        $dest_file_path = $upload_dir . '/' . $clean_filename;
        $write_res = file_put_contents($dest_file_path, $binary_content);

        if ($write_res === false) {
            @unlink($dest_file_path);
            dol_syslog(__METHOD__ . ': Failed to write attached file to ' . $dest_file_path, LOG_ERR);
            return -1;
        }

        if (!empty($conf->global->MAIN_UMASK)) {
            @chmod($dest_file_path, octdec($conf->global->MAIN_UMASK));
        }

        dol_syslog(__METHOD__ . ': Attached file successfully saved to ' . $dest_file_path, LOG_INFO);
        return 1;
    }
}
