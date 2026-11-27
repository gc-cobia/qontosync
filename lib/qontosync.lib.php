<?php
/*
 * QontoSync dolibarr module 
 * @file       lib/qontosync.lib.php
 * @author     Gaëtan COURTOIS
 * @license    GPLv3
 */

if (!defined('DOL_DOCUMENT_ROOT')) {
    http_response_code(403);
    exit('Access Forbidden');
}

function qontosyncAdminPrepareHead()
{
    global $langs;

    $langs->load("qontosync@qontosync");

    $h = 0;
    $head = array();

    $head[$h][0] = dol_buildpath('/qontosync/admin/setup.php', 2);
    $head[$h][1] = $langs->trans("Setup");
    $head[$h][2] = 'setup';
    $h++;

    $head[$h][0] = dol_buildpath('/qontosync/admin/reconciliation.php', 2);
    $head[$h][1] = $langs->trans("Reconciliation");
    $head[$h][2] = 'reconciliation';
    $h++;

    $head[$h][0] = dol_buildpath('/qontosync/admin/einvoicing.php', 2);
    $head[$h][1] = $langs->trans("EInvoicing");
    $head[$h][2] = 'einvoicing';
    $h++;

    return $head;
}

/**
 * Append a cache-busting query parameter (?v=<mtime>) to a relative asset path.
 *
 * @param  string $relativePath Relative path to CSS or JS asset
 * @return string               Versioned relative path
 */
function qontosyncVersionedAsset($relativePath)
{
    if (!function_exists('dol_filemtime')) require_once DOL_DOCUMENT_ROOT.'/core/lib/files.lib.php';

    $cleanPath = explode('?', $relativePath, 2)[0];
    $filepath = dol_buildpath($cleanPath, 0);
    $mtime = dol_filemtime($filepath);

    $version = ($mtime !== false && $mtime > 0) ? (string) ((int) $mtime) : (defined('DOL_VERSION') ? DOL_VERSION : '');
    if ($version === '') {
        return $relativePath;
    }

    $separator = (strpos($relativePath, '?') === false) ? '?' : '&';
    return $relativePath . $separator . 'v=' . rawurlencode($version);
}

/**
 * Append a cache-busting query parameter to an array of relative asset paths.
 *
 * @param  string[] $relativePaths Array of relative paths to CSS or JS assets
 * @return string[]                Array of versioned relative paths
 */
function qontosyncVersionedAssets(array $relativePaths)
{
    return array_map('qontosyncVersionedAsset', $relativePaths);
}

/**
 * Build the HTML <link> tag for QontoSync stylesheet with cache-busting parameter.
 *
 * @return string
 */
function qontosyncCssHeadTag()
{
    $cssUrl = dol_buildpath(qontosyncVersionedAsset('/qontosync/css/qontosync.css'), 1);
    return '<link rel="stylesheet" type="text/css" href="'.dol_escape_htmltag($cssUrl).'">'."\n";
}