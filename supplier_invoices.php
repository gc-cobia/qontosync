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

if (!class_exists('QontoApi')) dol_include_once('/qontosync/class/qontoapi.class.php', 'QontoApi');
if (!class_exists('QontoMatching')) dol_include_once('/qontosync/class/qontomatching.class.php', 'QontoMatching');
if (!class_exists('QontoEInvoicing')) dol_include_once('/qontosync/class/qontoeinvoicing.class.php', 'QontoEInvoicing');

$langs->loadLangs(array("qontosync@qontosync", "einvoicing@einvoicing", "main", "bills", "suppliers", "companies"));

$action = GETPOST('action', 'aZ09');
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

$eiEnabled = (bool) getDolGlobalInt('QONTOSYNC_EI_ENABLED');
$einvoicingModEnabled = isModEnabled('einvoicing');
$canWrite = $user->hasRight('qontosync', 'write');
$pendingActionHtml = '';

$qontoApi = null;
$rawInvoices = [];

if ($eiEnabled) {
    $qontoApi = new QontoApi();
    $fetched = $qontoApi->getSupplierInvoices();
    if (is_array($fetched)) {
        $rawInvoices = $fetched;
    } elseif (!empty($qontoApi->error)) {
        setEventMessages($langs->trans($qontoApi->error), null, 'errors');
    }
}

/*
 * Actions
 */
if ($eiEnabled && $canWrite && !empty($action)) {
    try {
        $einvoicingService = new QontoEInvoicing($db);

        if ($action === 'import_invoice') {
            $qontoId = GETPOST('qonto_id', 'alphanohtml');
            $targetInvoice = null;

            foreach ($rawInvoices as $inv) {
                if (isset($inv['id']) && (string) $inv['id'] === (string) $qontoId) {
                    $targetInvoice = $inv;
                    break;
                }
            }

            if ($targetInvoice === null) {
                setEventMessages($langs->trans("QontoEInvoicingInvoiceNotFound"), null, 'errors');
            } else {
                $resImport = $einvoicingService->importSupplierInvoice($targetInvoice, $qontoApi);
                if (!empty($resImport['res']) && (int) $resImport['res'] > 0) {
                    $facId = (int) $resImport['res'];
                    if (!empty($resImport['created'])) {
                        setEventMessages($langs->trans("QontoEInvoicingInvoiceImported", $facId), null, 'mesgs');
                    } else {
                        setEventMessages($langs->trans("QontoEInvoicingInvoiceLinked", $facId), null, 'mesgs');
                    }
                    if (!empty($einvoicingService->warnings)) {
                        setEventMessages(null, $einvoicingService->warnings, 'warnings');
                    }
                } else {
                    $errMsg = $einvoicingService->error ?: ($resImport['message'] ?? $langs->trans("QontoEInvoicingImportError"));
                    setEventMessages($langs->trans($errMsg), null, 'errors');
                    if (!empty($resImport['action'])) {
                        $pendingActionHtml = $resImport['action'];
                    }
                }
            }
        } elseif ($action === 'import_all') {
            $nbSuccess = $einvoicingService->autoSyncSupplierInvoices($rawInvoices, $qontoApi);
            if ($nbSuccess < 0) {
                setEventMessages($langs->trans($einvoicingService->error ?: "QontoEInvoicingImportError"), null, 'errors');
            } else {
                $nbErrors = count($einvoicingService->errors);
                if (!empty($einvoicingService->warnings)) {
                    setEventMessages(null, $einvoicingService->warnings, 'warnings');
                }
                if (!empty($einvoicingService->errors)) {
                    setEventMessages(null, $einvoicingService->errors, 'errors');
                }
                setEventMessages($langs->trans("QontoEInvoicingBatchCompleted", $nbSuccess, $nbErrors), null, ($nbErrors > 0 ? 'warnings' : 'mesgs'));
            }
        }
    } catch (\Throwable $e) {
        dol_syslog("QontoSync supplier_invoices.php exception: " . $e->getMessage() . " in " . $e->getFile() . ":" . $e->getLine(), LOG_ERR);
        setEventMessages($e->getMessage() . ' (' . basename($e->getFile()) . ':' . $e->getLine() . ')', null, 'errors');
    }
}

/*
 * View
 */
$title = "QontoSync - " . $langs->trans("SupplierInvoices");
llxHeader('', $title);

