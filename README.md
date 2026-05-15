# QontoSync for Dolibarr

Module de synchronisation intelligente entre Qonto et Dolibarr.

## Fonctionnalités (v1.0.0)

- **Configuration API** : Renseignez vos identifiants Qonto en toute sécurité.
- **Mode Simulation (Mock)** : Testez le module avec des données fictives sans clés API réelles.
- **Tableau de bord** : Visualisez vos transactions Qonto filtrées par date (Mois/Année) et par compte bancaire (via IBAN).

## Installation

1. Copiez le dossier `qontosync` dans votre répertoire `custom` de Dolibarr.
2. Activez le module dans **Configuration > Modules**.
3. Configurez vos identifiants API Qonto ou activez le mode Mock dans la configuration du module.

## Utilisation

1. Rendez-vous dans le menu **Banque > QontoSync**.
2. Sélectionnez le compte bancaire et la période souhaitée.
3. Les transactions récupérées s'affichent dans le tableau.

---
Développé pour Dolibarr v23.
