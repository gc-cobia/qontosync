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

/*
 * QontoSync dolibarr module 
 * @file       lib/qontosync.lib.php
 * @author     Gaëtan COURTOIS
 * @license    GPLv3
 */

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