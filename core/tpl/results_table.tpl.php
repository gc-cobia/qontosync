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

<div style="flex: 1 1 auto; overflow-y: auto; background: var(--colorbackbody, #fff);">
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
                            <span class="badge badge-success" style="<?php print $style_badge; ?>">
                                <?php print $langs->trans('Linked'); ?> ( # <?php print $t['linked_rowid']; ?> )
                            </span>
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
            
                                <select name="target_val[<?php print $q_id; ?>]" id="<?php print 'form_select_' . md5($q_id); ?>" form="form_batch_link" class="flat" style="<?php print $style_select; ?>">
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
                                                        <?php print dol_print_date($sinv->datev, 'day'); ?> - <?php print dol_escape_htmltag($sinv->label); ?> (<?php print $amt_display; ?>) - <?php print dol_escape_htmltag($cinv->thirdparty); ?> (<?php print dol_escape_htmltag($sinv->ref_supplier); ?>)
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
                        <!-- buton to access modal box -->
                        <?php
                        $btn_html = '<span class="button" style="padding: 0; margin: 0; width: 28px; height: 28px; display: flex; align-items: center; justify-content: center; min-width: unset; border-radius: 50%;"><span class="fa fa-search"></span></span>';
                        	
                        // Generate dynamic URL
                        $url_modal = dol_buildpath('/qontosync/advanced_match.php', 1) . '?qonto_id=' . urlencode($q_id) . '&bank_rowid=' . $bank_rowid . '&token=' . newToken();
                        
                        print dolButtonToOpenUrlInDialogPopup(
                            'btn_adv_match_' . md5($q_id),
                            $langs->transnoentities("AdvancedSearch"),
                            $btn_html,
                            $url_modal,
                            '',
                            '',
                            
                        );
                        ?>
                    </td>
                </tr>
            <?php endforeach; ?>
        </table>
    </form>
</div>