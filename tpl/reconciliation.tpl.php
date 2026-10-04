<?php
/*
 * QontoSync dolibarr module 
 * @file       tpl/reconciliation.tpl.php
 * @author     Gaëtan COURTOIS
 * @license    GPLv3
 */

if (!defined('DOL_DOCUMENT_ROOT')) {
    exit('Access Forbidden');
}

$fixed_width = "100%";
$style_badge = "width: {$fixed_width}; display: inline-block; text-align: center; box-sizing: border-box; padding: 6px; white-space: normal;";
$style_select = "width: {$fixed_width}; max-width: {$fixed_width}; box-sizing: border-box;";
?>

<div style="display: flex; flex-direction: column; height: calc(100vh - 160px); gap: 15px;">
    
<form id="qontosync_search_form" method="post" style="margin: 0; flex-shrink: 0;"
          data-lang-ajax-error="<?php print dol_escape_htmltag($langs->transnoentities("QontoSyncAjaxError")); ?>"
          data-lang-processing-batch="<?php print dol_escape_htmltag($langs->transnoentities("QontoSyncProcessingBatch")); ?>"
          data-lang-processing-advanced="<?php print dol_escape_htmltag($langs->transnoentities("QontoSyncProcessingAdvanced")); ?>"
          data-lang-searching="<?php print dol_escape_htmltag($langs->transnoentities("QontoSyncSearching")); ?>">

        <input type="hidden" name="action" value="search">
        <input type="hidden" name="token" value="<?php print newToken(); ?>">
    	
        <div class="divadvancedsearchfield centpercent" style="box-sizing: border-box; display: flex; justify-content: space-between; align-items: center; padding: 10px; margin-bottom: 15px; background-color: var(--colorbacktitle1); border: 1px solid var(--colortopbordertitle1); border-radius: 6px;">
        
            <!-- Left float box -->
    	    <div style="display: flex; align-items: center; gap: 15px; flex-wrap: wrap;">
    	
    	        <!--  Month/Year Selector -->
    	        <div>
    	            <span class="marginrightonly" style="font-weight: bold;"><?php print $langs->trans("Month"); ?> / <?php print $langs->trans("Year"); ?> :</span>
    	            <span class="marginrightonly">
    	                <?php print $select_month_html; ?>
    	                <?php print $select_year_html; ?>
    	            </span>
    	        </div>
    
    	        <!-- Bank Account Selector -->
    	        <div>
    	            <span class="marginrightonly" style="font-weight: bold;"><?php print $langs->trans("BankAccount"); ?> :</span>
    	            <span class="marginrightonly">
    	                <?php print $select_comptes_html; ?>
    	            </span>
    	        </div>
    
    	        <!--  Submit Button for search -->
    	        <div>
    	            <input type="submit" class="button" value="<?php print $langs->trans("Search"); ?>">
    	        </div>
    
    	        <!-- Toggle switch to hide linked transactions-->
                <div class="inline-block valignmiddle qontosync-toggle-wrapper">
    	            <span class="classfortooltip valignmiddle qontosync-tooltip-trigger" title="<?php print dol_escape_htmltag($langs->trans("HideLinked")); ?>">
                        <?php $eye_icon = getDolGlobalString('QONTOSYNC_HIDE_LINKED') ? 'fa-eye-slash' : 'fa-eye'; ?>
                        <span id="qontosync_eye_icon" class="fa <?php print $eye_icon; ?> qontosync-eye-icon"></span>
                    </span>
    	            <div class="inline-block valignmiddle qontosync-switch-container">
                        <?php print ajax_constantonoff('QONTOSYNC_HIDE_LINKED'); ?>
                    </div>
                </div>
    	
            </div>
    
    	    <!-- Button validate all matches in right float box -->
			<button type="button" class="butAction" id="btn_validate_conciliation" style="border: none; cursor: pointer; display: inline-flex; align-items: center; font-size: 1em;">
                <i class="fas fa-link marginrightonly"></i><?php print $langs->trans("Validate"); ?>
            </button>
    	
    	</div>
    </form>

    
    <!-- Container for AJAX view -->
    <div id="qontosync_results_container" style="flex: 1 1 auto; overflow-y: auto; width: 100%; min-height: 0; box-sizing: border-box;">
        <div style="text-align:center; padding:50px;" class="opacitymedium">
            <i class="fa fa-info-circle fa-2x" style="margin-bottom: 10px;"></i><br>
            <?php print $langs->trans("QontoSyncInitialMessage"); ?>
        </div>
    </div>
</div>

<style>
<?php if (!empty(getDolGlobalString('QONTOSYNC_BADGE_COLOR'))): ?>
@media (prefers-color-scheme: light) {
    #qontosync_results_container .qonto-badge-info,
    #qontosync_advanced_wrapper .qonto-badge-info {
        background-color: <?php print dol_escape_htmltag(getDolGlobalString('QONTOSYNC_BADGE_COLOR')); ?> !important;
        border-color: <?php print dol_escape_htmltag(getDolGlobalString('QONTOSYNC_BADGE_COLOR')); ?> !important;
    }
}
<?php endif; ?>

