<?php
/*
 * QontoSync dolibarr module 
 * @file       core/tpl/results_table.tpl.php
 * @author     Gaëtan COURTOIS
 * @license    GPLv3
 */
 
if (!defined('DOL_DOCUMENT_ROOT')) {
    exit('Access Forbidden');
}

// Style for badges and selects
$fixed_width = "100%";
$style_badge = "width: {$fixed_width}; display: inline-block; text-align: center; box-sizing: border-box; padding: 6px; white-space: normal;";
$style_select = "width: {$fixed_width}; max-width: {$fixed_width}; box-sizing: border-box;";

print dol_htmloutput_events();
?>

<style>
<?php if (!empty(getDolGlobalString('QONTOSYNC_BADGE_COLOR'))): ?>
@media (prefers-color-scheme: light) {
    #qontosync_results_container .qonto-badge-info,
    #qontosync_advanced_wrapper .qonto-badge-info {
        background-color: <?php print getDolGlobalString('QONTOSYNC_BADGE_COLOR'); ?> !important;
        border-color: <?php print getDolGlobalString('QONTOSYNC_BADGE_COLOR'); ?> !important;
    }
}
<?php endif; ?>

<?php if (!empty(getDolGlobalString('QONTOSYNC_BADGE_COLOR_DARK'))): ?>
@media (prefers-color-scheme: dark) {
    #qontosync_results_container .qonto-badge-info,
    #qontosync_advanced_wrapper .qonto-badge-info {
        background-color: <?php print getDolGlobalString('QONTOSYNC_BADGE_COLOR_DARK'); ?> !important;
        border-color: <?php print getDolGlobalString('QONTOSYNC_BADGE_COLOR_DARK'); ?> !important;
    }
}
<?php endif; ?>
</style>

