<?php
/*
 * QontoSync dolibarr module 
 * @file       tpl/statements.tpl.php
 * @author     Gaëtan COURTOIS
 * @license    GPLv3
 */

if (!defined('DOL_DOCUMENT_ROOT')) {
    http_response_code(403);
    exit('Access Forbidden');
}

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
    	
    	        <!-- Year Selector -->
    	        <div>
    	            <span class="marginrightonly" style="font-weight: bold;"><?php print $langs->trans("Year"); ?> :</span>
    	            <span class="marginrightonly">
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
    
    	        <!-- Submit Button for search -->
    	        <div>
    	            <input type="submit" class="button" value="<?php print $langs->trans("Search"); ?>">
    	        </div>
                
            </div>
    
    	    <!-- Button for update all statements PDF -->
            <button type="button" class="butAction" id="btn_import_statements" style="border: none; cursor: pointer; display: inline-flex; align-items: center; font-size: 1em;">
                <i class="fas fa-cloud-download-alt marginrightonly"></i><?php print $langs->trans("Import"); ?>
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

<!-- Translation dictionary for JS template rendering -->
<div id="qonto-statements-translations" style="display:none;" 
     data-open="<?php print dol_escape_htmltag($langs->trans('OpenVerb')); ?>" 
     data-available="<?php print dol_escape_htmltag($langs->trans('Available')); ?>" 
     data-not-available="<?php print dol_escape_htmltag($langs->trans('NotAvailable')); ?>">
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

<!-- Template Table Container & Batch Form -->
<template id="tpl-statements-table">
    <div class="div-table-responsive" style="background: var(--colorbackbody, #fff);">
        <form id="form_batch_link" method="post" action="">
            <input type="hidden" name="token" value="">
            <input type="hidden" name="action" value="sync_ged">
            <input type="hidden" name="bank_rowid" value="">
            <input type="hidden" name="year" value="">

            <table class="liste centpercent">
                <thead>
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
                </thead>
                <tbody class="qonto-statements-tbody"></tbody>
            </table>
        </form>
    </div>
</template>

<!-- Template Statement Row -->
<template id="tpl-statement-row">
    <tr class="oddeven">
        <td class="col-month"><span class="ucfirst col-month-text"></span></td>
        <td class="right col-doli-open"></td>
        <td class="right col-qonto-open"></td>
        <td class="right col-diff-open" style="font-weight:bold;"></td>
        <td class="right col-doli-close"></td>
        <td class="right col-qonto-close"></td>
        <td class="right col-diff-close" style="font-weight:bold;"></td>
        <td class="center col-action"></td>
    </tr>
</template>