<?php if (!empty(getDolGlobalString('QONTOSYNC_BADGE_COLOR_DARK'))): ?>
@media (prefers-color-scheme: dark) {
    #qontosync_results_container .qonto-badge-info,
    #qontosync_advanced_wrapper .qonto-badge-info {
        background-color: <?php print dol_escape_htmltag(getDolGlobalString('QONTOSYNC_BADGE_COLOR_DARK')); ?> !important;
        border-color: <?php print dol_escape_htmltag(getDolGlobalString('QONTOSYNC_BADGE_COLOR_DARK')); ?> !important;
    }
}
<?php endif; ?>
</style>

<!-- Translation dictionary for JS template rendering -->
<div id="qonto-js-translations" style="display: none;"
     data-optgroup-bank="<?php print dol_escape_htmltag($langs->trans('BankTransactions')); ?>"
     data-optgroup-cinv="<?php print dol_escape_htmltag($langs->trans('CustomersInvoices')); ?>"
     data-optgroup-sinv="<?php print dol_escape_htmltag($langs->trans('SupplierInvoices')); ?>"
     data-optgroup-vat="<?php print dol_escape_htmltag($langs->trans('VAT')); ?>"
     data-optgroup-social="<?php print dol_escape_htmltag($langs->trans('SocialContributions')); ?>"
     data-optgroup-salary="<?php print dol_escape_htmltag($langs->trans('Salaries')); ?>"
     data-optgroup-expense="<?php print dol_escape_htmltag($langs->trans('ExpenseReports')); ?>">
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

<!-- Template All Reconciled Message -->
<template id="tpl-all-linked-message">
    <div id="qonto-all-linked-message" class="qonto-all-linked-container opacitymedium" style="text-align: center; padding: 50px; display: none;">
        <i class="fa fa-check-circle fa-2x" style="margin-bottom: 10px; color: var(--amountpaymentcomplete, #5cb85c);"></i><br>
        <span><?php print $langs->trans("QontoSyncAllReconciled"); ?></span>
    </div>
</template>

<!-- Template Table Container & Batch Form -->
<template id="tpl-results-table">
    <div id="qonto-table-wrapper" style="background: var(--colorbackbody, #fff); padding-bottom: 120px;">
        <form id="form_batch_link" method="post" action="<?php print dol_escape_htmltag($_SERVER["PHP_SELF"]); ?>" style="margin: 0;">
            <input type="hidden" name="token" value="">
            <input type="hidden" name="action" value="batch_link">
            <input type="hidden" name="bank_rowid" value="">
            
            <table class="noborder centpercent" style="margin: 0;">
                <thead>
                    <tr class="liste_titre">
                        <td style="width: 1%; white-space: nowrap;"><?php print $langs->trans('Date'); ?></td>
                        <td><?php print $langs->trans('Label'); ?></td>
                        <td><?php print $langs->trans('Amount'); ?></td>
                        <td><?php print $langs->trans('Correspondence'); ?></td>
                        <td style="width: 1%; white-space: nowrap;">&nbsp;</td>
                    </tr>
                </thead>
                <tbody class="qonto-transactions-tbody"></tbody>
            </table>
        </form>
    </div>
</template>

<!-- Template Transaction Row -->
<template id="tpl-transaction-row">
    <tr class="oddeven trx-row">
        <td class="trx-date" style="width: 1%; white-space: nowrap;"></td>
        <td class="trx-label-cell">
            <strong class="trx-label"></strong>
            <span class="badge qonto-badge-info badge-inline trx-reference" style="display: none;"></span>
            <br>
            <code class="opacitymedium small trx-id-display" style="font-size:0.8em;"></code>
        </td>
        <td class="right trx-amount-cell">
            <span class="amount trx-amount"></span>
        </td>
        <td class="trx-correspondence-cell" style="width: 350px; min-width: 350px; max-width: 350px;"></td>
        <td class="center trx-action-cell" style="width: 1%; white-space: nowrap;">
            <div class="qonto-dropdown">
                <button type="button" class="button qonto-dropdown-toggle" aria-haspopup="true" aria-expanded="false" title="<?php print dol_escape_htmltag($langs->trans("Action")); ?>">
                    <span class="fas fa-bars"></span>
                </button>
                <div class="qonto-dropdown-content" role="menu">
                    <!-- Advanced Search -->
                    <button type="button" role="menuitem" class="btn-open-advanced-match qonto-dropdown-item" title="<?php print dol_escape_htmltag($langs->trans("AdvancedSearch")); ?>">
                        <span class="fa fa-search marginrightonly"></span><?php print dol_escape_htmltag($langs->transnoentities("AdvancedSearch")); ?>
                    </button>

                    <!-- Unlink Transaction -->
                    <button type="button" role="menuitem" class="btn-ask-unlink qonto-dropdown-item" style="display: none;" title="<?php print dol_escape_htmltag($langs->trans("UnlinkTransaction")); ?>">
                        <span class="fas fa-undo marginrightonly"></span><?php print dol_escape_htmltag($langs->transnoentities("UnlinkTransaction")); ?>
                    </button>

                    <!-- New Various Payment -->
                    <button type="button" role="menuitem" class="btn-ask-create-od qonto-dropdown-item" style="display: none;" title="<?php print dol_escape_htmltag($langs->trans("NewVariousPayment")); ?>">
                        <span class="fas fa-plus marginrightonly"></span><?php print dol_escape_htmltag($langs->transnoentities("NewVariousPayment")); ?>
                    </button>
                </div>
            </div>
        </td>
    </tr>
