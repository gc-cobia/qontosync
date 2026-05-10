<?php

/**
 * Page de tableau de bord pour la synchronisation Qonto.
 * Permet de visualiser les transactions Qonto et de les lier aux écritures Dolibarr.
 */

// Chargement de l'environnement Dolibarr
$res = 0;
if (!$res && file_exists("../../main.inc.php")) $res = @include "../../main.inc.php";
if (!$res && file_exists("../../../main.inc.php")) $res = @include "../../../main.inc.php";

if (!$res) die("Include of main.inc.php failed");

// Inclusions des classes Dolibarr et du module
require_once DOL_DOCUMENT_ROOT . '/compta/bank/class/account.class.php';
require_once DOL_DOCUMENT_ROOT . '/core/class/html.form.class.php';
require_once DOL_DOCUMENT_ROOT . '/core/class/html.formother.class.php';
require_once './class/api.qonto.class.php';
require_once './class/qontosync.class.php';

// Chargement des fichiers de langue
$langs->loadLangs(array("bank", "qontosync@qontosync"));

// Vérification des droits d'accès
if (!$user->hasRight('qontosync', 'read')) {
    accessforbidden();
}

// Récupération des paramètres de la page
$action = GETPOST('action', 'alpha');
$month = GETPOST('month', 'int') ? GETPOST('month', 'int') : date('m');
$year = GETPOST('year', 'int') ? GETPOST('year', 'int') : date('Y');
$bank_id = GETPOST('bank_id', 'int');

// Initialisation du service de matching
$matcher = new qontosyncMatcher($db);

/*
 * ACTIONS (Traitement des formulaires et liens)
 */

// Action de liaison manuelle
if ($action == 'link') {
    $qonto_id = GETPOST('qonto_id', 'alpha');
    $candidate_data = GETPOST('candidate_data', 'alpha'); // "type:id"
    $amount_val = (float) GETPOST('amount', 'alpha');
    $date_iso = GETPOST('date_iso', 'alpha');
    $operation_type = GETPOST('operation_type', 'alpha');
    $reference = GETPOST('reference', 'alpha');

    if ($qonto_id && $candidate_data) {
        list($candidate_type, $candidate_id) = explode(':', $candidate_data);
        $result = $matcher->linkCandidate($qonto_id, $candidate_type, (int)$candidate_id, $amount_val, $date_iso, $bank_id, $operation_type, $reference);
        if ($result >= 0) {
            setEventMessages($langs->trans("qontosyncMatchSuccess"), null, 'mesgs');
        } else {
            setEventMessages($langs->trans("Error") . ($matcher->error ? ' : ' . $matcher->error : ''), null, 'errors');
        }
    }
}

/*
 * VUE (Affichage de la page)
 */

$form = new Form($db);
$formother = new FormOther($db);

// Entête Dolibarr
llxHeader('', $langs->trans("qontosyncDashboard"));

// Titre de la fiche
print load_fiche_titre($langs->trans("qontosyncDashboard"), '', 'title_bank');

// Formulaire de sélection (Période et Compte)
print '<div class="info">' . $langs->trans("qontosyncSelectPeriodAndAccount") . '</div><br>';

print '<form method="get" action="' . $_SERVER["PHP_SELF"] . '">';
print '<input type="hidden" name="token" value="' . newToken() . '">';
print '<input type="hidden" name="action" value="search">';

print '<table class="noborder centpercent">';
print '<tr>';

// Sélection du mois
print '<td class="maxwidth100">';
print $formother->select_month($month, 'month', 0);
print '</td>';

// Sélection de l'année
print '<td class="maxwidth100">';
print $formother->select_year($year, 'year', 0);
print '</td>';

// Liste déroulante des comptes bancaires Dolibarr
print '<td>';
$nb_accounts = $form->select_comptes($bank_id, 'bank_id', 0, '', 1, '', 0, 'maxwidth200');
print '</td>';

// Boutons de recherche et d'appairage auto
if ($nb_accounts > 0) {
    print '<td class="right">';
    print '<input type="submit" class="button" value="' . $langs->trans("Search") . '">';
    print '&nbsp;';
    print '<a href="' . $_SERVER["PHP_SELF"] . '?action=automatch&month=' . $month . '&year=' . $year . '&bank_id=' . $bank_id . '&token=' . newToken() . '" class="button">' . $langs->trans("AutoMatch") . '</a>';
    print '</td>';
} else {
    print '<td><div class="warning">' . $langs->trans("qontosyncNoAccountInDolibarr") . '</div></td>';
}

