<?php
/*
 * QontoSync dolibarr module 
 * @file       qontosync.lib.php
 * @author     Gaëtan COURTOIS
 * @license    GPLv3
 */

dol_include_once('/core/class/html.form.class.php');
dol_include_once('/qontosync/class/qontorepository.class.php');

/**
 * Class QontoSyncLib
 * Library class containing display helper functions for QontoSync module
 */
class QontoSyncLib
{
	/**
	 * Prepare tabs head for admin page
	 *
	 * @return array
	 */
	public static function adminPrepareHead()
	{
		global $langs, $conf, $user;
		
		$langs->load("qontosync@qontosync");
		
		$h = 0;
		$head = array();
		$head[$h][0] = dol_buildpath("/qontosync/admin/setup.php", 1);
		$head[$h][1] = $langs->trans("Parameters");
		$head[$h][2] = 'settings';
		$h++;

		return $head;
	}

	/**
	 * Print the opening tag for the main flexbox layout container
	 */
	public static function printPageBegin()
	{
		print '<div style="display: flex; flex-direction: column; height: calc(100vh - 160px); gap: 15px;">';
	}

	/**
	 * Print the closing tag for the main flexbox layout container
	 */
	public static function printPageEnd()
	{
		print '</div>';
	}
	
	/**
	 * Print the opening tag for a modal container
	 */
	public static function printModalBegin()
	{
		print '<div class="fiche" style="padding: 15px; display: flex; flex-direction: column; gap: 15px;">';
	}
	
	/**
	 * Print the closing tag for a modal container
	 */
	public static function printModalEnd()
	{
		print '</div>';
	}

