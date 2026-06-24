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
                <th class="right"><?php print $langs->trans("Début Dolibarr"); ?></th>
                <th class="right"><?php print $langs->trans("Début Qonto"); ?></th>
                <th class="right"><?php print $langs->trans("Écart Début"); ?></th>
                <th class="right"><?php print $langs->trans("Fin Dolibarr"); ?></th>
                <th class="right"><?php print $langs->trans("Fin Qonto"); ?></th>
                <th class="right"><?php print $langs->trans("Écart Fin"); ?></th>
                <th class="center"><?php print $langs->trans("Relevé Officiel"); ?></th>
            </tr>

            <?php foreach ($months_data as $m => $data): ?>
                <?php 
                    $diff_open = price2num($data['qonto_opening'] - $data['dolibarr_opening'], 'MT');
                    $diff_close = price2num($data['qonto_closing'] - $data['dolibarr_closing'], 'MT');
                    
                    $color_open = ($diff_open == 0) ? 'var(--amountpaymentcomplete)' : 'var(--amountremaintopaycolor)';
                    $color_close = ($diff_close == 0) ? 'var(--amountpaymentcomplete)' : 'var(--amountremaintopaycolor)';
                ?>
                <tr class="oddeven">
                    <td><span class="ucfirst"><?php print dol_escape_htmltag($data['label']); ?></span></td>

                    <td class="right"><?php print price($data['dolibarr_opening']); ?></td>
                    <td class="right"><?php print price($data['qonto_opening']); ?></td>
                    <td class="right" style="color: <?php print $color_open; ?>; font-weight:bold;">
                        <?php print price($diff_open); ?>
                    </td>
                    
                    <td class="right opacitymedium"><?php print price($data['dolibarr_closing']); ?></td>
                    <td class="right opacitymedium"><?php print price($data['qonto_closing']); ?></td>
                    <td class="right" style="color: <?php print $color_close; ?>; font-weight:bold;">
                        <?php print price($diff_close); ?>
                    </td>

                    <td class="center">
                        <?php if ($data['in_dolibarr']): ?>
                            <a href="<?php print dol_escape_htmltag($data['dolibarr_url']); ?>" target="_blank" class="button button-a" style="padding: 4px 8px; font-size: 0.9em; border-color: #16a34a; background-color: #22c55e; color: white;">
                                <i class="fas fa-file-pdf marginrightonly"></i><?php print $langs->trans("Ouvrir"); ?>
                            </a>
                        <?php elseif ($data['in_qonto']): ?>
                            <span class="badge badge-warning" title="<?php print dol_escape_htmltag($langs->trans("Disponible sur Qonto, prêt à être synchronisé")); ?>">
                                <i class="fas fa-sync marginrightonly"></i><?php print $langs->trans("À synchroniser"); ?>
                            </span>
                        <?php else: ?>
                            <span class="opacitymedium small"><?php print $langs->trans("Non dispo"); ?></span>
                        <?php endif; ?>
                    </td>
                </tr>
            <?php endforeach; ?>

        </table>
    </form>
</div>