print '</tr>';
print '</table>';
print '</form>';

print '<br>';

/*
 * AFFICHAGE DES RÉSULTATS
 */
if ($action == 'search' || $action == 'link' || $action == 'automatch') {
    if (!$bank_id) {
        setEventMessages($langs->trans("ErrorFieldRequired", $langs->trans("BankAccount")), null, 'errors');
    } else {
        
        // --- TODO AUTOMATCH ---
        if ($action == 'automatch') {
            // Implémentation future de l'automatch
        }

        print '<div class="under-title">' . $langs->trans("QontoTransactionsFor", $month, $year) . '</div>';

        // 1. Récupération des transactions via le service
        $transactions = $matcher->getTransactions($bank_id, $month, $year);

        if (is_int($transactions) && $transactions < 0) {
            // Gestion des erreurs (Compte non trouvé, IBAN manquant, Erreur API)
            $error_msg = $langs->trans($matcher->error);
            if ($error_msg == $matcher->error) {
                if (strpos($matcher->error, 'ApiError_') === 0) {
                    $error_code = str_replace('ApiError_', '', $matcher->error);
                    $error_msg = $langs->trans("qontosyncApiError" . $error_code);
                    if ($error_msg == "qontosyncApiError" . $error_code) {
                        $error_msg = $langs->trans("qontosyncApiError") . ' (Code: ' . $error_code . ')';
                    }
                }
            }
            print '<div class="error">' . $error_msg . '</div>';
        } elseif (!empty($transactions)) {
            // Affichage du tableau des transactions
            print '<table class="noborder centpercent">';
            print '<tr class="liste_titre">';
            print '<th>' . $langs->trans("Date") . '</th>';
            print '<th>' . $langs->trans("Label") . '</th>';
            print '<th class="right">' . $langs->trans("Amount") . '</th>';
            print '<th>' . $langs->trans("qontosyncSuggestions") . '</th>';
            print '<th class="center">' . $langs->trans("Status") . '</th>';
            print '</tr>';

            foreach ($transactions as $txn) {
                // Préparation des données de la ligne
                $date_txn_ts = strtotime($txn['settled_at']);
                $date_txn = dol_print_date($date_txn_ts, 'day');
                $real_amount = ($txn['side'] == 'debit' ? -$txn['amount'] : $txn['amount']);
                $amount = price($real_amount, 0, $langs, 1, -1, -1, $txn['currency']);
                $class = ($txn['side'] == 'debit' ? 'amountnegative' : 'amountpositive');

                print '<tr class="oddeven">';
                print '<td>' . $date_txn . '</td>';
                // Affichage du libellé + Référence/Note Qonto + ID technique
                print '<td>';
                print dol_trunc($txn['label'], 40);
                if (!empty($txn['reference'])) {
                    print ' <span class="badge badge-status0" style="background-color: #e0e0e0; color: #666; font-weight: normal; margin-left: 5px;" title="Référence : ' . dol_escape_htmltag($txn['reference']) . '">Ref: ' . dol_trunc($txn['reference'], 20) . '</span>';
                }
                print '<br><small class="opacitymedium">' . $txn['transaction_id'] . '</small>';
                print '</td>';
                print '<td class="right ' . $class . '">' . $amount . '</td>';
                
                // 2. Vérification si la transaction est déjà liée
                $linked_data = $matcher->getLinkedBankLine($txn['transaction_id']);
                $is_linked = (bool) $linked_data;
                $is_reconciled = $is_linked ? (bool) $linked_data['rappro'] : false;

                // Colonne SUGGESTIONS
                print '<td>';
                if (!$is_linked) {
                    // Si non liée, on cherche des candidats dans Dolibarr
                    $candidates = $matcher->getMatchingCandidates($bank_id, $real_amount, $txn['settled_at']);
                    
                    if (!empty($candidates)) {
                        print '<form method="get" action="' . $_SERVER["PHP_SELF"] . '" style="display: flex; align-items: center; gap: 5px; margin: 0;">';
                        print '<input type="hidden" name="action" value="link">';
                        print '<input type="hidden" name="qonto_id" value="' . $txn['transaction_id'] . '">';
                        print '<input type="hidden" name="amount" value="' . $real_amount . '">';
                        print '<input type="hidden" name="date_iso" value="' . $txn['settled_at'] . '">';
                        print '<input type="hidden" name="month" value="' . $month . '">';
                        print '<input type="hidden" name="year" value="' . $year . '">';
                        print '<input type="hidden" name="bank_id" value="' . $bank_id . '">';
                        print '<input type="hidden" name="operation_type" value="' . dol_escape_htmltag($txn['operation_type']) . '">';
                        print '<input type="hidden" name="reference" value="' . dol_escape_htmltag($txn['reference']) . '">';
                        print '<input type="hidden" name="token" value="' . newToken() . '">';
                        
                        print '<select name="candidate_data" class="flat" style="max-width: 250px;">';
                        foreach ($candidates as $sug) {
                            $sug_label = dol_print_date($db->jdate($sug['date']), 'day') . ' - ' . $sug['label'];
                            $val = $sug['type'] . ':' . $sug['id'];
                            print '<option value="' . $val . '">' . $sug_label . '</option>';
                        }
                        print '</select>';
                        
                        print '<button type="submit" class="button" title="' . $langs->trans("qontosyncLink") . '" style="padding: 2px 5px; min-width: 0;">';
                        print img_picto('', 'link');
                        print '</button>';
                        print '</form>';
                    } else {
                        print '<span class="opacitymedium">' . $langs->trans("None") . '</span>';
                    }
                } else {
                    // Si déjà liée, on affiche l'ID de l'écriture Dolibarr
                    print '<span class="opacitymedium">' . $langs->trans("qontosyncLinkedTo", $linked_data['id']) . '</span>';
                    
                    if (!$is_reconciled) {
                        // Bouton pour forcer le rapprochement de cette écriture
                        print '<form method="get" action="' . $_SERVER["PHP_SELF"] . '" style="display: inline-block; margin-top: 5px;">';
                        print '<input type="hidden" name="action" value="link">';
                        print '<input type="hidden" name="qonto_id" value="' . $txn['transaction_id'] . '">';
                        print '<input type="hidden" name="candidate_data" value="bank_line:' . $linked_data['id'] . '">';
                        print '<input type="hidden" name="amount" value="' . $real_amount . '">';
                        print '<input type="hidden" name="date_iso" value="' . $txn['settled_at'] . '">';
                        print '<input type="hidden" name="month" value="' . $month . '">';
                        print '<input type="hidden" name="year" value="' . $year . '">';
                        print '<input type="hidden" name="bank_id" value="' . $bank_id . '">';
                        print '<input type="hidden" name="operation_type" value="' . dol_escape_htmltag($txn['operation_type']) . '">';
                        print '<input type="hidden" name="reference" value="' . dol_escape_htmltag($txn['reference']) . '">';
                        print '<input type="hidden" name="token" value="' . newToken() . '">';
                        print '<button type="submit" class="button" title="' . $langs->trans("qontosyncReconcileThis") . '" style="padding: 2px 5px; min-width: 0;">';
                        print img_picto('', 'link');
                        print '</button>';
                        print '</form>';
                    }
                }
                print '</td>';

                // Colonne ÉTAT
                print '<td class="center">';
                if ($is_linked && $is_reconciled) {
                    print '<span class="badge badge-status4">' . $langs->trans("Imported") . '</span>';
                } elseif ($is_linked && !$is_reconciled) {
                    print '<span class="badge badge-status3" title="' . $langs->trans("qontosyncLinkedNotReconciled") . '">' . $langs->trans("qontosyncLinkedNotReconciledShort") . '</span>';
                } else {
                    print '<span class="badge badge-status1">' . $langs->trans("NotImported") . '</span>';
                }
                print '</td>';
                print '</tr>';
            }
            print '</table>';
        } else {
            print '<p class="opacitymedium">' . $langs->trans("NoTransactionFound") . '</p>';
        }
    }
}

// Pied de page Dolibarr
llxFooter();
$db->close();
