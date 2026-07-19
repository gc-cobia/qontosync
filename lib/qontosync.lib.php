<?php
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

    $head[$h][0] = dol_buildpath('/qontosync/admin/setup.php', 1);
    $head[$h][1] = $langs->trans("Setup");
    $head[$h][2] = 'setup';
    $h++;

    $head[$h][0] = dol_buildpath('/qontosync/admin/gui.php', 1);
    $head[$h][1] = $langs->trans("GUISetup");
    $head[$h][2] = 'gui';
    $h++;

    return $head;
}