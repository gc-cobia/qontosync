<?php
/*
 * QontoSync dolibarr module 
 * @file       advancedBox.php
 * @author     Gaëtan COURTOIS
 * @license    GPLv3
 */

if (!defined('NOTOKENRENEWAL')) define('NOTOKENRENEWAL', '1');
if (!defined('NOREQUIREAJAX'))  define('NOREQUIREAJAX', '1');

$res = 0;
if (! $res && file_exists("../../../main.inc.php")) $res = @include "../../../main.inc.php";
if (! $res && file_exists("../../../../main.inc.php")) $res = @include "../../../../main.inc.php";

dol_include_once('/qontosync/class/qontoapi.class.php');
dol_include_once('/qontosync/class/qontorepository.class.php');
dol_include_once('/qontosync/class/qontomatching.class.php');
dol_include_once('/qontosync/lib/qontosync.lib.php');

// Load language files for translations (especially for native payment modes)
$langs->loadLangs(array("qontosync@qontosync", "banks", "bills", "compta"));

// Hide Dolibarr menus for the modal view
$conf->dol_hide_topmenu = 1;
$conf->dol_hide_leftmenu = 1;

// Load specific JS script for the modal interactions
$arrayofjs = array('/qontosync/js/advanced_matching.js');
llxHeader('', $langs->trans("AdvancedSearch"), '', '', 0, 0, $arrayofjs);

// Security check
if (!$user->admin && !$user->hasRight('qontosync', 'read')) {
    print "<div class='error'>Accès interdit</div>";
    llxFooter();
    exit;
}

// Retrieve parameters (Make sure the URL passes 'bank_rowid')
$qonto_id = GETPOST('qonto_id', 'alphanohtml');
$bank_rowid = GETPOST('bank_rowid', 'int');

if (empty($qonto_id)) {
    print "<div class='error'>ID de transaction manquant.</div>";
    llxFooter();
    exit;
}

// Single API call to fetch the precise transaction details
$api = new QontoApi();
$raw_trx = $api->getTransaction($qonto_id);

if (empty($raw_trx)) {
    print "<div class='error'>Impossible de récupérer les détails de la transaction depuis Qonto.</div>";
    llxFooter();
    exit;
}

// Format the transaction data securely
$processed_trx = $raw_trx;
$processed_trx['amount'] = (float)$raw_trx['amount'];

// Invert the sign for debits to match accounting logic
if (isset($raw_trx['side']) && $raw_trx['side'] == 'debit') {
    $processed_trx['amount'] = -$processed_trx['amount'];
}

// Real-time check to see if the transaction is already linked in the database
// We set the 3rd parameter to 'false' to explicitly bypass the PHP cache
$qontoMatching = new QontoMatching($db);
$linked = $qontoMatching->isLinked($qonto_id, $bank_rowid, false); 

// Print begin modal box wrapper
QontoSyncLib::printModalBegin();

// Print the transaction header details
QontoSyncLib::printTransactionSummary($processed_trx);

// If the transaction has any link status other than 0 (not linked)
if ($linked !== 0) {
    print '<br>';
    print '<div class="info">';
    
    // Check if it is successfully linked (>0) or if there's a duplication error (-1)
    if ($linked > 0) {
        print '  <span class="fa fa-check-circle" style="color: green; font-size: 1.5em; vertical-align: middle; margin-right: 10px;"></span>';
        print '  <span style="vertical-align: middle;">Cette transaction est <strong>déjà rapprochée</strong> avec une écriture bancaire dans Dolibarr (Réf Bancaire : ' . $linked . ').</span>';
    } else {
        print '  <span class="fa fa-exclamation-triangle" style="color: orange; font-size: 1.5em; vertical-align: middle; margin-right: 10px;"></span>';
        print '  <span style="vertical-align: middle;">Erreur : Cette transaction possède <strong>plusieurs rapprochements</strong> en base de données.</span>';
    }
    
    print '</div>';
    
// If the transaction is NOT linked, show the unpaid documents table
} else {
    $qontoRepo = new QontoRepository($db);
    $open_documents = $qontoRepo->getUnpaidDocuments('all');

    QontoSyncLib::printOpenDocumentsTable($processed_trx, $open_documents);
}

// Print end modal box wrapper
QontoSyncLib::printModalEnd();

llxFooter();