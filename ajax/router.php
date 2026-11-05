<?php
/*
 * QontoSync dolibarr module 
 * @file       ajax/router.php
 * @author     Gaëtan COURTOIS
 * @license    GPLv3
 */

if (!defined('NOREQUIREMENU'))  define('NOREQUIREMENU', '1');
if (!defined('NOREQUIREAJAX'))  define('NOREQUIREAJAX', '1');
if (!defined('NOREQUIREHTML'))  define('NOREQUIREHTML', '1');
if (!defined('NOCSRFCHECK'))    define('NOCSRFCHECK', '1');
if (!defined('NOTOKENRENEWAL')) define('NOTOKENRENEWAL', '1');

/** NO INCEPTION SCRIPT - START **/
ob_start();
$session_is_valid = false;
register_shutdown_function(function() use (&$session_is_valid) {
    if (!$session_is_valid) {
        if (ob_get_length()) {
            ob_end_clean();
        }
        top_httphead('application/json; charset=utf-8');
        http_response_code(401);
        echo json_encode(['error' => 'SESSION_EXPIRED']);
        exit;
    }
});
/*******************************/

$res = 0;
if (! $res && file_exists("../../../main.inc.php")) $res = @include "../../../main.inc.php";
if (! $res && file_exists("../../../../main.inc.php")) $res = @include "../../../../main.inc.php";

/** NO INCEPTION SCRIPT - END **/
$session_is_valid = true;
ob_end_clean();
/*******************************/

top_httphead('application/json; charset=utf-8');

if (!isModEnabled('qontosync')) {
    http_response_code(400);
    echo json_encode(['error' => 'MODULE_IS_NOT_ENABLED']);
    exit;
}

$input = json_decode(file_get_contents('php://input'), true);

if (json_last_error() !== JSON_ERROR_NONE) {
    http_response_code(400);
    echo json_encode(['error' => 'MALFORMED_JSON']);
    exit;
}

$tokenParam = $input['token'] ?? '';
if (empty($tokenParam) || $tokenParam !== currentToken()) {
    http_response_code(401);
    echo json_encode(['error' => 'INVALID_TOKEN']);
    exit;
}

if ($user->socid > 0 || !$user->hasRight('qontosync', 'read')) {
    http_response_code(403);
    echo json_encode(['error' => 'PERMISSION_DENIED']);
    exit;
}

$langs->loadLangs(['qontosync@qontosync', 'banks', 'bills', 'trips', 'compta', 'other']);

$controllerParam = $input['controller'] ?? null;
$actionParam     = $input['action'] ?? null;
$data            = $input['data'] ?? [];

$cleanController = preg_replace('/[^a-zA-Z0-9]/', '', (string) $controllerParam);
$methodName      = preg_replace('/[^a-zA-Z0-9]/', '', (string) $actionParam);
$className       = ucfirst($cleanController) . 'Controller';

$isLoaded = dol_include_once("/qontosync/ajax/{$cleanController}.ctrl.php");

if (!$isLoaded) {
    http_response_code(404);
    echo json_encode(['error' => "CONTROLLER_NOT_FOUND"]);
    exit;
}

if (class_exists($className)) {
    try {
        $instance = new $className($db);

        if (is_callable([$instance, $methodName]) && strpos($methodName, '_') !== 0) {
            $reponse = $instance->$methodName(is_array($data) ? $data : []);
        } else {
            $reponse = ['http_code' => 404, 'error' => "ACTION_NOT_FOUND"];
        }
    } catch (\Throwable $e) {
        dol_syslog("QontoSync Router Error: " . $e->getMessage(), LOG_ERR);
        $reponse = ['http_code' => 500, 'error' => "INTERNAL_SERVER_ERROR"];
    }
} else {
    $reponse = ['http_code' => 404, 'error' => "CLASS_NOT_FOUND"];
}

$httpCode = 200;
if (isset($reponse['http_code'])) {
    $httpCode = (int) $reponse['http_code'];
    unset($reponse['http_code']);
}

http_response_code($httpCode);
echo json_encode($reponse);
exit;