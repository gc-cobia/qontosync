<div style="display: flex; flex-direction: column; height: calc(100vh - 160px); gap: 15px;">
    
    <form id="qontosync_search_form" method="post" style="margin: 0; flex-shrink: 0;">
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
    
    	        <!-- JavaScript filter - Toggle switch style -->
    	        <style>
            		.qonto-switch { position: relative; display: inline-block; width: 34px; height: 20px; margin-right: 8px; vertical-align: middle; }
            		.qonto-switch input { opacity: 0; width: 0; height: 0; }
            		.qonto-slider { position: absolute; cursor: pointer; top: 0; left: 0; right: 0; bottom: 0; background-color: #ccc; transition: .3s; border-radius: 20px; }
            		.qonto-slider:before { position: absolute; content: ""; height: 16px; width: 16px; left: 2px; bottom: 2px; background-color: white; transition: .3s; border-radius: 50%; box-shadow: 0 1px 3px rgba(0,0,0,0.3); }
            		.qonto-switch input:checked + .qonto-slider { background-color: #2196F3; } 
            		.qonto-switch input:checked + .qonto-slider:before { transform: translateX(14px); }
            		.qonto-filter-wrapper { display: inline-flex; align-items: center; background: #fff; border: 1px solid #ddd; padding: 4px 12px; border-radius: 20px; font-size: 0.95em; color: #555; box-shadow: 0 1px 2px rgba(0,0,0,0.05); user-select: none; }
            		.qonto-filter-wrapper:hover { border-color: #bbb; background: #fdfdfd; }
            	</style>
    
    	        <div class="qonto-filter-wrapper">
    	            <label class="qonto-switch" style="margin-bottom: 0;">
    	                <input type="checkbox" id="filter_linked">
    	                <span class="qonto-slider"></span>
    	            </label>
    	            <span style="cursor: pointer;" onclick="document.getElementById(\'filter_linked\').click()">Masquer les opérations déjà rapprochées</span>
                </div>
    	
            </div>
    
    	    <!-- Button validate all matches in right float box -->
    	    <div style="margin-left: auto; padding-left: 15px;">
    	        <input type="submit" form="form_batch_link" class="button button-save" value="<?php print $langs->trans("Validate"); ?>">
    	    </div>
    	
    	</div>
    </form>
    
    <div id="qontosync_results_container"></div>

</div>