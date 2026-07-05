# QontoSync pour Dolibarr

Module de rapprochement bancaire manuel entre l'API Qonto et Dolibarr, fonctionnant sans aucun stockage de données tiers.

## Fonctionnalités

- **Interface de sélection** : Sélection de la période (Mois/Année) et du compte bancaire Dolibarr via des listes déroulantes natives. Intègre un message d'accueil pour guider l'utilisateur au chargement initial et un indicateur de chargement animé explicite ("Recherche des transactions en cours...") pendant la récupération.
- **Récupération à la volée** : Appel en temps réel de l'API Qonto pour afficher les transactions du compte (IBAN) sur la période choisie.
- **Gestion des relevés bancaires** : Comparatif mensuel automatique des soldes entre Dolibarr et Qonto, et importation directe des relevés officiels Qonto (PDF) dans la GED de Dolibarr.
- **Support Multi-devise** : Prise en charge des factures clients et fournisseurs en devises étrangères, incluant la génération automatique des lignes d'écart de conversion (gains ou pertes de change) en comptabilité.
- **Format libre des relevés bancaires** : Liberté totale pour définir la nomenclature de vos relevés (`num_releve`) depuis la configuration grâce à l'utilisation des balises de la fonction native PHP `date()` placées entre accolades (ex : `QONTO-{Y}-{m}`).
- **Tableau de réconciliation** :
    - Affichage des données Qonto (Date, Libellé avec vignette de référence, ID de transaction).
    - Suggestion dynamique dans une liste déroulante des écritures bancaires, factures, déclarations de TVA, charges sociales, salaires et notes de frais Dolibarr ayant le **montant exact**.
- **Rapprochement avancé** : Fenêtre modale permettant d'associer manuellement une transaction à plusieurs documents (factures clients/fournisseurs, TVA, charges sociales, salaires, notes de frais) d'un même tiers et de gérer des paiements partiels. Dispose d'une **barre de recherche en temps réel (Live search)** et d'un bouton de validation flottant centré moderne et optimisé pour l'utilisation sur ordinateur.
- **Traitement par lot (FIFO)** : Imputation des montants semi-automatique via sélection de plusieurs documents simultanément. Les rapprochements sont effectués dans l'ordre de sélection (FIFO) sur les opérations du même signe (ex: toutes les dépenses, puis toutes les recettes). Calcul à la volée du solde restant disponible à l'imputation et allocation automatique du montant complet du document sélectionné (s'il est de même signe).
- **Correspondance des modes de paiement** : Associez les types d'opérations Qonto (Virements, Cartes, Prélèvements, Chèques) à vos modes de paiement natifs Dolibarr.
- **Liaison manuelle** : Bouton permettant de lier une transaction Qonto à une écriture Dolibarr existante via l'injection de l'ID Qonto dans un `extrafield`.
- **Mode Sandbox** : Testez vos intégrations en toute sécurité grâce au support du mode Sandbox de Qonto.

## Spécifications Techniques et Sécurité

- **Zéro table SQL supplémentaire** : Utilisation exclusive des tables natives et d'un `extrafield` sur la table `llx_bank`.
- **Stockage sécurisé** : Identifiants API (Clé secrète, Login/Slug) stockés dans les constantes de configuration de Dolibarr (`llx_const`) avec masquage des champs.
- **Intégrité des données (Rollback)** : Gestion stricte des transactions SQL. En cas d'erreur pendant la création d'un paiement ou d'une liaison, toute l'opération est annulée pour éviter les données corrompues.
- **Verrouillage métier** : Blocage backend empêchant de lier plusieurs documents pour les opérations nécessitant un ratio de "1 pour 1" (ex: TVA, Salaires, Notes de frais).
- **Protection Anti-Doublon** : Sécurité intégrée empêchant la double soumission d'une transaction (ex: lors d'un rafraîchissement de page).
- **Résilience des sessions & Anti-CSRF** : Protection complète contre l'expiration des jetons de sécurité ou des sessions (HTTP 401/403) en cas d'utilisation multi-onglets, grâce à un mécanisme d'auto-rechargement silencieux et transparent pour l'utilisateur.
- **Protection Anti-IDOR stricte** : Ségrégation totale des comptes bancaires entre les entités (Multi-société). Les contrôles sont appliqués de manière rigoureuse sur toutes les requêtes (y compris AJAX).
- **Sanitisation et protection XSS** : Nettoyage rigoureux des masques de formatage libres (`dol_sanitizeFileName()`) et des données API (`dol_escape_htmltag()`) pour prévenir toute erreur de troncature de données (*Data truncation*) et toute faille de type Cross-Site Scripting.
- **Accessibilité et thèmes (Clair/Sombre)** : Ajustements CSS sur-mesure assurant un contraste de couleur optimal et une lisibilité accrue pour tous les badges de référence et statuts par défaut, s'adaptant nativement au mode clair et au mode sombre de Dolibarr.
- **Intégration Visuelle** : Utilisation exclusive du CSS natif de Dolibarr pour garantir la compatibilité avec tous les thèmes.
- **Hautes performances** : Requêtes SQL sur-mesure et hydratation manuelle des objets permettant de traiter instantanément des milliers de factures impayées sans surcharger le serveur.
- **Gestion stricte des droits** : Séparation granulaire des permissions entre la simple consultation (Lecture) et le droit d'associer des écritures (Écriture/Création).

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
LISEZMOI.md
Affichage de LISEZMOI.md.