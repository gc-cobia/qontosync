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

/**
 * Class QontoGed
 *
 * Centralizes external file downloads (pre-signed S3 URLs for attachments and bank statements)
 * and Dolibarr GED synchronization.
 */
class QontoGed
{
    /** @var DoliDB|null */
    private $db;

    /** @var string */
    public $error = '';

    /** @var array */
    public $errors = [];

    /**
     * Constructor
     *
     * @param DoliDB|null $db Database handler
     */
    public function __construct($db = null)
    {
        $this->db = $db;
    }

    /**
     * Common cURL method to download a file from a pre-signed URL (either into memory or directly to a file)
     *
     * @param string      $url          Target HTTPS URL
     * @param string|null $destFilePath Optional local file path to write directly to disk (null = return content string)
     * @param int         $timeout      cURL timeout in seconds
     * @return string|int|false         Raw file content (when $destFilePath is null) or false on error; 1 (when saved to $destFilePath) or <0 on error
     */
    private function downloadUrl(string $url, ?string $destFilePath = null, int $timeout = 60)
    {
        global $conf;

        $url = trim($url);
        if ($url === '') {
            dol_syslog(__METHOD__ . ': URL is empty', LOG_ERR);
            return ($destFilePath !== null ? -1 : false);
        }

        $fp = null;
        if ($destFilePath !== null) {
            $fp = fopen($destFilePath, 'w+');
            if ($fp === false) {
                dol_syslog(__METHOD__ . ': Unable to open the file for writing: ' . $destFilePath, LOG_ERR);
                return -1;
            }
        }

        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
        curl_setopt($ch, CURLOPT_MAXREDIRS, 3);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 10);
        curl_setopt($ch, CURLOPT_TIMEOUT, $timeout);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 2);
        curl_setopt($ch, CURLOPT_USERAGENT, 'Dolibarr QontoSync');

        if (defined('CURLOPT_PROTOCOLS_STR')) {
            curl_setopt($ch, CURLOPT_PROTOCOLS_STR, 'HTTPS');
        } else {
            curl_setopt($ch, CURLOPT_PROTOCOLS, CURLPROTO_HTTPS);
        }

        if ($fp !== null) {
            curl_setopt($ch, CURLOPT_FILE, $fp);
        } else {
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        }

        $response = curl_exec($ch);
        if ($response === false) {
            $curl_err = curl_error($ch);
            dol_syslog(__METHOD__ . ': cURL error while downloading URL: ' . $curl_err, LOG_ERR);
            curl_close($ch);
            if ($fp !== null) {
                fclose($fp);
                @unlink($destFilePath);
                return -1;
            }
            return false;
        }

        $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($fp !== null) {
            fclose($fp);
            if ($httpCode === 200) {
                if (!empty($conf->global->MAIN_UMASK)) {
                    @chmod($destFilePath, octdec($conf->global->MAIN_UMASK));
                }
                return 1;
            }

            @unlink($destFilePath);
            dol_syslog(__METHOD__ . ': Failed to download file to ' . $destFilePath . '. HTTP Code: ' . $httpCode, LOG_ERR);
            return -1;
        }

        if ($httpCode !== 200 || $response === '') {
            dol_syslog(__METHOD__ . ': HTTP error ' . $httpCode . ' while downloading content into memory', LOG_ERR);
            return false;
        }

        return $response;
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
        if ($api === null) {
            if (!class_exists('QontoApi')) {
                dol_include_once('/qontosync/class/qontoapi.class.php', 'QontoApi');
            }
            $api = new QontoApi();
        }

        $attachment = $api->getAttachment($attachment_id);
        if (empty($attachment) || empty($attachment['url'])) {
            dol_syslog(__METHOD__ . ': Cannot fetch attachment metadata or URL for ID ' . $attachment_id . (!empty($api->error) ? ' (' . $api->error . ')' : ''), LOG_ERR);
            return false;
        }

        dol_syslog(__METHOD__ . ': Downloading attachment content for ID ' . $attachment_id, LOG_DEBUG);

        return $this->downloadUrl($attachment['url'], null, 30);
    }

    /**
     * Download missing statements from Qonto to Dolibarr GED
     *
     * @param Account       $account Bank account object
     * @param int           $year    Year to analyze
     * @param QontoApi|null $api     Instantiated Qonto API object
     * @return array                 Array with success, error, and skipped counts
     */
    public function syncStatementsGED($account, $year, $api = null)
    {
        global $conf;

        $result = array(
            'success_count'  => 0,
            'error_count'    => 0,
            'already_exists' => 0,
            'api_error'      => false,
            'valid_refs'     => array()
        );

        if ($api === null) {
            if (!class_exists('QontoApi')) {
                dol_include_once('/qontosync/class/qontoapi.class.php', 'QontoApi');
            }
            $api = new QontoApi();
        }

        // 1. Retrieving the list of statements from the Qonto API
        $qonto_statements_raw = $api->getStatements($account->iban, $year);

        if ($qonto_statements_raw === false) {
            dol_syslog(__METHOD__ . ': Failed to retrieve statements from Qonto API for IBAN ' . $account->iban . (!empty($api->error) ? ' (' . $api->error . ')' : ''), LOG_ERR);
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

                $dir_output = $conf->bank->dir_output . '/' . $account->id . '/statement/' . $ref_releve;
                if (!file_exists($dir_output)) {
                    dol_mkdir($dir_output);
                }

                $filename = $ref_releve . '.pdf';
                $dest_file_path = $dir_output . '/' . $filename;

                // If the file already exists locally, it is ignored.
                if (file_exists($dest_file_path)) {
                    $result['already_exists']++;
                    $result['valid_refs'][] = $ref_releve;
                    continue;
                }

                // Secure download via common cURL method
                if (!empty($statement_url)) {
                    if ($this->downloadUrl($statement_url, $dest_file_path, 60) > 0) {
                        $result['success_count']++;
                        $result['valid_refs'][] = $ref_releve;
                        dol_syslog(__METHOD__ . ': Statement downloaded successfully for period ' . $period_key . ' to ' . $dest_file_path, LOG_INFO);
                    } else {
                        $result['error_count']++;
                        dol_syslog(__METHOD__ . ': Failed to download statement for period ' . $period_key . ' from ' . $statement_url, LOG_ERR);
                    }
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
