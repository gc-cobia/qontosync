<?php
/*
 * QontoSync dolibarr module 
 * @file       tpl/advanced_modal.tpl.php
 * @author     Gaëtan COURTOIS
 * @license    GPLv3
 */
 
if (!defined('DOL_DOCUMENT_ROOT')) {
    http_response_code(403);
    exit('Access Forbidden');
}

?>

<template id="tpl-advanced-wrapper">
<div id="qontosync_advanced_wrapper" class="fiche" style="padding: 15px; display: flex; flex-direction: column; gap: 15px;">

    <!-- Initial Spinner / Loader -->
    <div id="advanced_modal_loader" class="qonto-loading-container opacitymedium" style="text-align: center; padding: 50px;">
        <i class="fas fa-spinner fa-pulse fa-3x"></i><br><br>
        <span><?php print dol_escape_htmltag($langs->trans("Processing")); ?></span>
    </div>

    <!-- Error Container -->
    <div id="advanced_error_container" style="display: none;"></div>

    <!-- Dynamic Content Area -->
    <div id="advanced_content_container" style="display: none; display: flex; flex-direction: column; gap: 15px;">
        <!-- Transaction Details Section -->
        <div id="advanced_transaction_info"></div>

        <!-- Dynamic Matching / Linked Section -->
        <div id="advanced_dynamic_section"></div>
    </div>

</div>
</template>

<!-- ========================================== -->
<!-- HTML5 TEMPLATES FOR CLIENT-SIDE RENDERING  -->
<!-- ========================================== -->

<!-- Template: Transaction Details Header -->
<template id="tpl-advanced-info">
    <div class="titre fiche_titre" style="display: flex; align-items: center; gap: 8px; margin-bottom: 10px;">
        <span class="fas fa-university pictofixedwidth" style="font-size: 1.3em;"></span>
        <span class="fiche_titre_text"><?php print $langs->trans("QontoTransactionDetails"); ?></span>
    </div>
    
    <div style="display:grid;grid-template-columns:180px 1fr;row-gap:18px;">
        <div><?php print $langs->trans("Label"); ?></div>
        <div><span class="bold info-label"></span></div>
        
        <div><?php print $langs->trans("Date"); ?></div>
        <div class="info-date"></div>
        
        <div><?php print $langs->trans("Amount"); ?></div>
        <div><span class="amount info-amount"></span></div>
        
        <div><?php print $langs->trans("Type"); ?></div>
        <div class="info-type"></div>
        
        <div class="info-ref-title" style="display:none;"><?php print $langs->trans("Ref"); ?></div>
        <div class="info-ref-val" style="display:none;"></div>
    </div>
    
    <div class="oddeven" style="margin-top:20px;padding:8px 10px;">
        <code>ID : <span class="info-id"></span></code>
    </div>
</template>

<!-- Template: Already Linked Container -->
<template id="tpl-advanced-linked">
    <div class="info qonto-linked-message" style="margin-top: 15px;">
        <span class="fa fa-check-circle" style="color: green; font-size: 1.5em; vertical-align: middle; margin-right: 10px;"></span>
        <span class="linked-message-text" style="vertical-align: middle;"></span>
    </div>
    <div class="linked-details-container" style="margin-top: 20px; display: none;">
        <div class="titre fiche_titre" style="display: flex; align-items: center; gap: 8px; margin-bottom: 10px;">
            <span class="fas fa-file-invoice pictofixedwidth" style="font-size: 1.3em;"></span>
            <span class="fiche_titre_text"><?php print $langs->trans("Documents"); ?></span>
        </div>
        <div class="div-table-responsive">
            <table class="liste centpercent">
                <thead>
                    <tr class="liste_titre">
                        <th><?php print $langs->trans("Type"); ?></th>
                        <th class="center"><?php print $langs->trans("Payment"); ?></th>
                        <th><?php print $langs->trans("Document"); ?></th>
                        <th class="right"><?php print $langs->trans("Amount"); ?></th>
                    </tr>
                </thead>
                <tbody class="linked-details-tbody"></tbody>
            </table>
        </div>
    </div>
</template>

<!-- Template: Already Linked Row -->
<template id="tpl-advanced-linked-row">
    <tr class="oddeven">
        <td class="col-type"></td>
        <td class="center col-payment"><span class="badge qonto-badge-info"></span></td>
        <td class="col-doc">
            <strong class="col-ref"></strong>
            <span class="col-ref-supplier opacitymedium small" style="display:none;"></span>
        </td>
        <td class="right amount col-amount"></td>
    </tr>
