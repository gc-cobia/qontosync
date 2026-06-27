<?php
/*
 * QontoSync dolibarr module 
 * @file       core/tpl/results_statements.tpl.php
 * @author     Gaëtan COURTOIS
 * @license    GPLv3
 */

if (!defined('DOL_DOCUMENT_ROOT')) {
    exit('Access Forbidden');
}

?>

<div class="div-table-responsive">
    <form id="form_batch_link" method="post" action="">
        <input type="hidden" name="token" value="<?php print newToken(); ?>">
        <input type="hidden" name="action" value="sync_ged">
        <input type="hidden" name="bank_rowid" value="<?php print dol_escape_htmltag($bank_rowid); ?>">
        <input type="hidden" name="year" value="<?php print dol_escape_htmltag($year); ?>">

        <table class="liste centpercent">
            <tr class="liste_titre">
                <th><?php print $langs->trans("Month"); ?></th>
                <th class="right">Dolibarr<br><span class="small"><?php print $langs->trans("InitialBankBalance"); ?></span></th>
                <th class="right">Qonto<br><span class="small"><?php print $langs->trans("InitialBankBalance"); ?></span></th>
                <th class="right"><?php print $langs->trans("Delta"); ?></th>
                <th class="right">Dolibarr<br><span class="small"><?php print $langs->trans("EndBankBalance"); ?></span></th>
                <th class="right">Qonto<br><span class="small"><?php print $langs->trans("EndBankBalance"); ?></span></th>
                <th class="right"><?php print $langs->trans("Delta"); ?></th>
                <th class="center"><?php print $langs->trans("AccountStatements"); ?></th>
            </tr>

            <?php foreach ($months_data as $m => $data): ?>
                <?php 
                    $is_future = ($year > date('Y') || ($year == date('Y') && $m > date('n')));

                    $diff_open = price2num($data['qonto_opening'] - $data['dolibarr_opening'], 'MT');
                    $diff_close = price2num($data['qonto_closing'] - $data['dolibarr_closing'], 'MT');
                    
                    $color_open = ($diff_open == 0) ? 'var(--amountpaymentcomplete)' : 'var(--amountremaintopaycolor)';
                    $color_close = ($diff_close == 0) ? 'var(--amountpaymentcomplete)' : 'var(--amountremaintopaycolor)';
                ?>
                <tr class="oddeven">
                    <td><span class="ucfirst"><?php print dol_escape_htmltag($data['label']); ?></span></td>

                    <?php if ($is_future): ?>
                    <td class="right">-</td>
                    <td class="right">-</td>
                    <td class="right">-</td>
                    
                    <td class="right">-</td>
                    <td class="right">-</td>
                    <td class="right">-</td>

                    <td class="center">-</td>

                    <?php else: ?>
                    <td class="right"><?php print price($data['dolibarr_opening']); ?></td>
                    <td class="right"><?php print price($data['qonto_opening']); ?></td>
                    <td class="right" style="color: <?php print $color_open; ?>; font-weight:bold;">
                        <?php print price($diff_open); ?>
                    </td>
                    
                    <td class="right"><?php print price($data['dolibarr_closing']); ?></td>
                    <td class="right"><?php print price($data['qonto_closing']); ?></td>
                    <td class="right" style="color: <?php print $color_close; ?>; font-weight:bold;">
                        <?php print price($diff_close); ?>
                    </td>

                    <td class="center">
                        <?php if ($data['in_dolibarr']): ?>
                            <a href="<?php print dol_escape_htmltag($data['dolibarr_url']); ?>" target="_blank" class="badge badge-success" style="color: white; text-decoration: none; padding: 6px;">
                                <i class="fas fa-file-pdf marginrightonly"></i><?php print $langs->trans("OpenVerb"); ?>
                            </a>
                            <?php elseif ($data['in_qonto']): ?>
                                <span class="badge badge-warning qonto-sync-available" data-url="<?php print dol_escape_htmltag($data['dolibarr_url']); ?>" data-open-text="<?php print dol_escape_htmltag($langs->trans("OpenVerb")); ?>">
                                    <i class="fas fa-sync marginrightonly"></i><?php print $langs->trans("Available"); ?>
                                </span>
                            <?php else: ?>
                            <span class="opacitymedium small"><?php print $langs->trans("NotAvailable"); ?></span>
                        <?php endif; ?>
                    </td>
                    <?php endif; ?>
                </tr>
            <?php endforeach; ?>

        </table>
    </form>
</div>