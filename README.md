# qontosync for Dolibarr

Module de synchronisation intelligente entre Qonto et Dolibarr.

## Fonctionnalités

- **Tableau de bord de réconciliation** : Comparez vos transactions Qonto avec vos écritures Dolibarr période par période.
- **Appairage Intelligent** : Suggestions automatiques basées sur le montant et la date.
- **Liaison en un clic** : Liez une transaction Qonto à :
    - Une écriture bancaire existante.
    - Une facture client impayée (création automatique du paiement).
    - Une facture fournisseur impayée (création automatique du paiement).
- **Auto-Rapprochement** : Lors de la liaison, le module met à jour automatiquement la date de valeur, le mode de paiement et la référence, puis marque l'écriture comme rapprochée dans le relevé du mois concerné.
- **Support Multi-comptes** : Basé sur l'IBAN renseigné sur vos comptes bancaires Dolibarr.
- **Récupération Automatisée** : Supporte la récupération via CRON pour préparer les données.

## Installation

1. Copiez le dossier `qontosync` dans votre répertoire `custom` de Dolibarr.
2. Activez le module dans **Configuration > Modules**.
3. Configurez vos identifiants API Qonto (Login/Slug et Clé Secrète) dans la configuration du module.

## Utilisation

1. Rendez-vous dans **Banque > qontosync**.
2. Sélectionnez le compte bancaire et la période.
3. Utilisez l'icône de liaison pour associer les transactions.
4. Les écritures associées sont automatiquement rapprochées et documentées avec les métadonnées Qonto (Référence, Type d'opération).

## Spécifications Techniques

- Utilise les classes natives Dolibarr (`Paiement`, `AccountLine`) pour garantir l'intégrité des données.
- Stockage de l'ID de transaction Qonto dans les `extrafields` de la table `llx_bank`.
- Mapping automatique des types d'opérations :
    - Card -> CB
    - Direct Debit -> Prélèvement
    - Transfer -> Virement

---
Développé pour Dolibarr.