</template>

<!-- Template: Multiple Links Error Container -->
<template id="tpl-advanced-multiple-links">
    <div class="info" style="margin-top: 15px;">
        <span class="fa fa-exclamation-triangle" style="color: orange; font-size: 1.5em; vertical-align: middle; margin-right: 10px;"></span>
        <span style="vertical-align: middle;"><?php print $langs->transnoentities("ErrorMultipleLinks", "<strong>" . $langs->trans("MultipleLinks") . "</strong>"); ?></span>
    </div>
</template>

<!-- Template: Pending Documents Matching Container -->
<template id="tpl-advanced-pending">
    <div style="margin-top: 20px;">
        <div class="titre fiche_titre" style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 10px;">
            <div class="titre" style="display: flex; align-items: center; gap: 8px;">
                <span class="fas fa-file-invoice pictofixedwidth" style="font-size: 1.3em;"></span>
                <span class="fiche_titre_text"><?php print $langs->trans("PendingDocuments"); ?></span>
            </div>
            <div style="display: inline-flex; align-items: center; font-weight: normal;">
                <span class="fa fa-search opacitymedium" style="margin-right: 8px; font-size: 0.9em;"></span>
                <input type="text" id="advanced_search_input" class="flat" placeholder="<?php print dol_escape_htmltag($langs->trans('Search') . ' (' . $langs->trans('Ref') . ', ' . $langs->trans('ThirdParty') . '...)'); ?>" style="width: 250px; padding: 4px 6px;">
            </div>
        </div>

        <form id="form_advanced_matching" method="post" action="">
            <input type="hidden" name="token" value="">
            <input type="hidden" name="action" value="process_advanced_match">
            <input type="hidden" name="qonto_id" value="">
            <input type="hidden" name="bank_rowid" value="">
            <input type="hidden" id="qonto_trx_amount" value="">

            <div class="div-table-responsive" style="margin-bottom: 60px;">
                <table class="liste centpercent">
                    <thead>
                        <tr class="liste_titre">
                            <th class="liste_titre center" style="width: 40px;">#</th>
                            <th class="liste_titre"><?php print $langs->trans("Type"); ?></th>
                            <th class="liste_titre"><?php print $langs->trans("Date"); ?></th>
                            <th class="liste_titre"><?php print $langs->trans("Ref"); ?></th>
                            <th class="liste_titre"><?php print $langs->trans("ThirdParty"); ?></th>
                            <th class="liste_titre right"><?php print $langs->trans("RemainToPay"); ?></th>
                            <th class="liste_titre right" style="width: 140px;"><?php print $langs->trans("AmountToImpute"); ?></th>
                        </tr>
                    </thead>
                    <tbody class="pending-documents-tbody"></tbody>
                    <!-- No result line for live search -->
                    <tr id="no_search_result_row" style="display: none;">
                        <td colspan="7" class="center opacitymedium"><?php print $langs->trans("NoCorrespondenceFound"); ?></td>
                    </tr>
                </table>
            </div>

            <div class="qontosync-modal-footer action-buttons">
                <input type="submit" class="button button-save qontosync-btn-full" value="<?php print $langs->trans("Validate"); ?>">
            </div>

            <!-- Internal Loader during submission -->
            <div id="advanced_match_loader" class="opacitymedium" style="display: none; text-align: center; padding: 15px;">
                <i class="fas fa-spinner fa-pulse fa-2x"></i>
            </div>
        </form>
    </div>
</template>

<!-- Template: Pending Document Row -->
<template id="tpl-advanced-pending-row">
    <tr class="oddeven doc_row">
        <td class="center">
            <input type="checkbox" class="flat check_link_doc" name="link_doc[]" value="">
        </td>
        <td class="col-doctype">
            <span class="doctype-text"></span>
            <span class="badge badge-info badge-credit-note" style="display:none;"></span>
        </td>
        <td class="col-date"></td>
        <td class="col-ref">
            <span class="ref-label"></span>
            <span class="ref-supplier opacitymedium small" style="display:none;"></span>
        </td>
        <td class="col-thirdparty"></td>
        <td class="right amount col-remain"></td>
        <td class="right">
            <input type="text" class="flat right maxwidth75 amount_input" name="" value="" placeholder="0.00" disabled>
        </td>
    </tr>
</template>

<!-- Template: No Pending Documents Row -->
<template id="tpl-advanced-no-docs">
    <tr>
        <td colspan="7" class="center opacitymedium"><?php print $langs->trans("NoPendingDocumentsFound"); ?></td>
    </tr>
</template>