<div style="background: var(--colorbackbody, #fff); padding-bottom: 120px;">
    <form id="form_batch_link" method="post" action="<?php print dol_escape_htmltag($_SERVER["PHP_SELF"]); ?>" style="margin: 0;">
        <input type="hidden" name="token" value="<?php print newToken(); ?>">
        <input type="hidden" name="action" value="batch_link">
        <input type="hidden" name="bank_rowid" value="<?php print $bank_rowid; ?>">
        
        <table class="noborder centpercent" style="margin: 0;">
            <tr class="liste_titre">
                <td style="width: 1%; white-space: nowrap;"><?php print $langs->trans('Date'); ?></td>
                <td><?php print $langs->trans('Label'); ?></td>
                <td><?php print $langs->trans('Amount'); ?></td>
                <td><?php print $langs->trans('Correspondence'); ?></td>
	            <td style="width: 1%; white-space: nowrap;">&nbsp;</td>
            </tr>
 
            <!-- For each transaction -->
            <?php foreach ($transactions as $t):
                 $q_id = dol_escape_htmltag($t['id']);
                 
                 $row_style = (getDolGlobalString('QONTOSYNC_HIDE_LINKED') && !empty($t['linked_rowid'])) ? 'style="display: none;"' : '';
                ?>
                <tr class="oddeven <?php print !empty($t['linked_rowid']) ? 'trx-linked' : 'trx-unlinked'; ?>" <?php print $row_style; ?>>
                    <td><?php print dol_print_date(strtotime($t['settled_at']), 'day'); ?></td>
                    <td>
                        <strong><?php print dol_escape_htmltag($t['label']); ?></strong>
                        <?php if (!empty($t['reference'])) : ?>
                        <span class="badge qonto-badge-info badge-inline"><?php print dol_escape_htmltag($t['reference']); ?></span>
                        <?php endif; ?>
                        <br>
                        <code class="opacitymedium small" style="font-size:0.8em;">ID: <?php print $q_id; ?></code>
                    </td>
                    <td class="right">
                       <span class="amount" style="color: <?php print ($t['amount'] < 0) ? 'var(--amountremaintopaycolor)' : 'var(--amountpaymentcomplete)'; ?>;">
                            <?php print price($t['amount']); ?> <?php print dol_escape_htmltag($t['currency']); ?>
                       </span>
                    </td>
                    
                    <!-- if multiple bank lines found linked with this Qonto ID -->
                    <?php if (!empty($t['trx_status']) && $t['trx_status'] == 'errorTooManyTrx'): ?>
                        <td>
                            <span class="badge badge-danger" style="<?php print $style_badge; ?>">
                                <?php print $langs->trans('ErrorTooManyTrx'); ?>
                            </span>
                        </td>
                    
                    <!-- if one bank line found linked with this Qonto ID -->
                    <?php elseif (!empty($t['linked_rowid'])): ?>
                        <td>
                            <a href="<?php print DOL_URL_ROOT . '/compta/bank/line.php?rowid=' . $t['linked_rowid']; ?>" target="_blank" class="badge badge-success" style="<?php print $style_badge; ?>" title="<?php print $langs->trans('BankTransaction'); ?>">
                                <?php print $langs->trans('Linked'); ?> ( # <?php print $t['linked_rowid']; ?> )
                            </a>
                        </td>
                    
                    <!-- else print what can be linked -->
                    <?php else: 
                        // Check if there are any matching entries
                        $has_bank_entries = (!empty($t['trx']) && is_array($t['trx']));
                        $has_c_invoices   = (!empty($t['c_invoices']) && is_array($t['c_invoices']));
                        $has_s_invoices   = (!empty($t['s_invoices']) && is_array($t['s_invoices']));
                        $has_vat          = (!empty($t['vat']) && is_array($t['vat']));
                        $has_social       = (!empty($t['social']) && is_array($t['social']));
                        $has_salary       = (!empty($t['salary']) && is_array($t['salary']));
                        $has_expense      = (!empty($t['expense']) && is_array($t['expense']));
                        ?>

                        <td style="width: 350px; min-width: 350px; max-width: 350px;">
                            <!-- print select list with entries found -->
                            <?php if ($has_bank_entries || $has_c_invoices || $has_s_invoices || $has_vat || $has_social || $has_salary || $has_expense): ?>
            
                                <select name="target_val[<?php print $q_id; ?>]" id="<?php print 'form_select_' . md5($q_id); ?>" class="flat" style="<?php print $style_select; ?>">
                                    <option value="">&nbsp;</option>

                                        <!-- insert bank entries -->
                                        <?php if ($has_bank_entries): ?>
                                            <optgroup label="<?php print $langs->trans('BankTransactions'); ?>">
                                                 
                                                <?php foreach ($t['trx'] as $de):
                                                    // Labdel transalation
                                                    $trans_label = $de->label;
                                                    if (preg_match('/^\(([^)]+)\)(.*)$/i', $trans_label, $reg)) {
                                                        $trans_label = '(' . $langs->trans($reg[1]) . ')' . $reg[2];
                                                    } else {
                                                        $trans_label = $langs->trans($trans_label);
                                                    } ?>
                                                    
                                                    <option value="bank_<?php print $de->rowid; ?>">
                                                        <?php print dol_print_date($db->jdate($de->datev), 'day'); ?> - <?php print dol_escape_htmltag($trans_label); ?> (<?php print price($de->amount); ?>)
                                                    </option>
                                                <?php endforeach; ?>
                                                
                                            </optgroup>
                                        <?php endif; ?>

                                        <!-- insert customers invoices -->
                                        <?php if ($has_c_invoices): ?>
                                            <optgroup label="<?php print $langs->trans('CustomersInvoices'); ?>">
                                                
                                                <?php foreach ($t['c_invoices'] as $cinv): ?>
                                                    <?php
                                                    $amt_display = price($cinv->amount) . ' ' . $conf->currency;
                                                    if (!empty($cinv->mc_code) && $cinv->mc_code != $conf->currency) {
                                                        $amt_display = price($cinv->mc_amount) . ' ' . $cinv->mc_code . ' (' . $amt_display . ')';
                                                    }
                                                    ?>
                                                    <option value="cinv_<?php print $cinv->rowid; ?>">
                                                        <?php print dol_print_date($cinv->datev, 'day'); ?> - <?php print dol_escape_htmltag($cinv->label); ?> (<?php print $amt_display; ?>) - <?php print dol_escape_htmltag($cinv->thirdparty); ?>
                                                    </option>
                                                <?php endforeach; ?>
                                                
                                            </optgroup>
                                        <?php endif; ?>

                                        <!-- insert suppliers invoices -->
                                        <?php if ($has_s_invoices): ?>
                                            <optgroup label="<?php print $langs->trans('SupplierInvoices'); ?>">

                                                <?php foreach ($t['s_invoices'] as $sinv): ?>
                                                    <?php
                                                    $amt_display = price($sinv->amount) . ' ' . $conf->currency;
                                                    if (!empty($sinv->mc_code) && $sinv->mc_code != $conf->currency) {
                                                        $amt_display = price($sinv->mc_amount) . ' ' . $sinv->mc_code . ' (' . $amt_display . ')';
                                                    }
                                                    ?>
                                                    <option value="sinv_<?php print $sinv->rowid; ?>">
                                                        <?php print dol_print_date($sinv->datev, 'day'); ?> - <?php print dol_escape_htmltag($sinv->label); ?> (<?php print $amt_display; ?>) - <?php print dol_escape_htmltag($sinv->thirdparty); ?> (<?php print dol_escape_htmltag($sinv->ref_supplier); ?>)
                                                    </option>
                                                <?php endforeach; ?>
                                                
                                            </optgroup>
                                        <?php endif; ?>

                                        <!-- insert vat documents-->
                                        <?php if ($has_vat): ?>
                                            <optgroup label="<?php print $langs->trans('VAT'); ?>">
                                                
                                                <?php foreach ($t['vat'] as $vat): ?>
                                                    <option value="vat_<?php print $vat->rowid; ?>">
                                                        <?php print dol_print_date($vat->datev, 'day'); ?> - <?php print dol_escape_htmltag($vat->label); ?> (<?php print price($vat->amount); ?>)
                                                    </option>
                                                <?php endforeach; ?>
                                                
                                            </optgroup>
                                        <?php endif; ?>

                                        <!-- insert social charges -->
                                        <?php if ($has_social): ?>
                                            <optgroup label="<?php print $langs->trans('SocialContributions'); ?>">
                                                <?php foreach ($t['social'] as $soc): ?>
                                                    <option value="social_<?php print $soc->rowid; ?>">
                                                        <?php print dol_print_date($soc->datev, 'day'); ?> - <?php print dol_escape_htmltag($soc->label); ?> (<?php print price($soc->amount); ?>)
                                                    </option>
                                                <?php endforeach; ?>
                                            </optgroup>
                                        <?php endif; ?>

                                        <!-- insert salaries -->
                                        <?php if ($has_salary): ?>
                                            <optgroup label="<?php print $langs->trans('Salaries'); ?>">
                                                <?php foreach ($t['salary'] as $sal): ?>
                                                    <option value="salary_<?php print $sal->rowid; ?>">
                                                        <?php print dol_escape_htmltag($sal->label); ?> (<?php print price($sal->amount); ?>)
                                                    </option>
                                                <?php endforeach; ?>
                                            </optgroup>
                                        <?php endif; ?>

                                        <!-- insert expense reports -->
                                        <?php if ($has_expense): ?>
                                            <optgroup label="<?php print $langs->trans('ExpenseReports'); ?>">
                                                <?php foreach ($t['expense'] as $exp): ?>
                                                    <option value="expense_<?php print $exp->rowid; ?>">
                                                        <?php print dol_print_date($exp->datev, 'day'); ?> - <?php print dol_escape_htmltag($exp->label); ?> (<?php print price($exp->amount); ?>)
                                                    </option>
                                                <?php endforeach; ?>
                                            </optgroup>
                                        <?php endif; ?>
                                </select>
                                
                            <!-- if no entries found print -->
                            <?php else: ?>
                                <span class="badge badge-warning" style="<?php print $style_badge; ?>">
                                    <?php print $langs->trans('NoCorrespondenceFound'); ?>
                                </span>
                            <?php endif; ?>
                            
                        </td>
                        <?php endif; ?>
                        
                    <td class="center">
                        <div class="qonto-dropdown">
                            <button type="button" class="button" style="padding: 0; margin: 0; width: 28px; height: 28px; display: inline-flex; align-items: center; justify-content: center; min-width: unset; border-radius: 50%;" title="<?php print dol_escape_htmltag($langs->trans("Action")); ?>">
                                <span class="fas fa-bars"></span>
                            </button>
                            <div class="qonto-dropdown-content">
                                <!-- Advanced Search -->
                                <?php
                                $btn_html = '<span class="fa fa-search marginrightonly" style="margin-right: 5px;"></span> ' . $langs->transnoentities("AdvancedSearch");
                                $url_modal = dol_buildpath('/qontosync/advanced_match.php', 2) . '?qonto_id=' . urlencode($q_id) . '&bank_rowid=' . $bank_rowid . '&token=' . newToken();
                                
                                print dolButtonToOpenUrlInDialogPopup(
                                    'btn_adv_match_' . md5($q_id),
                                    $langs->transnoentities("AdvancedSearch"),
                                    $btn_html,
                                    $url_modal,
                                    '',
                                    'qonto-dropdown-item',
                                );
                                ?>

                                <!-- Unlink Transaction -->
                                <?php if (!empty($t['trx_status']) && $t['trx_status'] === 'linked'): ?>
                                <button type="button" class="btn-ask-unlink qonto-dropdown-item" data-bank-rowid="<?php print $t['linked_rowid']; ?>" title="<?php print dol_escape_htmltag($langs->trans("UnlinkTransaction")); ?>">
                                    <span class="fas fa-undo marginrightonly" style="margin-right: 5px;"></span> <?php print dol_escape_htmltag($langs->trans("UnlinkTransaction")); ?>
                                </button>
                                <?php endif; ?>

                                <!-- New various payment -->
                                <?php
                                if (!empty($t['trx_status']) && $t['trx_status'] === 'unlinked'):
                                    $way = ($t['amount'] < 0) ? 0 : 1; 
                                    $amount = abs($t['amount']);                            
                                    $label = $t['label'];
                                    if (!empty($t['reference'])) {
                                        $label .= ' - ' . $t['reference'];
                                    }
                                    
                                    $mop = $qontoMatching->getPaymentModeId(
                                        isset($t['operation_type']) ? $t['operation_type'] : '', 
                                        isset($t['side']) ? $t['side'] : ''
                                    );
                                    $payment_code = '';
                                    if (!empty($mop)) {
                                        $payment_code = $langs->getLabelFromKey($db, $mop, 'c_paiement', 'id', 'code');
                                    }

                                    $dateo = isset($t['emitted_at']) ? strtotime($t['emitted_at']) : strtotime($t['settled_at']);
                                    $datev = strtotime($t['settled_at']);

                                    $back_url = dol_buildpath('/qontosync/reconciliation.php', 1) . '?bank_rowid=' . $bank_rowid;
                                    if (isset($month)) $back_url .= '&month=' . $month;
                                    if (isset($year)) $back_url .= '&year=' . $year;
                                    
                                    $various_url = dol_buildpath('/compta/bank/various_payment/card.php', 1) . '?action=add';
                                    $various_url .= '&token=' . newToken();
                                    $various_url .= '&accountid=' . $bank_rowid;
                                    $various_url .= '&amount=' . $amount;
                                    $various_url .= '&sens=' . $way;
                                    $various_url .= '&label=' . urlencode(dol_trunc($label, 255));
                                    $various_url .= '&num_payment=' . urlencode(!empty($t['reference']) ? $t['reference'] : $q_id);
                                    
                                    if (!empty($payment_code)) {
                                        $various_url .= '&paymenttype=' . urlencode($payment_code);
                                    }

                                    $various_url .= '&datepday=' . date('d', $dateo) . '&datepmonth=' . date('m', $dateo) . '&datepyear=' . date('Y', $dateo);
                                    $various_url .= '&datevday=' . date('d', $datev) . '&datevmonth=' . date('m', $datev) . '&datevyear=' . date('Y', $datev);
                                    
                                    $various_url .= '&backtopage=' . urlencode($back_url);
                                ?>
                                <button type="button" class="btn-ask-create-od qonto-dropdown-item" data-url="<?php print $various_url; ?>" title="<?php print dol_escape_htmltag($langs->trans("NewVariousPayment")); ?>">
                                    <span class="fas fa-plus marginrightonly" style="margin-right: 5px;"></span> <?php print dol_escape_htmltag($langs->trans("NewVariousPayment")); ?>
                                </button>
                                <?php endif; ?>
                            </div>
                        </div>
                    </td>
                </tr>
            <?php endforeach; ?>
        </table>
    </form>

    <!-- Confirmation modal for new various payment -->
    <div id="dialog-confirm-create-od" 
         title="<?php print dol_escape_htmltag($langs->trans("NewVariousPayment")); ?>" 
         data-yes="<?php print dol_escape_htmltag($langs->transnoentities("Yes")); ?>" 
         data-no="<?php print dol_escape_htmltag($langs->transnoentities("No")); ?>" 
         style="display: none;">
        <p><span class="fas fa-exclamation-circle" style="float:left; margin:12px 12px 20px 0;"></span><?php print $langs->trans("ConfirmNewVariousPaymentQuestion"); ?></p>
        <?php 
        // Add accountancy selector if double entry accounting module is enabled
        if (!empty($conf->accounting->enabled)) {
            print '<div style="margin-top: 20px;">';
            print '<label for="od_accountancy_code" style="font-weight: bold; margin-bottom: 5px; display: inline-block;">' . $langs->trans("AccountingAccount") . '</label><br>';
            print $accountancy_accounts_list;
            print '</div>';
        }
        ?>
    </div>

    <!-- Confirmation modal for unlinking -->
    <div id="dialog-confirm-unlink" 
         title="<?php print dol_escape_htmltag($langs->trans("UnlinkTransaction")); ?>" 
         data-yes="<?php print dol_escape_htmltag($langs->transnoentities("Yes")); ?>" 
         data-no="<?php print dol_escape_htmltag($langs->transnoentities("No")); ?>" 
         style="display: none;">
        <p><span class="fas fa-exclamation-circle" style="float:left; margin:12px 12px 20px 0;"></span><?php print $langs->trans("ConfirmUnlinkTransaction"); ?></p>
    </div>
</div>