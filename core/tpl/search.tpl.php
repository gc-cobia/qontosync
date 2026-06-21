<?php
/*
 * QontoSync dolibarr module 
 * @file       core/tpl/search.tpl.php
 * @author     Gaëtan COURTOIS
 * @license    GPLv3
 */

if (!defined('DOL_DOCUMENT_ROOT')) {
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
    	
    	        <!--  Month/Year Selector -->
    	        <div>
    	            <span class="marginrightonly" style="font-weight: bold;"><?php print $langs->trans("Month"); ?> / <?php print $langs->trans("Year"); ?> :</span>
    	            <select name="month" class="flat marginrightonly">
    	                <?php for ($i = 1; $i <= 12; $i++): ?>
    	                	<option value="<?php print $i; ?>" <?php if ($i == $month) print 'selected="selected"'; ?>>
    	                        <?php print sprintf("%02d", $i); ?>
    	                    </option>
    	                <?php endfor; ?>
    	            </select> 
    	            
    	            <select name="year" class="flat marginrightonly">
    	                <?php for ($i = date('Y') - 5; $i <= date('Y'); $i++): ?>
                            <option value="<?php print $i; ?>" <?php if ($i == $year) print 'selected="selected"'; ?>>
                                <?php print $i; ?>
                            </option>
                        <?php endfor; ?>
    	            </select>
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
                <div class="inline-block valignmiddle marginleftonly qontosync-toggle-wrapper">
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
    	    <div style="margin-left: auto; padding-left: 15px;">
    	        <input type="submit" form="form_batch_link" class="button button-save" value="<?php print $langs->trans("Validate"); ?>">
    	    </div>
    	
    	</div>
    </form>

    
    <!-- Container for AJAX view -->
	<div id="qontosync_results_container">
		<div style="text-align:center; padding:50px;" class="opacitymedium">
			<i class="fa fa-info-circle fa-2x" style="margin-bottom: 10px;"></i><br>
			<?php print $langs->trans("QontoSyncInitialMessage"); ?>
		</div>
	</div>
</div>