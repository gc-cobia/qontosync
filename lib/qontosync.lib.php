<?php

/**
 * Fonctions d'affichage pour le module QontoSync
 */

/**
 * Affiche le formulaire de recherche (Mois, Année, Compte Bancaire)
 *
 * @param int $selected_month Mois sélectionné
 * @param int $selected_year  Année sélectionnée
 * @param int $selected_bank  ID du compte bancaire Dolibarr
 * @return void
 */
function qontosync_print_search_form($selected_month, $selected_year, $selected_bank)
{
	global $db, $langs, $form;

	if (!is_object($form)) {
		require_once DOL_DOCUMENT_ROOT . '/core/class/html.form.class.php';
		$form = new Form($db);
	}

	print '<form method="get" action="' . $_SERVER["PHP_SELF"] . '">';
	print '<input type="hidden" name="token" value="' . newToken() . '">';
	print '<input type="hidden" name="action" value="search">';

	print '<div class="tagtable centpercent">';
	print '<div class="tagtr">';

	// Sélecteur de banque
	print '<div class="tagtd paddingright">';
	print $langs->trans("Bank") . ' : ';
	print $form->select_comptes($selected_bank, 'bank_id', 0, '', 1);
	print '</div>';

	// Sélecteur de mois
	print '<div class="tagtd paddingright">';
	print $langs->trans("QontoSyncMonth") . ' : ';
	print $form->select_month($selected_month, 'month', 1);
	print '</div>';

	// Sélecteur d'année
	print '<div class="tagtd paddingright">';
	print $langs->trans("QontoSyncYear") . ' : ';
	print $form->select_year($selected_year, 'year', 1);
	print '</div>';

	// Bouton de recherche
	print '<div class="tagtd">';
	print '<input type="submit" class="button" value="' . $langs->trans("QontoSyncSearch") . '">';
	print '</div>';

	print '</div>'; // Fin tagtr
	print '</div>'; // Fin tagtable
	print '</form>';
}

/**
 * Affiche le tableau des transactions Qonto avec matching Dolibarr
 *
 * @param array $transactions Liste des transactions renvoyées par l'API
 * @param int   $bank_id      ID du compte bancaire Dolibarr pour le matching
 * @return void
 */
function qontosync_print_results_table($transactions, $bank_id)
{
	global $db, $langs, $form;

	print '<div class="div-table-responsive">';
	print '<table class="noborder centpercent">';
	print '<tr class="liste_titre">';
	print '<td>' . $langs->trans("Date") . '</td>';
	print '<td>' . $langs->trans("Label") . ' / ' . $langs->trans("Reference") . '</td>';
	print '<td class="right">' . $langs->trans("Amount") . '</td>';
	print '<td>' . $langs->trans("DolibarrTransaction") . '</td>';
	print '<td class="center">' . $langs->trans("Action") . '</td>';
	print '</tr>';

	foreach ($transactions as $qonto_tx) {
		$amount = $qonto_tx['amount_cents'] / 100;
		$qonto_id = $qonto_tx['id'];
		$settled_at = substr($qonto_tx['settled_at'], 0, 10);

		print '<tr class="oddeven">';
		
		// Date
		print '<td>' . dol_print_date($db->jdate($settled_at), 'day') . '</td>';

		// Libellé et ID Qonto
		print '<td>';
		print '<strong>' . dol_escape_htmltag($qonto_tx['label']) . '</strong>';
		if (!empty($qonto_tx['reference'])) {
			print ' <span class="badge badge-secondary">' . dol_escape_htmltag($qonto_tx['reference']) . '</span>';
		}
		print '<br><small class="opacitymedium">ID: ' . $qonto_id . '</small>';
		print '</td>';

		// Montant
		print '<td class="right">' . price($amount) . '</td>';

		// Matching Dolibarr
		print '<td>';
		
		// 1. On vérifie si c'est déjà lié
		$already_linked_id = qontosync_is_linked($qonto_id);
		
		if ($already_linked_id > 0) {
			print '<span class="badge badge-success">' . $langs->trans("AlreadyLinked") . ' (ID:' . $already_linked_id . ')</span>';
		} else {
			// 2. Sinon, on cherche les écritures avec le montant exact
			$matches = qontosync_get_matching_bank_entries($amount, $bank_id);
			
			if (empty($matches)) {
				print '<span class="opacitymedium">' . $langs->trans("NoExactAmountMatch") . '</span>';
			} else {
				print '<select name="link_to_' . $qonto_id . '" class="flat">';
				print '<option value="0">-- ' . $langs->trans("SelectTransaction") . ' --</option>';
				foreach ($matches as $m) {
					$label = dol_print_date($m->datev, 'day') . " - " . dol_trunc($m->label, 30);
					print '<option value="' . $m->rowid . '">' . $label . '</option>';
				}
				print '</select>';
			}
		}
		print '</td>';

		// Bouton Action
		print '<td class="center">';
		if (!$already_linked_id && !empty($matches)) {
			print '<button class="button" onclick="alert(\'Action de liaison à coder\')">' . $langs->trans("QontoSyncLink") . '</button>';
		}
		print '</td>';

		print '</tr>';
	}

	print '</table>';
	print '</div>';
}

/**
 * Cherche les écritures bancaires Dolibarr correspondant au montant exact
 */
function qontosync_get_matching_bank_entries($amount, $bank_id)
{
	global $db;
	$res = array();

	$sql = "SELECT b.rowid, b.datev, b.label";
	$sql .= " FROM " . MAIN_DB_PREFIX . "bank as b";
	$sql .= " WHERE b.amount = " . (float) $amount;
	$sql .= " AND b.fk_account = " . (int) $bank_id;
	// On exclut les écritures déjà liées à un ID Qonto
	$sql .= " AND b.rowid NOT IN (SELECT fk_object FROM " . MAIN_DB_PREFIX . "bank_extrafields WHERE qonto_id IS NOT NULL AND qonto_id != '')";
	$sql .= " ORDER BY b.datev DESC";

	$resql = $db->query($sql);
	if ($resql) {
		while ($obj = $db->fetch_object($resql)) {
			$res[] = $obj;
		}
	}
	return $res;
}

/**
 * Vérifie si un ID Qonto existe déjà dans l'extrafield
 */
function qontosync_is_linked($qonto_id)
{
	global $db;
	$sql = "SELECT fk_object FROM " . MAIN_DB_PREFIX . "bank_extrafields WHERE qonto_id = '" . $db->escape($qonto_id) . "'";
	$resql = $db->query($sql);
	if ($resql && $db->num_rows($resql) > 0) {
		$obj = $db->fetch_object($resql);
		return $obj->fk_object;
	}
	return 0;
}
