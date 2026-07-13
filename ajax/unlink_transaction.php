<?php
/*
 * QontoSync dolibarr module 
 * @file       ajax/unlink_transaction.php
 * @author     Gaëtan COURTOIS
 * @license    GPLv3
 */

if (!defined('NOTOKENRENEWAL')) define('NOTOKENRENEWAL', '1');
if (!defined('NOREQUIREMENU'))  define('NOREQUIREMENU', '1');
if (!defined('NOREQUIREAJAX'))  define('NOREQUIREAJAX', '1');

/** NO INCEPTION SCRIPT **/
ob_start();
$session_is_valid = false;
register_shutdown_function(function() use (&$session_is_valid) {
    if (!$session_is_valid) {
        if (ob_get_length()) {
            ob_end_clean();
        }
        http_response_code(401);
        echo "SESSION_EXPIRED";
        exit;
    }
});
/*************************/

$res = 0;
if (! $res && file_exists("../../../main.inc.php")) $res = @include "../../../main.inc.php";
if (! $res && file_exists("../../../../main.inc.php")) $res = @include "../../../../main.inc.php";

/** NO INCEPTION SCRIPT **/
$session_is_valid = true;
ob_end_clean();
/*************************/

// Security check
if (GETPOST('token', 'alpha') !== currentToken()) {
    http_response_code(401);
    echo "Invalid Token";
    exit;
}

if ($user->socid > 0 || !$user->hasRight('qontosync', 'write')) {
    http_response_code(403);
    echo "Permission Denied";
    exit;
}

$langs->loadLangs(array("qontosync@qontosync"));

$bank_line_id = GETPOST('bank_line_id', 'int');

if (empty($bank_line_id)) {
    http_response_code(400);
    echo "Bad Request: missing bank_line_id";
    exit;
}

if (!class_exists('QontoUpdater')) dol_include_once('/qontosync/class/qontoupdater.class.php');
$updater = new QontoUpdater($db);
$res = $updater->unlinkTransaction($bank_line_id);

if ($res < 0) {
    http_response_code(500);
    echo "Error unlinking transaction";
    exit;
}

setEventMessages($langs->trans("TransactionSuccessfullyUnlinked"), null, 'mesgs');
echo "OK";
exit;
