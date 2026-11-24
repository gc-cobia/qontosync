<?php
/*
 * QontoSync dolibarr module
 * @file       tpl/supplier_invoices.tpl.php
 * @author     Gaëtan COURTOIS
 * @license    GPLv3
 */

if (!defined('DOL_DOCUMENT_ROOT')) {
    http_response_code(403);
    exit('Access Forbidden');
}

?>

<div style="display: flex; flex-direction: column; height: calc(100vh - 160px); gap: 15px;">

    <?php if (!$einvoicingModEnabled): ?>
    <div class="warning" style="margin-bottom: 0; padding: 10px; flex-shrink: 0;">
        <?php print $langs->trans("QontoEInvoicingModuleRequired"); ?>
    </div>
    <?php endif; ?>

    <!-- Dynamic pending action banner returned by EInvoicing on failure -->
    <div id="qonto-supplier-pending-action" class="warning" style="display: none; margin-bottom: 0; padding: 12px; flex-shrink: 0;"></div>

    <form id="qontosync_search_form" method="post" style="margin: 0; flex-shrink: 0;"
          data-lang-ajax-error="<?php print dol_escape_htmltag($langs->transnoentities("QontoSyncAjaxError")); ?>"
          data-lang-processing-batch="<?php print dol_escape_htmltag($langs->transnoentities("QontoSyncProcessingBatch")); ?>"
          data-lang-searching="<?php print dol_escape_htmltag($langs->transnoentities("QontoEInvoicingSearching")); ?>">

        <input type="hidden" name="token" value="<?php print newToken(); ?>">

        <div class="divadvancedsearchfield centpercent" style="box-sizing: border-box; display: flex; justify-content: space-between; align-items: center; padding: 10px; margin-bottom: 15px; background-color: var(--colorbacktitle1); border: 1px solid var(--colortopbordertitle1); border-radius: 6px;">

            <!-- Left float box -->
            <div style="display: flex; align-items: center; gap: 15px; flex-wrap: wrap;">
                <button type="submit" id="btn_refresh_invoices" class="button">
                    <i class="fas fa-sync marginrightonly"></i><?php print $langs->trans("Refresh"); ?>
                </button>
            </div>

            <?php if ($user->hasRight('qontosync', 'supplier_invoices', 'import') && $einvoicingModEnabled): ?>
            <!-- Button to synchronize all pending supplier invoices -->
            <button type="button" class="butAction" id="btn_import_all_invoices" style="border: none; cursor: pointer; display: none; align-items: center; font-size: 1em;">
                <i class="fas fa-cloud-download-alt marginrightonly"></i><span class="btn-import-all-label"><?php print $langs->trans("QontoEInvoicingImportAll"); ?></span><span class="btn-import-all-count"></span>
            </button>
            <?php endif; ?>

        </div>
    </form>

    <!-- Container for AJAX view -->
    <div id="qontosync_results_container" style="flex: 1 1 auto; overflow-y: auto; width: 100%; min-height: 0; box-sizing: border-box;"></div>
</div>

