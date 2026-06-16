<?php
if (!defined('NOTOKENRENEWAL')) define('NOTOKENRENEWAL', '1');
if (!defined('NOREQUIREAJAX'))  define('NOREQUIREAJAX', '1');

$res = 0;
if (! $res && file_exists("../../../main.inc.php")) $res = @include "../../../main.inc.php";
if (! $res && file_exists("../../../../main.inc.php")) $res = @include "../../../../main.inc.php";

dol_include_once('/qontosync/class/qontoapi.class.php');
dol_include_once('/qontosync/class/qontorepository.class.php');
dol_include_once('/qontosync/class/qontomatching.class.php');
dol_include_once('/qontosync/lib/qontosync.lib.php');

// Hide menu
$conf->dol_hide_topmenu = 1;
$conf->dol_hide_leftmenu = 1;

llxHeader('', $langs->trans("Rapprochement avancé"));

if (!$user->admin && !$user->hasRight('qontosync', 'read')) {
    print "<div class='error'>Accès interdit</div>";
    llxFooter();
    exit;
}

$qonto_id = GETPOST('qonto_id', 'alphanohtml');
$bank_rowid = GETPOST('bank_rowid', 'int');

if (empty($qonto_id)) {
    print "<div class='error'>ID de transaction manquant.</div>";
    llxFooter();
    exit;
}

// Single api call for modale
$api = new QontoApi();
$raw_trx = $api->getTransaction($qonto_id);

$processed_trx = $raw_trx;
$processed_trx['amount'] = (float)$raw_trx['amount'];

if (isset($raw_trx['side']) && $raw_trx['side'] == 'debit') {
    $processed_trx['amount'] = -$processed_trx['amount'];
}

if (empty($raw_trx)) {
    print "<div class='error'>Impossible de récupérer les détails de la transaction depuis Qonto.</div>";
    llxFooter();
    exit;
}

$qontoMatching = new QontoMatching($db);
$linked = $qontoMatching->isLinked($qonto_id, $bank_rowid);

// Print begin modal box
QontoSyncLib::printModalBegin();

// Print the transaction details
QontoSyncLib::printTransactionSummary($processed_trx);

if ($linked > 0) {
    print '<br>';
    print '<div class="info">';
    print '  <span class="fa fa-check-circle" style="color: green; font-size: 1.5em; vertical-align: middle; margin-right: 10px;"></span>';
    print '  <span style="vertical-align: middle;">Cette transaction est <strong>déjà rapprochée</strong> avec une écriture bancaire dans Dolibarr (Réf Bancaire : ' . $linked . ').</span>';
    print '</div>';
} else {
    $qontoRepo = new QontoRepository($db);
    $open_documents = $qontoRepo->getUnpaidDocuments('all');

    QontoSyncLib::printOpenDocumentsTable($processed_trx, $open_documents);
}

// Print end modal box
QontoSyncLib::printModalEnd();

llxFooter();