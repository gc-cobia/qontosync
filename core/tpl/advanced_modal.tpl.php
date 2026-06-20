<?php
/*
 * QontoSync dolibarr module 
 * @file       core/tpl/advanced_modal.tpl.php
 * @author     Gaëtan COURTOIS
 * @license    GPLv3
 */
 
if (!defined('DOL_DOCUMENT_ROOT')) {
    exit('Access Forbidden');
}

?>

<div class="fiche" style="padding: 15px; display: flex; flex-direction: column; gap: 15px;">

    <?php print load_fiche_titre($langs->trans("QontoTransactionDetails"), '', 'bank'); ?>
    
    <div style="display:grid;grid-template-columns:180px 1fr;row-gap:18px;">
        <div><?php print $langs->trans("Label"); ?></div>
        <div><span class="bold"><?php print dol_escape_htmltag($processed_trx['label']); ?></span></div>
        
        <div><?php print $langs->trans("Date"); ?></div>
        <div><?php print dol_print_date(strtotime($processed_trx['settled_at']), 'day'); ?></div>
        
        <div><?php print $langs->trans("Amount"); ?></div>
        <?php $amount_color = ($processed_trx['amount'] < 0) ? 'var(--amountremaintopaycolor)' : 'var(--amountpaymentcomplete)'; ?>
        <div><span class="amount" style="color:<?php print $amount_color; ?>;"><?php print price($processed_trx['amount']); ?> <?php print dol_escape_htmltag($processed_trx['currency']); ?></span></div>
        
        <div><?php print $langs->trans("Type"); ?></div>
        <div><?php print dol_escape_htmltag($payment_label); ?></div>
        
        <?php if (!empty($processed_trx['reference'])): ?>
            <div><?php print $langs->trans("Ref"); ?></div>
            <div><?php print dol_escape_htmltag($processed_trx['reference']); ?></div>
        <?php endif; ?>
    </div>
    
    <div class="oddeven" style="margin-top:20px;padding:8px 10px;">
        <code>ID : <?php print dol_escape_htmltag($processed_trx['id']); ?></code>
    </div>

    <?php if ($linked !== 0): ?>
        
        <br>
        <div class="info">
            <?php if ($linked > 0): ?>
                <span class="fa fa-check-circle" style="color: green; font-size: 1.5em; vertical-align: middle; margin-right: 10px;"></span>
                <span style="vertical-align: middle;"><?php print $langs->trans("TransactionAlreadyLinked", "<strong>" . $langs->trans("AlreadyLinked") . "</strong>", $linked); ?></span>
            <?php else: ?>
                <span class="fa fa-exclamation-triangle" style="color: orange; font-size: 1.5em; vertical-align: middle; margin-right: 10px;"></span>
                <span style="vertical-align: middle;"><?php print $langs->trans("ErrorMultipleLinks", "<strong>" . $langs->trans("MultipleLinks") . "</strong>"); ?></span>
            <?php endif; ?>
        </div>
        
    <?php else: ?>
    
        <br>
        <?php print load_fiche_titre($langs->trans("PendingDocuments"), '', 'bill'); ?>
        <div class="clearboth"></div>
        
        <form id="form_advanced_matching" method="post" action="">
            <input type="hidden" name="token" value="<?php print newToken(); ?>">
            <input type="hidden" name="action" value="process_advanced_match">
            <input type="hidden" name="qonto_id" value="<?php print dol_escape_htmltag($processed_trx['id']); ?>">
            <input type="hidden" name="bank_rowid" value="<?php print $bank_rowid; ?>">
            <input type="hidden" id="qonto_trx_amount" value="<?php print abs($processed_trx['amount']); ?>">

            <div class="div-table-responsive">
                <table class="liste centpercent">
                    <tr class="liste_titre">
                        <th class="liste_titre center" style="width: 40px;">#</th>
                        <th class="liste_titre"><?php print $langs->trans("Type"); ?></th>
                        <th class="liste_titre"><?php print $langs->trans("Ref"); ?></th>
                        <th class="liste_titre"><?php print $langs->trans("ThirdParty"); ?></th>
                        <th class="liste_titre right"><?php print $langs->trans("RemainToPay"); ?></th>
                        <th class="liste_titre right" style="width: 140px;"><?php print $langs->trans("AmountToImpute"); ?></th>
                    </tr>

                    <?php $has_data = false; ?>

                    <?php if (!empty($filtered_documents['c_invoices'])): ?>
                        <?php $has_data = true; ?>
                        <?php foreach ($filtered_documents['c_invoices'] as $d): ?>
                            <?php $soc_name = dol_escape_htmltag($d->thirdparty); ?>
                            <tr class="oddeven doc_row">
                                <td class="center"><input type="checkbox" class="flat check_link_doc" name="link_doc[]" value="cinv_<?php print $d->rowid; ?>" data-doctype="cinv" data-thirdparty="<?php print $soc_name; ?>" data-remain="<?php print $d->amount; ?>"></td>
                                <td><?php print $langs->trans("CustomersInvoices"); ?>
                                <?php if ($d->inv_type == 2) : ?>
                                    <span class="badge badge-info""><?php print $langs->trans("CreditNote"); ?></span>
                                <?php endif; ?>
                                </td>
                                <td><?php print $d->label; ?></td>
                                <td><?php print $d->thirdparty; ?></td>
                                <td class="right amount"><?php print price($d->amount); ?></td>
                                <td class="right"><input type="text" class="flat right maxwidth75 amount_input" name="amount_cinv_<?php print $d->rowid; ?>" value="" placeholder="0.00" disabled></td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>

                    <?php if (!empty($filtered_documents['s_invoices'])): ?>
                        <?php $has_data = true; ?>
                        <?php foreach ($filtered_documents['s_invoices'] as $d): ?>
                            <?php $soc_name = dol_escape_htmltag($d->thirdparty); ?>
                            <tr class="oddeven doc_row">
                                <td class="center"><input type="checkbox" class="flat check_link_doc" name="link_doc[]" value="sinv_<?php print $d->rowid; ?>" data-doctype="sinv" data-thirdparty="<?php print $soc_name; ?>" data-remain="<?php print -$d->amount; ?>"></td>
                                <td><?php print $langs->trans("SupplierInvoices"); ?>
                                <?php if ($d->inv_type == 2) : ?>
                                    <span class="badge badge-info""><?php print $langs->trans("CreditNote"); ?></span>
                                <?php endif; ?>
                                </td>
                                <td><?php print $d->label; ?> <span class="opacitymedium small">(<?php print $d->ref_supplier; ?>)</span></td>
                                <td><?php print $d->thirdparty; ?></td>
                                <td class="right amount"><?php print price($d->amount); ?></td>
                                <td class="right"><input type="text" class="flat right maxwidth75 amount_input" name="amount_sinv_<?php print $d->rowid; ?>" value="" placeholder="0.00" disabled></td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>

                    <?php if (!empty($filtered_documents['vat'])): ?>
                        <?php $has_data = true; ?>
                        <?php foreach ($filtered_documents['vat'] as $d): ?>
                            <tr class="oddeven doc_row">
                                <td class="center"><input type="checkbox" class="flat check_link_doc" name="link_doc[]" value="vat_<?php print $d->rowid; ?>" data-doctype="vat" data-thirdparty="VAT" data-remain="<?php print -$d->amount; ?>"></td>
                                <td><?php print $langs->trans("VAT"); ?></td>
                                <td><?php print $d->label; ?></td>
                                <td>-</td>
                                <td class="right amount"><?php print price($d->amount); ?></td>
                                <td class="right"><input type="text" class="flat right maxwidth75 amount_input" name="amount_vat_<?php print $d->rowid; ?>" value="" placeholder="0.00" disabled></td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>

                    <?php if (!$has_data): ?>
                        <tr><td colspan="6" class="center opacitymedium"><?php print $langs->trans("NoPendingDocumentsFound"); ?></td></tr>
                    <?php endif; ?>
                </table>
            </div>
            
            <div class="tabsAction">
                <div class="inline-block marginleftonly marginrightonly action-buttons">
                    <input type="submit" class="button button-save" value="<?php print $langs->trans("Validate"); ?>">
                </div>
            </div>
            
        </form>

    <?php endif; ?>
</div>