	/**
	 * Print the search form for the module
	 *
	 * @param int 	$month
	 * @param int 	$year
	 * @param int 	$bank_rowid
	 */
	public static function printSearchForm($month, $year, $bank_rowid)
	{
		global $langs, $db;

		$bank_rowid = $bank_rowid > 0 ? $bank_rowid : '';

		print '<div style="flex: 0 0 auto;">';

		print '<form method="post" action="'.$_SERVER["PHP_SELF"].'">';
		print '<input type="hidden" name="token" value="'.newToken().'">';
		print '<input type="hidden" name="action" value="search">';

		print '<table class="border centpercent" style="border: 1px solid var(--colborder, #ccc); border-collapse: collapse; margin-bottom: 20px;">';
		print '<tr>';
		print '<td class="valignmiddle" style="border: none; padding: 12px 15px; background: var(--backcolor, #fafafa);">';

		// Month/Year Selector
		print '<span class="marginrightonly" style="font-weight: bold; color: var(--coltext, #333);">' . $langs->trans("Month") . '/' . $langs->trans("Year") . ' :</span>';
		print '<select name="month" class="flat marginrightonly">';
		for ($i = 1; $i <= 12; $i++) {
			$selected = ($i == $month) ? ' selected="selected"' : '';
			print '<option value="'.$i.'"'.$selected.'>'.sprintf("%02d", $i).'</option>';
		}
		print '</select> ';
		print '<select name="year" class="flat marginrightonly">';
		for ($i = date('Y') - 5; $i <= date('Y'); $i++) {
			$selected = ($i == $year) ? ' selected="selected"' : '';
			print '<option value="'.$i.'"'.$selected.'>'.$i.'</option>';
		}
		print '</select>';

		print '&nbsp;&nbsp;&nbsp;&nbsp;';

		// Bank Account Selector
		$form = new Form($db);

		print '<span class="marginrightonly" style="font-weight: bold; color: var(--coltext, #333);">' . $langs->trans("BankAccount") . ' :</span>';
		print '<span class="marginrightonly">';
		print $form->select_comptes($bank_rowid, 'bank_rowid', 0, '', 2, '', 0, '', 1);
		print '</span>';

		print '&nbsp;&nbsp;&nbsp;&nbsp;';

		// Submit Button for search
		print '<input type="submit" class="button" value="' . $langs->trans("Search") . '">';

		// Button validate all matches
		print '&nbsp;&nbsp;';
		print '<input type="submit" form="form_batch_link" class="button button-save" value="' . $langs->trans("Validate") . '">';
		
		print '&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;';

		// JavaScript filter - Toggle switch style
		print '<style>
		.qonto-switch { position: relative; display: inline-block; width: 34px; height: 20px; margin-right: 8px; vertical-align: middle; }
		.qonto-switch input { opacity: 0; width: 0; height: 0; }
		.qonto-slider { position: absolute; cursor: pointer; top: 0; left: 0; right: 0; bottom: 0; background-color: #ccc; transition: .3s; border-radius: 20px; }
		.qonto-slider:before { position: absolute; content: ""; height: 16px; width: 16px; left: 2px; bottom: 2px; background-color: white; transition: .3s; border-radius: 50%; box-shadow: 0 1px 3px rgba(0,0,0,0.3); }
		.qonto-switch input:checked + .qonto-slider { background-color: #2196F3; } 
		.qonto-switch input:checked + .qonto-slider:before { transform: translateX(14px); }
		.qonto-filter-wrapper { display: inline-flex; align-items: center; background: #fff; border: 1px solid #ddd; padding: 4px 12px; border-radius: 20px; font-size: 0.95em; color: #555; box-shadow: 0 1px 2px rgba(0,0,0,0.05); user-select: none; }
		.qonto-filter-wrapper:hover { border-color: #bbb; background: #fdfdfd; }
		</style>';

		print '<div class="qonto-filter-wrapper">';
		print '  <label class="qonto-switch" style="margin-bottom: 0;">';
		print '    <input type="checkbox" id="filter_linked">';
		print '    <span class="qonto-slider"></span>';
		print '  </label>';
		print '  <span style="cursor: pointer;" onclick="document.getElementById(\'filter_linked\').click()">Masquer les opérations déjà rapprochées</span>';
		print '</div>';

		print '</td>';
		print '</tr>';
		print '</table>';
		print '</form>';
		print '</div>';
	}

	/**
	 * Print the matching results table
	 *
	 * @param array $transactions Transactions from Qonto
	 * @param DoliDB $db
	 * @param int $bank_rowid
	 * @param int $month
	 * @param int $year
	 */
	public static function printResultsTable($transactions, $db, $bank_rowid, $month, $year)
	{
		global $langs;

		print '<div style="flex: 1 1 auto; overflow-y: auto; background: var(--colorbackbody, #fff);">';
		
		print '<form id="form_batch_link" method="post" action="'.$_SERVER["PHP_SELF"].'" style="margin: 0;">';
		print '<input type="hidden" name="token" value="'.newToken().'">';
		print '<input type="hidden" name="action" value="batch_link">';
		print '<input type="hidden" name="bank_rowid" value="'.$bank_rowid.'">';
		print '<input type="hidden" name="month" value="'.$month.'">';
		print '<input type="hidden" name="year" value="'.$year.'">';

		print '<table class="noborder centpercent" style="margin: 0;">';
		print '<tr class="liste_titre">';
		print '<td style="width: 1%; white-space: nowrap;">'.$langs->trans('Date').'</td>';
		print '<td>'.$langs->trans('Label').'</td>';
		print '<td>'.$langs->trans('Amount').'</td>';
		print '<td>'.$langs->trans('Correspondence').'</td>';
		print '<td style="width: 1%; white-space: nowrap;">'.$langs->trans('Action').'</td>';
		print '</tr>';
		
		// -------------------------------------------------------------
		// OPTIMIZATION: Move static variables outside the foreach loop
		// -------------------------------------------------------------
		
		// Style for badges and selects
		$fixed_width = "100%";
		$style_badge = "width: {$fixed_width}; display: inline-block; text-align: center; box-sizing: border-box; padding: 6px; white-space: normal;";
		$style_select = "width: {$fixed_width}; max-width: {$fixed_width}; box-sizing: border-box;";
		
		// Static HTML for the advanced search modal button
		$btn_html = '<span class="button" style="padding: 0; margin: 0; width: 28px; height: 28px; display: flex; align-items: center; justify-content: center; min-width: unset; border-radius: 50%;"><span class="fa fa-search"></span></span>';
		// -------------------------------------------------------------

		foreach ($transactions as $t) {
			$q_id = dol_escape_htmltag($t['id']);
			$q_date = date('Y-m-d', strtotime(dol_escape_htmltag($t['settled_at'])));
			$q_amount = dol_escape_htmltag($t['amount']);
			$q_label = '<strong>' . dol_escape_htmltag($t['label']) . '</strong>' . ($t['reference'] ? ' <span class="badge badge-info badge-inline">' . dol_escape_htmltag($t['reference']) . '</span>' : '');
			
			// Generate dynamic URL for the modal box
			$url_modal = dol_buildpath('/qontosync/ajax/advancedBox.php', 1) . '?qonto_id=' . urlencode($q_id) . '&bank_rowid=' . $bank_rowid;
			
			// Signature : dolButtonToOpenUrlInDialogPopup($htmlname, $title, $buttonlabel, $url, $morecss='', $moreparam='')
			$advancedBox = dolButtonToOpenUrlInDialogPopup(
				'btn_adv_match_' . md5($q_id),
				$langs->trans("AdvancedSearch"),
				$btn_html,
				$url_modal,
				'',
				''
			);

			// Put a CSS class specific to the line state for the JS toggle
			$row_class = !empty($t['linked_rowid']) ? 'trx-linked' : 'trx-unlinked';

			print '<tr class="oddeven ' . $row_class . '">';
			print '<td>' . dol_print_date(strtotime($q_date), 'day') . '</td>';
			print '<td>' . $q_label . ' <br><code class="opacitymedium small" style="font-size:0.8em;">ID: '.$q_id.'</code></td>';

			// Adapt color of amount based on sign
			$amount_color = ($q_amount < 0)	? 'var(--amountremaintopaycolor)' : 'var(--amountpaymentcomplete)';
			
			print '<td class="right"><span class="amount" style="color:'.$amount_color.';">' . price($q_amount) . '</span> ' . $t['currency'] . '</td>';
			
			if (!empty($t['trx_status']) && $t['trx_status'] == 'errorTooManyTrx') {
				// Display error badge
				print '<td><span class="badge badge-danger" style="'.$style_badge.'">'. $langs->trans('ErrorTooManyTrx') .'</span></td>';
			} elseif (!empty($t['linked_rowid'])) {
				// Display success badge
				print '<td><span class="badge badge-success" style="'.$style_badge.'">'. $langs->trans('Linked') .' ( # ' . $t['linked_rowid'] . ' )</span></td>';
			} else {
				// Check if there are any matching entries
				$has_bank_entries = (!empty($t['trx']) && is_array($t['trx']));
				$has_c_invoices = (!empty($t['c_invoices']) && is_array($t['c_invoices']));
				$has_s_invoices = (!empty($t['s_invoices']) && is_array($t['s_invoices']));
				$has_vat = (!empty($t['vat']) && is_array($t['vat']));
				
				if ($has_bank_entries || $has_c_invoices || $has_s_invoices || $has_vat) {
					
					// Apply unique select id to be compatible with Select2
					$select_id = 'form_select_' . md5($q_id);
					
					print '<td>';
					print '<select name="target_val['.$q_id.']" id="'.$select_id.'" form="form_batch_link" class="flat" style="'.$style_select.'">';
					print '<option value="">-- '.$langs->trans('Select').' --</option>';
					
					// Bank entries
					if ($has_bank_entries) {
						print '<optgroup label="'.$langs->trans('BankTransactions').'">';
						foreach ($t['trx'] as $de) {
							// Try to translate the label
							$trans_label = $de->label;
							if (preg_match('/^\(([^)]+)\)(.*)$/i', $trans_label, $reg)) {
								$trans_label = '(' . $langs->trans($reg[1]) . ')' . $reg[2];
							} else {
								$trans_label = $langs->trans($trans_label);
							}
							
							print '<option value="bank_'.$de->rowid.'">' . dol_print_date($db->jdate($de->datev), 'day') . ' - ' . $trans_label . ' (' . price($de->amount) . ')</option>';
						}
						print '</optgroup>';
					}

					// Customer invoices
					if ($has_c_invoices) {
						print '<optgroup label="'.$langs->trans('CustomersInvoices').'">';
						foreach ($t['c_invoices'] as $cinv) {
							print '<option value="cinv_'.$cinv->rowid.'">' . dol_print_date($cinv->datev, 'day') . ' - ' . $cinv->label . ' (' . price($cinv->amount) . ') - ' . $cinv->thirdparty . '</option>';
						}
						print '</optgroup>';
					}

					// Supplier invoices
					if ($has_s_invoices) {
						print '<optgroup label="'.$langs->trans('SupplierInvoices').'">';
						foreach ($t['s_invoices'] as $sinv) {
							print '<option value="sinv_'.$sinv->rowid.'">' . dol_print_date($sinv->datev, 'day') . ' - ' . $sinv->label . ' (' . $sinv->ref_supplier . ') - ' . $sinv->thirdparty . '  (' . price($sinv->amount) . ') </option>';
						}
						print '</optgroup>';
					}

					// VAT
					if ($has_vat) {
						print '<optgroup label="'.$langs->trans('VAT').'">';
						foreach ($t['vat'] as $vat) {
							print '<option value="vat_'.$vat->rowid.'">' . dol_print_date($vat->datev, 'day') . ' - ' . $vat->label . ' (' . price($vat->amount) . ')</option>';
						}
						print '</optgroup>';
					}

					print '</select>';
					print '</td>';
					
					print'<script>
					$(function() {
						$("#' .  $select_id . '").select2();
					});
					</script>';
					
				} else {
					// No correspondence found
					print '<td>';
					print '<span class="badge badge-warning" style="'.$style_badge.'">'.$langs->trans('NoCorrespondenceFound').'</span>';
					print '</td>';
				}
			}
				
			// Action column with advanced search modal button
			print '<td class="center">';
			print $advancedBox;
			print '</td>';
			
			print '</tr>';
		}
		
		print '</table>';
		print '</form>';
		print '</div>';
	}
	
	/**
	 * Print the box with the Qonto transaction details
	 *
	 * @param array $trx Transaction data from Qonto API
	 */
	public static function printTransactionSummary($trx)
	{
		global $langs, $db;
		
		$t_id       = $trx['id'];
		$t_amount   = $trx['amount'];
		$t_label    = $trx['label'];
		$t_date     = $trx['settled_at'];
		$t_currency = $trx['currency'];
		$t_ref      = isset($trx['reference']) ? $trx['reference'] : '';
		
		// Load utils functions
		$qontoRepo = new QontoRepository($db);
		
		// Define operation type string for legacy code use below
		$op_type = isset($trx['operation_type']) ? $trx['operation_type'] : '';
		
		$mop = $qontoRepo->getPaymentModeId(
			$op_type, 
			isset($trx['side']) ? $trx['side'] : ''
		);
		
		$payment_label = '';
		if (!empty($mop)) {
			$payment_code = $langs->getLabelFromKey($db, $mop, 'c_paiement', 'id', 'code');
			$payment_label = $langs->trans("PaymentTypeShort" . $payment_code);
			
			// Fallback to label if translation key is missing
			if ($payment_label == "PaymentTypeShort" . $payment_code) {
				$payment_label = $langs->getLabelFromKey($db, $mop, 'c_paiement', 'id', 'libelle');
			}
		}
		
		// Adapt color of amount based on sign
		$amount_color = ($t_amount < 0)	? 'var(--amountremaintopaycolor)' : 'var(--amountpaymentcomplete)';

		// Translating the title
		print load_fiche_titre($langs->trans("QontoTransactionDetails"), '', 'bank');
		
		print '<div style="display:grid;grid-template-columns:180px 1fr;row-gap:18px;">';
		
		print '<div>'.$langs->trans("Label").'</div>';
		print '<div><span class="bold">'.dol_escape_htmltag($t_label).'</span></div>';
		
		print '<div>'.$langs->trans("Date").'</div>';
		print '<div>'.dol_print_date(strtotime($t_date), 'day').'</div>';
		
		print '<div>'.$langs->trans("Amount").'</div>';
		print '<div><span class="amount" style="color:'.$amount_color.';">'.price($t_amount).' '.dol_escape_htmltag($t_currency).'</span></div>';
		
		print '<div>'.$langs->trans("Type").'</div>';
		print '<div>'.$payment_label.'</div>';
		
		if (isset($t_ref) && !empty($t_ref)) {
			print '<div>'.$langs->trans("Ref").'</div>';
			print '<div>'.dol_escape_htmltag($t_ref).'</div>';
		}
		
		print '</div>';
		
		print '<div class="oddeven" style="margin-top:20px;padding:8px 10px;">';
		print '<code>ID : '.dol_escape_htmltag($t_id).'</code>';
		print '</div>';
	}

	/**
	 * Print unpaid documents table
	 *
	 * @param array $qonto_trx Transaction data from Qonto API
	 * @param array $documents Array containing invoices/VAT to display
	 */
	public static function printOpenDocumentsTable($qonto_trx, $documents = array())
	{
		global $langs, $conf;
		
		print '<br>';
		// Use language keys for translations
		print load_fiche_titre($langs->trans("PendingDocuments"), '', 'bill');
		print '<div class="clearboth"></div>';
		
		print '<form id="form_advanced_matching" method="post" action="'.dol_buildpath('/qontosync/index.php', 1).'">';
		print '<input type="hidden" name="token" value="'.newToken().'">';
		print '<input type="hidden" name="action" value="process_advanced_match">';
		print '<input type="hidden" name="qonto_id" value="'.dol_escape_htmltag($qonto_trx['id']).'">';
		print '<input type="hidden" name="bank_rowid" value="' . GETPOST('bank_rowid', 'int') . '">';

		print '<div class="div-table-responsive">';
		print '<table class="liste centpercent">';
		
		print '<tr class="liste_titre">';
		print '<th class="liste_titre center" style="width: 40px;"><input type="checkbox" id="checkall" title="'.$langs->trans("CheckAll").'"></th>';
		print '<th class="liste_titre">'.$langs->trans("Type").'</th>';
		print '<th class="liste_titre">'.$langs->trans("Ref").'</th>';
		print '<th class="liste_titre">'.$langs->trans("ThirdParty").'</th>';
		print '<th class="liste_titre right">'.$langs->trans("RemainToPay").'</th>';
		print '<th class="liste_titre right" style="width: 140px;">'.$langs->trans("AmountToImpute").'</th>';
		print '</tr>';

		$has_data = false;

		// 1. Customer Invoices
		if (!empty($documents['c_invoices'])) {
			$has_data = true;
			foreach ($documents['c_invoices'] as $d) {
				print '<tr class="oddeven">';
				print '<td class="center"><input type="checkbox" class="flat check_link_doc" name="link_doc[]" value="cinv_' . $d->rowid . '"></td>';
				print '<td>'.$langs->trans("CustomersInvoices").'</td>';
				print '<td>' . $d->label . '</td>';
				print '<td>' . $d->thirdparty . '</td>';
				print '<td class="right amount">' . price($d->amount) . '</td>';
				print '<td class="right"><input type="text" class="flat right maxwidth75" name="amount_cinv_' . $d->rowid . '" value="" placeholder="0.00"></td>';
				print '</tr>';
			}
		}

		// 2. Supplier Invoices
		if (!empty($documents['s_invoices'])) {
			$has_data = true;
			foreach ($documents['s_invoices'] as $d) {
				print '<tr class="oddeven">';
				print '<td class="center"><input type="checkbox" class="flat check_link_doc" name="link_doc[]" value="sinv_' . $d->rowid . '"></td>';
				print '<td>'.$langs->trans("SupplierInvoices").'</td>';
				print '<td>' . $d->label . ' <span class="opacitymedium small">(' . $d->ref_supplier . ')</span></td>';
				print '<td>' . $d->thirdparty . '</td>';
				print '<td class="right amount">' . price($d->amount) . '</td>';
				print '<td class="right"><input type="text" class="flat right maxwidth75" name="amount_sinv_' . $d->rowid . '" value="" placeholder="0.00"></td>';
				print '</tr>';
			}
		}

		// 3. VAT Declarations
		if (!empty($documents['vat'])) {
			$has_data = true;
			foreach ($documents['vat'] as $d) {
				print '<tr class="oddeven">';
				print '<td class="center"><input type="checkbox" class="flat check_link_doc" name="link_doc[]" value="vat_' . $d->rowid . '"></td>';
				print '<td>'.$langs->trans("VAT").'</td>';
				print '<td>' . $d->label . '</td>';
				print '<td>-</td>';
				print '<td class="right amount">' . price($d->amount) . '</td>';
				print '<td class="right"><input type="text" class="flat right maxwidth75" name="amount_vat_' . $d->rowid . '" value="" placeholder="0.00"></td>';
				print '</tr>';
			}
		}

		// If no documents are found
		if (!$has_data) {
			print '<tr><td colspan="6" class="center opacitymedium">'.$langs->trans("NoPendingDocumentsFound").'</td></tr>';
		}

		print '</table>';
		print '</div>';
		
		// Dolibarr action bar for modal submission
		print '<div class="tabsAction">';
		print '<div class="inline-block marginleftonly marginrightonly">';
		print '<input type="submit" class="button button-save" value="'.$langs->trans("Validate").'">';
		print '</div>';
		print '<div class="inline-block marginleftonly marginrightonly">';
		print '<input type="button" class="button button-cancel" value="'.$langs->trans("Cancel").'" onclick="$(this).closest(\'.ui-dialog-content\').dialog(\'close\');">';
		print '</div>';
		print '</div>';
		
		print '</form>';

		print '<script>
			$(document).ready(function() {
				$("#checkall").click(function() {
					$(".check_link_doc").prop("checked", $(this).prop("checked"));
				});
			});
		</script>';
	}
}