</template>

<!-- Template Status: Error Too Many Trx -->
<template id="tpl-status-error-too-many">
    <span class="badge badge-danger" style="<?php print $style_badge; ?>">
        <?php print $langs->trans('ErrorTooManyTrx'); ?>
    </span>
</template>

<!-- Template Status: Linked Transaction -->
<template id="tpl-status-linked">
    <a href="#" target="_blank" class="badge badge-success trx-link-url" style="<?php print $style_badge; ?>" title="<?php print dol_escape_htmltag($langs->trans('BankTransaction')); ?>">
        <?php print $langs->trans('Linked'); ?> ( # <span class="trx-linked-id"></span> )
    </a>
</template>

<!-- Template Status: No Correspondence Found -->
<template id="tpl-status-no-correspondence">
    <span class="badge badge-warning" style="<?php print $style_badge; ?>">
        <?php print $langs->trans('NoCorrespondenceFound'); ?>
    </span>
</template>

<!-- Template Correspondence Select -->
<template id="tpl-correspondence-select">
    <select class="flat trx-select" style="<?php print $style_select; ?>">
        <option value="">&nbsp;</option>
    </select>
</template>

<!-- Template Optgroup -->
<template id="tpl-optgroup">
    <optgroup label=""></optgroup>
</template>

<!-- Template Option -->
<template id="tpl-select-option">
    <option value=""></option>
</template>

<!-- ========================================== -->
<!-- CONFIRMATION DIALOG MODALS                 -->
<!-- ========================================== -->

<!-- Confirmation modal for new various payment -->
<div id="dialog-confirm-create-od" 
     title="<?php print dol_escape_htmltag($langs->trans("NewVariousPayment")); ?>" 
     data-yes="<?php print dol_escape_htmltag($langs->transnoentities("Yes")); ?>" 
     data-no="<?php print dol_escape_htmltag($langs->transnoentities("No")); ?>" 
     style="display: none;">
    <p><span class="fas fa-exclamation-circle" style="float:left; margin:12px 12px 20px 0;"></span><?php print $langs->trans("ConfirmNewVariousPaymentQuestion"); ?></p>
    <div class="info" style="margin-bottom: 15px; padding: 10px;">
        <div style="margin-bottom: 5px;"><strong><?php print $langs->trans("Date"); ?> :</strong> <span id="od_dialog_date"></span></div>
        <div style="margin-bottom: 5px;"><strong><?php print $langs->trans("Label"); ?> :</strong> <span id="od_dialog_label"></span></div>
        <div><strong><?php print $langs->trans("Amount"); ?> :</strong> <span id="od_dialog_amount" style="font-weight: bold;"></span></div>
    </div>
    <?php 
    // Add accountancy selector if double entry accounting module is enabled
    if (isModEnabled('accounting')) {
        if (!class_exists('FormAccounting')) require_once DOL_DOCUMENT_ROOT.'/core/class/html.formaccounting.class.php';
        $formaccounting = new FormAccounting($db);
        $accountancy_accounts_list = $formaccounting->select_account('', 'od_accountancy_code', 1, array(), 1, 1, 'centpercent');
        print '<div style="margin-top: 20px;">';
        print '<label for="od_accountancy_code" style="font-weight: bold; margin-bottom: 5px; display: inline-block;">' . $langs->trans("AccountingAccount") . '</label><br>';
        print $accountancy_accounts_list;
        print '</div>';
    }
    ?>
    <div style="margin-top: 15px;">
        <label for="od_attached_file" style="font-weight: bold; margin-bottom: 5px; display: inline-block;"><?php print dol_escape_htmltag($langs->trans("AttachANewFile")); ?></label><br>
        <input type="file" id="od_attached_file" name="od_attached_file" class="flat minwidth200" style="width: 100%; max-width: 100%; box-sizing: border-box;" />
    </div>
</div>

<!-- Confirmation modal for unlinking -->
<div id="dialog-confirm-unlink" 
     title="<?php print dol_escape_htmltag($langs->trans("UnlinkTransaction")); ?>" 
     data-yes="<?php print dol_escape_htmltag($langs->transnoentities("Yes")); ?>" 
     data-no="<?php print dol_escape_htmltag($langs->transnoentities("No")); ?>" 
     style="display: none;">
    <p><span class="fas fa-exclamation-circle" style="float:left; margin:12px 12px 20px 0;"></span><?php print $langs->trans("ConfirmUnlinkTransaction"); ?></p>
</div>
