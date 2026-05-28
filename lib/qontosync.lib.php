<?php
/*
 * QontoSync dolibarr module 
 * @file       qontosync.lib.php
 * @author     Gaëtan COURTOIS
 * @license    GPLv3
 */

dol_include_once('/core/class/html.form.class.php');

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

		// Submit Button
		print '<input type="submit" class="button" value="' . $langs->trans("Search") . '">';

		print '</td>';
		print '</tr>';
		print '</table>';
		print '</form>';
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

		print '<br><table class="noborder centpercent">';
		print '<tr class="liste_titre">';
		print '<td>'.$langs->trans('Date').'</td>';
		print '<td>'.$langs->trans('Label').'</td>';
		print '<td>'.$langs->trans('Amount').'</td>';
		print '<td>'.$langs->trans('Correspondence').'</td>';
		print '<td>'.$langs->trans('Action').'</td>';
		print '</tr>';
		
		foreach ($transactions as $t) {			
			$q_id = dol_escape_htmltag($t['id']);
			$q_date = date('Y-m-d', strtotime(dol_escape_htmltag($t['settled_at'])));
			$q_amount = dol_escape_htmltag($t['amount']);
			$q_label = '<strong>' . dol_escape_htmltag($t['label']) . '</strong>' . ($t['reference'] ? ' <span class="badge badge-info badge-inline">' . dol_escape_htmltag($t['reference']) . '</span>' : '');
			
			// Style for badge
			$fixed_width = "100%";
			$style_badge = "width: {$fixed_width}; display: inline-block; text-align: center; box-sizing: border-box; padding: 6px; white-space: normal;";
			$style_select = "width: {$fixed_width}; max-width: {$fixed_width}; box-sizing: border-box;";

			print '<tr class="oddeven">';
			print '<td>' . dol_print_date(strtotime($q_date), 'day') . '</td>';
			print '<td>' . $q_label . ' <br><code class="opacitymedium small" style="font-size:0.8em;">ID: '.$q_id.'</code></td>';

			print '<td class="right">' . price($q_amount) . ' ' . $t['currency'] . '</td>';
			
			if (!empty($t['trx_status']) && $t['trx_status'] == 'errorTooManyTrx') {
				// Application du style au badge d'erreur
				print '<td><span class="badge badge-danger" style="'.$style_badge.'">'. $langs->trans('ErrorTooManyTrx') .'</span></td>';
				print '<td></td>';
			} elseif (!empty($t['linked_rowid'])) {
				// Application du style au badge de succès
				print '<td><span class="badge badge-success" style="'.$style_badge.'">'. $langs->trans('Linked') .' ( # ' . $t['linked_rowid'] . ' )</span></td>';
				print '<td></td>';
			} else {
				// Check if there is anything to link
				$has_bank_entries = (!empty($t['trx']) && is_array($t['trx']));
				$has_c_invoices = (!empty($t['c_invoices']) && is_array($t['c_invoices']));
				$has_s_invoices = (!empty($t['s_invoices']) && is_array($t['s_invoices']));
				$has_vat = (!empty($t['vat']) && is_array($t['vat']));

				if ($has_bank_entries || $has_c_invoices || $has_s_invoices || $has_vat) {
					// Apply form id to be HTML5 valid
					$form_id = 'form_link_' . md5($q_id);
					
					print '<td>';
					print '<select name="target_val" form="'.$form_id.'" class="flat" style="'.$style_select.'" required>';
					print '<option value="">-- '.$langs->trans('Select').' --</option>';
					
					// Bank entries
					if ($has_bank_entries) {
						print '<optgroup label="'.$langs->trans('BankTransactions').'">';
						foreach ($t['trx'] as $de) {
							print '<option value="bank_'.$de->rowid.'">' . dol_print_date($db->jdate($de->datev), 'day') . ' - ' . $de->label . ' (' . price($de->amount) . ')</option>';
						}
						print '</optgroup>';
					}

					// Customer invoices
					if ($has_c_invoices) {
						print '<optgroup label="'.$langs->trans('CustomersInvoices').'">';
						foreach ($t['c_invoices'] as $cinv) {
							print '<option value="cinv_'.$cinv->rowid.'">' . dol_print_date($cinv->datev, 'day') . ' - ' . $cinv->label . ' (' . price($cinv->amount) . ')</option>';
						}
						print '</optgroup>';
					}

					// Supplier invoices
					if ($has_s_invoices) {
						print '<optgroup label="'.$langs->trans('SupplierInvoices').'">';
						foreach ($t['s_invoices'] as $sinv) {
							print '<option value="sinv_'.$sinv->rowid.'">' . dol_print_date($sinv->datev, 'day') . ' - ' . $sinv->label . ' (' . price($sinv->amount) . ')</option>';
						}
						print '</optgroup>';
					}

					// TVA
					if ($has_vat) {
						print '<optgroup label="'.$langs->trans('VAT').'">';
						foreach ($t['vat'] as $vat) {
							print '<option value="vat_'.$vat->rowid.'">' . dol_print_date($vat->datev, 'day') . ' - ' . $vat->label . ' (' . price($vat->amount) . ')</option>';
						}
						print '</optgroup>';
					}

					print '</select>';
					print '</td>';
					
					print '<td>';
					print '<form id="'.$form_id.'" method="post" action="'.$_SERVER["PHP_SELF"].'" style="margin: 0;">';
					print '<input type="hidden" name="token" value="'.newToken().'">';
					print '<input type="hidden" name="action" value="link">';
					print '<input type="hidden" name="bank_rowid" value="'.$bank_rowid.'">';
					print '<input type="hidden" name="month" value="'.$month.'">';
					print '<input type="hidden" name="year" value="'.$year.'">';
					print '<input type="hidden" name="qonto_id" value="'.$q_id.'">';
					
					print '<input type="submit" class="button button-edit" value="'.$langs->trans('Validate').'">';
					print '</form>';
					print '</td>';
					
				} else {
					// Nothing found
					print '<td>';
					print '<span class="badge badge-warning" style="'.$style_badge.'">'.$langs->trans('NoCorrespondenceFound').'</span>';
					print '</td>';
					print '<td></td>';
				}
			}
			
			print '</tr>';
		}
		
		print '</table>';
	}
}