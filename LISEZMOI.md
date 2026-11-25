# QontoSync pour Dolibarr

Module de rapprochement bancaire manuel entre Qonto et Dolibarr, fonctionnant en temps réel sans stockage de données bancaires sur des serveurs tiers.

## Descriptif fonctionnel

### Récupération en temps réel des transactions
- Sélectionnez un compte bancaire Dolibarr et une période (mois/année) pour récupérer directement les transactions Qonto via l'API Qonto.
- Testez vos configurations en toute sécurité grâce au mode Sandbox de Qonto.

### Rapprochement et lettrage
- **Suggestions automatiques** : Propose les écritures bancaires, factures clients/fournisseurs, déclarations de TVA, charges sociales, salaires et notes de frais Dolibarr correspondant au montant exact.
- **Rapprochement avancé et partiel** : Associez une transaction à plusieurs documents d'un même tiers, gérez les paiements partiels et filtrez vos documents via la recherche en temps réel.
- **Rapprochement par lot** : Sélectionnez et rapprochez plusieurs documents à la fois dans l'ordre de sélection (FIFO).
- **Liaison manuelle et déliaison** : Liez une transaction Qonto à une écriture bancaire Dolibarr existante ou annulez un rapprochement en un clic.
- **Opérations diverses** : Créez des opérations diverses (paiements divers) directement depuis une ligne de transaction Qonto.

### Relevés bancaires et multi-devise
- **Contrôle et archivage des relevés** : Comparez les soldes mensuels entre Dolibarr et Qonto, et importez directement les relevés bancaires officiels Qonto (PDF) dans la GED de Dolibarr.
- **Support multi-devise** : Rapprochez les factures en devises étrangères avec prise en charge automatique des gains et pertes de change.

### Configuration et personnalisation
- **Correspondance des modes de paiement** : Associez les types d'opérations Qonto (virement, carte, prélèvement, chèque) à vos modes de paiement Dolibarr.
- **Format personnalisé des relevés** : Définissez votre propre format de référence de relevé bancaire à l'aide de motifs de date (ex. `QONTO-{Y}-{m}`).
- **Préférences visuelles** : Personnalisez la couleur des badges pour les thèmes clair et sombre.

## Prérequis

Le module natif suivant de Dolibarr doit être activé :
- **Banques et Caisses** (`modBanque`)

Les modules suivants sont optionnels (le module vérifie s'ils sont activés avant de proposer leurs documents) :
- **Factures clients** (`modFacture`)
- **Factures fournisseurs** (`modFournisseur`)
- **Taxes, charges sociales et dividendes** (`modTax`)
- **Salaires** (`modSalaries`)
- **Notes de frais** (`modExpenseReport`)

## Liens utiles & Ressources

Pour utiliser ce module, vous devez posséder des identifiants API Qonto. Vous pouvez les gérer sur les plateformes officielles :
- **Portail Développeur Qonto** : [https://developers.qonto.com/](https://developers.qonto.com/) (Pour créer vos clés API et votre Identifiant/Slug).
- **Documentation API** : [https://api-doc.qonto.com/](https://api-doc.qonto.com/) (Détails techniques sur les points de terminaison et le mode Sandbox).
- **Centre d'aide Qonto** : Pour toute question relative aux permissions de votre compte professionnel.

## Installation et Utilisation

1. Copiez le dossier `qontosync` dans votre répertoire `custom` de Dolibarr.
2. Activez le module dans **Accueil > Configuration > Modules/Applications**.
3. Renseignez la clé d'API et configurez les correspondances dans la configuration du module.
4. Accédez au menu **Banques/Caisses > QontoSync** pour commencer le rapprochement.

---
Développé pour Dolibarr.