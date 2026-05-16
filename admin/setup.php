<?php

// Chargement de l'environnement Dolibarr
require '../../main.inc.php';
require_once DOL_DOCUMENT_ROOT.'/core/lib/admin.lib.php';

// Chargement des fichiers de langue
$langs->loadLangs(array("admin", "qontosync@qontosync"));

// Vérification des droits d'accès
if (!$user->admin) {
	accessforbidden();
}

$action = GETPOST('action', 'aZ09');
$backtopage = GETPOST('backtopage', 'alpha');

// Liste des constantes à gérer
$constantes = array(
	'QONTOSYNC_API_KEY',
	'QONTOSYNC_SLUG'
);

/*
 * Actions
 */

if ($action == 'update') {
	$error = 0;

	foreach ($constantes as $const) {
		$val = GETPOST($const, 'alpha');
		if (!dolibarr_set_const($db, $const, $val, 'chaine', 0, '', $conf->entity)) {
			$error++;
		}
	}

	if (!$error) {
		setEventMessages($langs->trans("SetupSaved"), null, 'mesgs');
	} else {
		setEventMessages($langs->trans("Error"), null, 'errors');
	}
}

/*
 * Affichage
 */

$page_name = $langs->trans("QontoSyncSetup");
llxHeader('', $page_name);

// Génération des onglets de l'administration
$linkback = '<a href="'.DOL_URL_ROOT.'/admin/modules.php?restore_lastsearch_values=1">'.$langs->trans("BackToModuleList").'</a>';
print load_fiche_titre($page_name, $linkback, 'title_setup');

// Barre d'onglets (vide ici car un seul onglet, mais prêt pour extension)
$head = array();
$h = 0;
$head[$h][0] = dol_buildpath("/qontosync/admin/setup.php", 1);
$head[$h][1] = $langs->trans("Settings");
$head[$h][2] = 'settings';
dol_fiche_head($head, 'settings', '', -1, "");

print '<form method="post" action="'.$_SERVER["PHP_SELF"].'">';
print '<input type="hidden" name="token" value="'.newToken().'">';
print '<input type="hidden" name="action" value="update">';

print '<table class="noborder centpercent">';
print '<tr class="liste_titre">';
print '<td>'.$langs->trans("Parameter").'</td>';
print '<td>'.$langs->trans("Value").'</td>';
print '</tr>';

// Champ API KEY
print '<tr class="oddeven">';
print '<td>'.$langs->trans("QontoSyncApiKey").'</td>';
print '<td><input type="password" size="40" name="QONTOSYNC_API_KEY" value="'.$conf->global->QONTOSYNC_API_KEY.'"></td>';
print '</tr>';

// Champ SLUG
print '<tr class="oddeven">';
print '<td>'.$langs->trans("QontoSyncSlug").'</td>';
print '<td><input type="text" size="40" name="QONTOSYNC_SLUG" value="'.$conf->global->QONTOSYNC_SLUG.'"></td>';
print '</tr>';

print '</table>';

print '<div class="tabsAction">';
print '<input type="submit" class="button" value="'.$langs->trans("Save").'">';
print '</div>';

print '</form>';

dol_fiche_end();

llxFooter();
$db->close();
