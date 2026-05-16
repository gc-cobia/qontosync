<?php

// htdocs/custom/qontosync/index.php

require '../../main.inc.php';
require_once DOL_DOCUMENT_ROOT.'/core/lib/date.lib.php';
require_once DOL_DOCUMENT_ROOT.'/compta/bank/class/account.class.php';
require_once './class/qonto.class.php';
require_once './class/matching.class.php'; // Inclusion de la nouvelle classe
require_once './lib/qontosync.lib.php';

$langs->loadLangs(array("qontosync@qontosync", "banks"));

if (!$user->rights->qontosync->read) accessforbidden();

$action    = GETPOST('action', 'aZ09');
$month     = GETPOST('month', 'int') ?: date('m');
$year      = GETPOST('year', 'int')  ?: date('Y');
$bank_id   = GETPOST('bank_id', 'int');

// -----------------------------------------
// ACTION : TRAITEMENT DE LA LIAISON (POST)
// -----------------------------------------
if ($action == 'link') {
	if (!checkToken()) accessforbidden();
	
	$qonto_id = GETPOST('qonto_id', 'alpha');
	$bank_line_id = GETPOST('bank_line_id', 'int');
	
	if ($bank_line_id > 0 && !empty($qonto_id)) {
		// Instanciation de la classe Matching
		$matching = new Matching($db);
		$res = $matching->linkTransaction($bank_line_id, $qonto_id);
		
		if ($res > 0) {
			setEventMessages($langs->trans("QontoSyncLinkSuccess"), null, 'mesgs');
		} else {
			setEventMessages($matching->error, null, 'errors');
		}
	}
	$action = 'search'; // On repasse en mode recherche pour réafficher le tableau
}

// -----------------------------------------
// ACTION : COLLECTE DES DONNÉES
// -----------------------------------------
$qonto_transactions = array();
$error = 0;

if ($action == 'search' && $bank_id > 0) {
	if (!checkToken()) accessforbidden();

	$account = new Account($db);
	$account->fetch($bank_id);
	$iban = $account->iban;

	if (empty($iban)) {
		setEventMessages($langs->trans("QontoSyncErrorNoIbanOnAccount"), null, 'errors');
		$error++;
	} else {
		$qonto = new Qonto($db);
		$result = $qonto->fetchTransactions($iban, $month, $year);

		if ($result === -1) {
			setEventMessages($langs->trans($qonto->error), $qonto->errors, 'errors');
			$error++;
		} else {
			$qonto_transactions = $result;
		}
	}
}

// -----------------------------------------
// AFFICHAGE
// -----------------------------------------
$page_name = $langs->trans("QontoSyncMenuTitle");
llxHeader('', $page_name);

print load_fiche_titre($page_name, '', 'bank');

qontosync_print_search_form($month, $year, $bank_id);

print '<br>';

if ($action == 'search' && !$error) {
	if (empty($qonto_transactions)) {
		print '<div class="opacitymedium">' . $langs->trans("NoRecordFound") . '</div>';
	} else {
		qontosync_print_results_table($qonto_transactions, $bank_id);
	}
}

llxFooter();
$db->close();