if (!$eiEnabled) {
    print load_fiche_titre($title, '', 'object_qonto.svg@qontosync');
    print '<div class="error" style="margin-bottom: 15px; padding: 10px;">' . $langs->trans("QontoEInvoicingNotEnabled") . '</div>';
} else {
    $qontoMatching = new QontoMatching($db);
    $invoices = $qontoMatching->matchSupplierInvoices($rawInvoices);

    // Count how many invoices can be imported or updated/linked via einvoicing
    $nbToProcess = 0;
    foreach ($invoices as $inv) {
        $matchStatus = $inv['match_status'] ?? 'not_found';
        $attId = !empty($inv['attachment_id']) ? $inv['attachment_id'] : (!empty($inv['display_attachment_id']) ? $inv['display_attachment_id'] : '');
        if ($matchStatus !== 'linked' && !empty($attId) && $einvoicingModEnabled) {
            $nbToProcess++;
        }
    }

    $morehtmlright = '';
    if ($canWrite && $nbToProcess > 0) {
        $urlImportAll = dol_buildpath('/qontosync/supplier_invoices.php', 1) . '?action=import_all&token=' . newToken();
        $morehtmlright .= dolGetButtonTitle($langs->trans("QontoEInvoicingImportAll") . ' (' . $nbToProcess . ')', '', 'fa fa-cloud-download-alt', $urlImportAll);
    }

    print load_fiche_titre($title, $morehtmlright, 'object_qonto.svg@qontosync');

    if (!$einvoicingModEnabled) {
        print '<div class="warning" style="margin-bottom: 15px; padding: 10px;">' . $langs->trans("QontoEInvoicingModuleRequired") . '</div>';
    }

    if (!empty($pendingActionHtml)) {
        print '<div class="warning" style="margin-bottom: 15px; padding: 12px;">' . $pendingActionHtml . '</div>';
    }

    print '<div class="div-table-responsive">';
    print '<table class="tagtable liste">' . "\n";
    print '<tr class="liste_titre">';
    print '<th>' . $langs->trans("Date") . '</th>';
    print '<th>' . $langs->trans("Ref") . '</th>';
    print '<th>' . $langs->trans("Supplier") . '</th>';
    print '<th class="right">' . $langs->trans("AmountTTC") . '</th>';
    print '<th class="center">' . $langs->trans("Status") . '</th>';
    print '<th class="center">Dolibarr</th>';
    print '<th class="center">' . $langs->trans("Action") . '</th>';
    print '</tr>';

    if (!empty($invoices) && is_array($invoices)) {
        foreach ($invoices as $inv) {
            $qontoId = trim((string) ($inv['id'] ?? ''));
            $raw_date = $inv['issue_date'] ?? $inv['created_at'] ?? '';
            $date_op = !empty($raw_date) ? strtotime($raw_date) : '';
            $ref = $inv['invoice_number'] ?? $inv['reference'] ?? $qontoId;
            $supplier = $inv['supplier_name'] ?? ($inv['seller']['name'] ?? ($inv['counterparty']['name'] ?? '-'));
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

            // Statut Qonto
            print '<td class="center">';
            $badge_class = 'badge-status0';
            if ($status === 'paid') $badge_class = 'badge-status4';
            elseif ($status === 'to_pay') $badge_class = 'badge-status1';
            elseif ($status === 'to_review') $badge_class = 'badge-status3';
            print '<span class="badge ' . $badge_class . '">' . dol_escape_htmltag($status) . '</span>';
            print '</td>';

            // Statut Dolibarr
            $matchStatus = $inv['match_status'] ?? 'not_found';
            $facId = $inv['dolibarr_rowid'] ?? null;

            print '<td class="center nowrap">';
            if ($matchStatus === 'linked' && !empty($facId)) {
                $url_fac = DOL_URL_ROOT . '/fourn/facture/card.php?facid=' . (int) $facId;
                print '<a href="' . dol_escape_htmltag($url_fac) . '" class="badge badge-status4" target="_blank">';
                print '<i class="fas fa-check-circle marginrightonly"></i>' . $langs->trans("QontoEInvoicingStatusImported") . ' (#' . (int) $facId . ')';
                print '</a>';
            } elseif ($matchStatus === 'matched' && !empty($facId)) {
                $url_fac = DOL_URL_ROOT . '/fourn/facture/card.php?facid=' . (int) $facId;
                print '<a href="' . dol_escape_htmltag($url_fac) . '" class="badge badge-status3" target="_blank" title="' . dol_escape_htmltag($langs->trans("QontoEInvoicingStatusMatchedTooltip")) . '">';
                print '<i class="fas fa-link marginrightonly"></i>' . $langs->trans("QontoEInvoicingStatusMatched") . ' (#' . (int) $facId . ')';
                print '</a>';
            } else {
                print '<span class="badge badge-status0">' . $langs->trans("QontoEInvoicingStatusNotImported") . '</span>';
            }
            print '</td>';

            // Actions (Import / Synchronisation via EInvoicing + Téléchargement PDF)
            $att_id = !empty($inv['attachment_id']) ? $inv['attachment_id'] : (!empty($inv['display_attachment_id']) ? $inv['display_attachment_id'] : '');
            print '<td class="center nowrap">';
            $hasAction = false;

            if ($canWrite && $matchStatus !== 'linked' && !empty($att_id) && $einvoicingModEnabled) {
                $url_import = dol_buildpath('/qontosync/supplier_invoices.php', 1) . '?action=import_invoice&qonto_id=' . urlencode($qontoId) . '&token=' . newToken();
                print '<a href="' . dol_escape_htmltag($url_import) . '" class="button smallpaddingimp marginrightonly">';
                print '<i class="fas fa-file-import marginrightonly"></i>' . $langs->trans("Import");
                print '</a>';
                $hasAction = true;
            }

            if (!empty($att_id)) {
                $url_view = dol_buildpath('/qontosync/supplier_invoices.php', 1) . '?view_attachment=' . urlencode($att_id) . '&token=' . newToken();
                print '<a href="' . dol_escape_htmltag($url_view) . '" target="_blank" class="button smallpaddingimp">';
                print '<i class="fas fa-file-pdf marginrightonly"></i>' . $langs->trans("OpenVerb");
                print '</a>';
                $hasAction = true;
            }

            if (!$hasAction) {
                print '<span class="opacitymedium">-</span>';
            }
            print '</td>';
            print '</tr>';
        }
    } else {
        print '<tr><td colspan="7" class="center opacitymedium">' . $langs->trans("NoTransactionsFound") . '</td></tr>';
    }
    print '</table>';
    print '</div>';
}

llxFooter();