<!-- Translation dictionary for JS template rendering -->
<div id="qonto-supplier-invoices-translations" style="display:none;"
     data-open="<?php print dol_escape_htmltag($langs->transnoentities('OpenVerb')); ?>"
     data-import="<?php print dol_escape_htmltag($langs->transnoentities('Import')); ?>"
     data-status-imported="<?php print dol_escape_htmltag($langs->transnoentities('QontoEInvoicingStatusImported')); ?>"
     data-status-matched="<?php print dol_escape_htmltag($langs->transnoentities('QontoEInvoicingStatusMatched')); ?>"
     data-status-matched-tooltip="<?php print dol_escape_htmltag($langs->transnoentities('QontoEInvoicingStatusMatchedTooltip')); ?>"
     data-status-not-imported="<?php print dol_escape_htmltag($langs->transnoentities('QontoEInvoicingStatusNotImported')); ?>"
     data-no-invoices="<?php print dol_escape_htmltag($langs->transnoentities('NoSupplierInvoicesFound')); ?>"
     data-import-all="<?php print dol_escape_htmltag($langs->transnoentities('QontoEInvoicingImportAll')); ?>"
     data-error-session-expired="<?php print dol_escape_htmltag($langs->transnoentities('SessionExpired')); ?>"
     data-error-invalid-token="<?php print dol_escape_htmltag($langs->transnoentities('InvalidToken')); ?>"
     data-error-permission-denied="<?php print dol_escape_htmltag($langs->transnoentities('PermissionDenied')); ?>"
     data-error-invalid-parameters="<?php print dol_escape_htmltag($langs->transnoentities('InvalidParameters')); ?>"
     data-error-einvoicing-not-enabled="<?php print dol_escape_htmltag($langs->transnoentities('QontoEInvoicingNotEnabled')); ?>"
     data-error-einvoicing-not-ready="<?php print dol_escape_htmltag($langs->transnoentities('QontoEInvoicingModuleRequired')); ?>"
     data-error-error-fetching-supplier-invoices="<?php print dol_escape_htmltag($langs->transnoentities('ErrorFetchingSupplierInvoices')); ?>"
     data-error-invoice-not-found="<?php print dol_escape_htmltag($langs->transnoentities('QontoEInvoicingInvoiceNotFound')); ?>"
     data-error-import-error="<?php print dol_escape_htmltag($langs->transnoentities('QontoEInvoicingImportError')); ?>"
     data-error-error-file-not-found="<?php print dol_escape_htmltag($langs->transnoentities('ErrorFileNotFound')); ?>"
     data-error-module-is-not-enabled="<?php print dol_escape_htmltag($langs->transnoentities('PermissionDenied')); ?>"
     data-error-internal-server-error="<?php print dol_escape_htmltag($langs->transnoentities('QontoSyncAjaxError')); ?>">
</div>

<!-- ========================================== -->
<!-- HTML5 TEMPLATES FOR CLIENT-SIDE RENDERING -->
<!-- ========================================== -->

<!-- Template Spinner / Loader -->
<template id="tpl-spinner">
    <div class="qonto-loading-container opacitymedium" style="text-align: center; padding: 50px;">
        <i class="fas fa-spinner fa-pulse fa-3x"></i><br><br>
        <span class="qonto-spinner-text"></span>
    </div>
</template>

<!-- Template Alert Box (Info / Warning / Error) -->
<template id="tpl-alert-box">
    <div class="qonto-alert-box" style="margin-bottom: 15px;">
        <span class="qonto-alert-text"></span>
    </div>
</template>

<!-- Template Table Container for supplier invoices -->
<template id="tpl-supplier-invoices-table">
    <div id="qonto-supplier-invoices-wrapper" class="div-table-responsive" style="background: var(--colorbackbody, #fff);">
        <table class="tagtable liste centpercent">
            <thead>
                <tr class="liste_titre">
                    <th><?php print $langs->trans("Date"); ?></th>
                    <th><?php print $langs->trans("Ref"); ?></th>
                    <th><?php print $langs->trans("Supplier"); ?></th>
                    <th class="right"><?php print $langs->trans("AmountTTC"); ?></th>
                    <th class="center"><?php print $langs->trans("Status"); ?></th>
                    <th class="center">Dolibarr</th>
                    <th class="center"><?php print $langs->trans("Action"); ?></th>
                </tr>
            </thead>
            <tbody class="qonto-supplier-invoices-tbody"></tbody>
        </table>
    </div>
</template>

<!-- Template Supplier Invoice Row -->
<template id="tpl-supplier-invoice-row">
    <tr class="oddeven">
        <td class="nowrap col-date"></td>
        <td class="col-ref"><strong class="col-ref-text"></strong></td>
        <td class="col-supplier"></td>
        <td class="right nowrap col-amount"></td>
        <td class="center col-qonto-status"><span class="badge col-qonto-status-badge"></span></td>
        <td class="center nowrap col-dolibarr-status"></td>
        <td class="center nowrap col-action"></td>
    </tr>
</template>

<!-- Template Empty Row -->
<template id="tpl-supplier-invoices-empty">
    <tr><td colspan="7" class="center opacitymedium col-empty-text"></td></tr>
</template>
