<?php

// htdocs/custom/qontosync/index.php

// 1. Environnement Dolibarr
require '../../main.inc.php';
require_once DOL_DOCUMENT_ROOT.'/core/lib/date.lib.php';
require_once DOL_DOCUMENT_ROOT.'/compta/bank/class/account.class.php';
require_once './class/qonto.class.php';
require_once './lib/qontosync.lib.php';

// 2. Chargement des langues et droits
$langs->loadLangs(array("qontosync@qontosync", "banks", "admin"));
if (!$user->rights->qontosync->read) {
	accessforbidden();
}

// 3. Récupération des paramètres
$action    = GETPOST('action', 'aZ09');
$token     = GETPOST('token', 'alpha');
$month     = GETPOST('month', 'int') ?: date('m');
$year      = GETPOST('year', 'int')  ?: date('Y');
$bank_id   = GETPOST('bank_id', 'int');

$qonto_transactions = array();
$error = 0;

// 4. Logique métier (Orchestration)
if ($action == 'search' && $bank_id > 0) {
	// Vérification du token de sécurité
	if (!checkToken()) {
		accessforbidden();
	}

	// Récupération de l'IBAN du compte Dolibarr sélectionné
	$account = new Account($db);
	$account->fetch($bank_id);
	$iban = $account->iban;

	if (empty($iban)) {
		setEventMessages($langs->trans("QontoSyncErrorNoIbanOnAccount"), null, 'errors');
		$error++;
	} else {
		// Appel de la classe de collecte (Data Collection)
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

/*
 * 5. Affichage (View via Lib)
 */

$page_name = $langs->trans("QontoSyncMenuTitle");
llxHeader('', $page_name);

// Titre de la page
print load_fiche_titre($page_name, '', 'bank');

// Affichage du formulaire de recherche (via la lib)
qontosync_print_search_form($month, $year, $bank_id);

print '<br>';

// Affichage des résultats (via la lib)
if ($action == 'search' && !$error) {
	if (empty($qonto_transactions)) {
		print '<div class="opacitymedium">' . $langs->trans("NoRecordFound") . '</div>';
	} else {
		// La lib va gérer le tableau et la recherche des écritures matching Dolibarr
		qontosync_print_results_table($qonto_transactions, $bank_id);
	}
}

llxFooter();
$db->close();
