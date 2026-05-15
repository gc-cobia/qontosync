<?php

// Load Dolibarr environment
$res = 0;
if (!$res && file_exists("../main.inc.php")) $res = @include "../main.inc.php";
if (!$res && file_exists("../../main.inc.php")) $res = @include "../../main.inc.php";

if (!$res) die("Include of main.inc.php failed");

require_once DOL_DOCUMENT_ROOT . '/core/class/html.form.class.php';
require_once DOL_DOCUMENT_ROOT . '/core/class/html.formother.class.php';
require_once DOL_DOCUMENT_ROOT . '/compta/bank/class/account.class.php';
require_once './class/qontoapi.class.php';

// Load language files
$langs->loadLangs(array("bank", "qontosync@qontosync"));

// Security check
if (!$user->hasRight('qontosync', 'read')) {
    accessforbidden();
}

$action = GETPOST('action', 'alpha');
$month = GETPOST('month', 'int') ? GETPOST('month', 'int') : date('m');
$year = GETPOST('year', 'int') ? GETPOST('year', 'int') : date('Y');
$bank_id = GETPOST('bank_id', 'int');

$form = new Form($db);
$formother = new FormOther($db);

llxHeader('', $langs->trans("QontoSyncDashboard"));

print load_fiche_titre($langs->trans("QontoSyncDashboard"), '', 'title_bank');

// Search Form
print '<form method="get" action="' . $_SERVER["PHP_SELF"] . '">';
print '<table class="noborder centpercent">';
print '<tr>';

// Month
print '<td class="maxwidth100">';
print $formother->select_month($month, 'month', 0);
print '</td>';

// Year
print '<td class="maxwidth100">';
print $formother->select_year($year, 'year', 0);
print '</td>';

// Bank account
print '<td>';
$form->select_comptes($bank_id, 'bank_id', 0, '', 1, '', 0, 'maxwidth200');
print '</td>';

print '<td class="right">';
print '<input type="submit" class="button" value="' . $langs->trans("Search") . '">';
print '</td>';

print '</tr>';
print '</table>';
print '</form>';

print '<br>';

if ($bank_id) {
    $account = new Account($db);
    if ($account->fetch($bank_id) > 0) {
        $iban = str_replace(' ', '', $account->iban);
        
        if (empty($iban)) {
            print '<div class="warning">' . $langs->trans("QontoSyncNoIbanHelp") . '</div>';
        } else {
            // Dates for Qonto API
            $date_from = date('Y-m-d\T00:00:00\Z', mktime(0, 0, 0, $month, 1, $year));
            $date_to = date('Y-m-d\T23:59:59\Z', mktime(0, 0, 0, $month + 1, 0, $year));

            $api = new QontoAPI($db, $conf->global->QONTOSYNC_API_LOGIN, $conf->global->QONTOSYNC_API_KEY, $conf->global->QONTOSYNC_MOCK_MODE);
            $transactions = $api->fetchTransactions($iban, $date_from, $date_to);

            print '<div class="under-title">' . $langs->trans("QontoSyncTransactionsFor", $month, $year) . '</div>';

            if (isset($transactions['error'])) {
                print '<div class="error">Error ' . $transactions['error'] . ': ' . $transactions['message'] . '</div>';
            } elseif (!empty($transactions)) {
                print '<table class="noborder centpercent">';
                print '<tr class="liste_titre">';
                print '<th>' . $langs->trans("QontoSyncDate") . '</th>';
                print '<th>' . $langs->trans("QontoSyncLabel") . '</th>';
                print '<th class="right">' . $langs->trans("QontoSyncAmount") . '</th>';
                print '<th class="center">' . $langs->trans("QontoSyncStatus") . '</th>';
                print '</tr>';

                foreach ($transactions as $txn) {
                    $date_txn = dol_print_date(strtotime($txn['settled_at']), 'day');
                    $real_amount = ($txn['side'] == 'debit' ? -$txn['amount'] : $txn['amount']);
                    $amount_formatted = price($real_amount, 0, $langs, 1, -1, -1, $txn['currency']);
                    $class = ($txn['side'] == 'debit' ? 'amountnegative' : 'amountpositive');

                    print '<tr class="oddeven">';
                    print '<td>' . $date_txn . '</td>';
                    print '<td>' . dol_escape_htmltag($txn['label']) . ' <br><small class="opacitymedium">' . $txn['transaction_id'] . '</small></td>';
                    print '<td class="right ' . $class . '">' . $amount_formatted . '</td>';
                    print '<td class="center"><span class="opacitymedium">N/A</span></td>';
                    print '</tr>';
                }
                print '</table>';
            } else {
                print '<p class="opacitymedium">' . $langs->trans("QontoSyncNoTransactions") . '</p>';
            }
        }
    }
}

llxFooter();
$db->close();
