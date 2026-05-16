# qontosync for Dolibarr

Module de rapprochement bancaire manuel entre l'API Qonto et Dolibarr, fonctionnant sans stockage de données tiers.

## Fonctionnalités

- **Interface de sélection** : Sélection de la période (Mois/Année) et du compte bancaire Dolibarr via des listes déroulantes natives.
- **Récupération à la volée** : Appel en temps réel de l'API Qonto pour afficher les transactions du compte (IBAN) sur la période choisie.
- **Tableau de réconciliation** :
    - Affichage des données Qonto (Date, Libellé avec vignette de référence, ID de transaction).
    - Suggestion dynamique dans une liste déroulante des écritures bancaires Dolibarr ayant le **montant exact**.
- **Liaison manuelle** : Bouton permettant de lier une transaction Qonto à une écriture Dolibarr existante via l'injection de l'ID Qonto dans un `extrafield`.
- **Gestion des erreurs** : Traitement exhaustif des retours d'erreurs API et des cas d'absence de correspondance.

## Spécifications Techniques

- **Zéro table SQL supplémentaire** : Utilisation exclusive des tables natives et d'un `extrafield` sur la table `llx_bank`.
- **Stockage sécurisé** : Identifiants API (Clé secrète, Login/Slug) stockés dans les constantes de configuration de Dolibarr (`llx_const`).
- **Architecture MVC / Clean Code** :
    - **Orchestrateur** : Page principale à la racine du module gérant le flux.
    - **Logique métier** : Classes situées dans `/class/`.
    - **Affichage** : Fonctions de rendu centralisées dans `/lib/qontosync.lib.php`.
- **Intégration Visuelle** : Utilisation exclusive du CSS natif de Dolibarr pour garantir la compatibilité avec tous les thèmes.

## Installation

1. Copiez le dossier `qontosync` dans votre répertoire `custom`.
2. Activez le module dans **Configuration > Modules**.
3. Renseignez la clé d'API et le Login/Slug dans la configuration du module.
4. Le module crée automatiquement l'extrafield `qonto_id` sur l'objet Écriture Bancaire (`bank`) lors de son activation.

## Utilisation

1. Accédez au menu **Banque > qontosync**.
2. Sélectionnez le Mois, l'Année et le Compte Bancaire concerné.
3. Cliquez sur le bouton de recherche pour interroger l'API Qonto.
4. Pour chaque ligne Qonto, sélectionnez l'écriture Dolibarr correspondante dans la liste et cliquez sur "Lier".

---
Développé pour Dolibarr.
