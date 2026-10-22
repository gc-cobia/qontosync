<?php
/**
 * QontoSync dolibarr module
 * @file       supplier_invoices.php
 * @author     Gaëtan COURTOIS
 * @license    GPLv3
 */

$res = 0;
if (! $res && file_exists("../../main.inc.php")) $res = @include "../../main.inc.php";
if (! $res && file_exists("../../../main.inc.php")) $res = @include "../../../main.inc.php";
if (! $res && file_exists("../../../../main.inc.php")) $res = @include "../../../../main.inc.php";
if (! $res && preg_match('/\/custom\//', $_SERVER["PHP_SELF"])) $res = @include "../../../../main.inc.php";

if ($user->socid > 0 || !$user->hasRight('qontosync', 'read')) {
    accessforbidden();
}

if (!class_exists('QontoApi')) dol_include_once('/qontosync/class/qontoapi.class.php');

$langs->loadLangs(array("qontosync@qontosync", "main", "bills", "suppliers"));

$view_attachment_id = GETPOST('view_attachment', 'alphanohtml');

if (!empty($view_attachment_id)) {
    $qontoApi = new QontoApi();
    $attachment = $qontoApi->getAttachment($view_attachment_id);

    if (!empty($attachment['url'])) {
        header("Location: " . $attachment['url']);
        exit;
    }

    setEventMessages($langs->trans("ErrorFileNotFound"), null, 'errors');
}

$title = "QontoSync - " . $langs->trans("SupplierInvoices");
llxHeader('', $title);

print load_fiche_titre($title, '', 'object_qonto.svg@qontosync');

if (!getDolGlobalInt('QONTOSYNC_EI_ENABLED')) {
    print '<div class="error" style="margin-bottom: 15px; padding: 10px;">' . $langs->trans("QontoEInvoicingNotEnabled") . '</div>';
} else {
    $qontoApi = new QontoApi();
    $invoices = $qontoApi->getSupplierInvoices();

    print '<div class="div-table-responsive">';
    print '<table class="tagtable liste">' . "\n";
    print '<tr class="liste_titre">';
    print '<th>' . $langs->trans("Date") . '</th>';
    print '<th>' . $langs->trans("Ref") . '</th>';
    print '<th>' . $langs->trans("Supplier") . '</th>';
    print '<th class="right">' . $langs->trans("AmountTTC") . '</th>';
    print '<th class="center">' . $langs->trans("Status") . '</th>';
    print '<th class="center">' . $langs->trans("Action") . '</th>';
    print '</tr>';

    if (!empty($invoices) && is_array($invoices)) {
        foreach ($invoices as $inv) {
            $raw_date = $inv['issue_date'] ?? $inv['created_at'] ?? '';
            $date_op = !empty($raw_date) ? strtotime($raw_date) : '';
            $ref = $inv['invoice_number'] ?? $inv['reference'] ?? $inv['id'];
            $supplier = $inv['supplier_name'] ?? '-';
            $status = $inv['status'] ?? '';
            $amount = 0;
            $currency = 'EUR';

            if (isset($inv['total_amount']['value'])) {
                $amount = (float) $inv['total_amount']['value'];
                $currency = $inv['total_amount']['currency'] ?? 'EUR';
            } elseif (isset($inv['amount'])) {
                $amount = (float) $inv['amount'];
                $currency = $inv['currency'] ?? 'EUR';
            }

            print '<tr class="oddeven">';
            print '<td class="nowrap">' . ($date_op ? dol_print_date($date_op, 'day') : '-') . '</td>';
            print '<td><strong>' . dol_escape_htmltag($ref) . '</strong></td>';
            print '<td>' . dol_escape_htmltag($supplier) . '</td>';
            print '<td class="right nowrap">' . price($amount) . ' ' . dol_escape_htmltag($currency) . '</td>';

            print '<td class="center">';
            $badge_class = 'badge-status0';
            if ($status === 'paid') $badge_class = 'badge-status4';
            elseif ($status === 'to_pay') $badge_class = 'badge-status1';
            elseif ($status === 'to_review') $badge_class = 'badge-status3';
            print '<span class="badge ' . $badge_class . '">' . dol_escape_htmltag($status) . '</span>';
            print '</td>';

            $att_id = !empty($inv['attachment_id']) ? $inv['attachment_id'] : (!empty($inv['display_attachment_id']) ? $inv['display_attachment_id'] : '');
            print '<td class="center nowrap">';
            if (!empty($att_id)) {
                $url_view = dol_buildpath('/qontosync/supplier_invoices.php', 1) . '?view_attachment=' . urlencode($att_id) . '&token=' . newToken();
                print '<a href="' . dol_escape_htmltag($url_view) . '" target="_blank" class="button">';
                print '<i class="fas fa-file-pdf marginrightonly"></i>' . $langs->trans("OpenVerb");
                print '</a>';
            } else {
                print '<span class="opacitymedium">-</span>';
            }
            print '</td>';

            print '</tr>';
        }
    } else {
        print '<tr><td colspan="6" class="center opacitymedium">' . $langs->trans("NoTransactionsFound") . '</td></tr>';
    }

    print '</table>';
    print '</div>';
}

llxFooter();