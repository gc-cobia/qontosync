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
    /**
     * @var DoliDB|null
     */
    private $db;

    /**
     * @var string
     */
    public $error = '';

    /**
     * @var array
     */
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
     * @return string|bool              Raw file content (when $destFilePath is null), true (when saved to $destFilePath), or false on error
     */
    private function downloadUrl(string $url, ?string $destFilePath = null, int $timeout = 60)
    {
        global $conf;

        $this->error = '';
        $url = trim($url);
        if ($url === '') {
            $this->error = 'ErrorFileNotFound';
            return false;
        }

        $fp = null;
        if ($destFilePath !== null) {
            $fp = fopen($destFilePath, 'w+');
            if ($fp === false) {
                $this->error = 'ErrorFailedToSaveFile';
                dol_syslog(__METHOD__ . ': Unable to open the file for writing: ' . $destFilePath, LOG_ERR);
                return false;
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
            $this->error = curl_error($ch);
            dol_syslog(__METHOD__ . ': cURL error while downloading URL: ' . $this->error, LOG_ERR);
            curl_close($ch);
            if ($fp !== null) {
                fclose($fp);
                @unlink($destFilePath);
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
                return true;
            }

            @unlink($destFilePath);
            $this->error = 'ErrorHTTPCode' . $httpCode;
            dol_syslog(__METHOD__ . ': Failed to download file to ' . $destFilePath . '. HTTP Code: ' . $httpCode, LOG_ERR);
            return false;
        }

        if ($httpCode !== 200 || $response === '') {
            $this->error = 'ErrorHTTPCode' . $httpCode;
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
        $this->error = '';

        if ($api === null) {
            if (!class_exists('QontoApi')) {
                dol_include_once('/qontosync/class/qontoapi.class.php', 'QontoApi');
            }
            $api = new QontoApi();
        }

        $attachment = $api->getAttachment($attachment_id);
        if (empty($attachment) || empty($attachment['url'])) {
            $this->error = !empty($api->error) ? $api->error : 'ErrorFileNotFound';
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
            $this->error = $api->error;
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
                    if ($this->downloadUrl($statement_url, $dest_file_path, 60) === true) {
                        $result['success_count']++;
                        $result['valid_refs'][] = $ref_releve;
                        dol_syslog(__METHOD__ . ': Statement downloaded successfully for period ' . $period_key . ' to ' . $dest_file_path, LOG_INFO);
                    } else {
                        $result['error_count']++;
                        dol_syslog(__METHOD__ . ': Failed to download statement for period ' . $period_key . ': ' . $this->error, LOG_ERR);
                    }
                }
            }
        }

        return $result;
    }
}