# Changelog

Toutes les évolutions notables de ce plugin sont consignées ici.
Le format suit [Keep a Changelog](https://keepachangelog.com/fr/1.1.0/) et le
projet adopte le [versionnage sémantique](https://semver.org/lang/fr/).

## [0.1.0] - 2026-06-27

### Ajouté
- Configuration de la connexion à l'API ChatX3 (endpoint, clé API **chiffrée**
  au repos, délai d'expiration).
- Qualification automatique des tickets : hook à la création + à la modification
  (`item_add` / `item_update`), file d'attente et tâche planifiée (CronTask)
  appelant ChatX3, puis ajout d'une **note interne privée**.
- **Filtre par catégorie** : restriction de la qualification aux catégories
  sélectionnées.
- **Prompt système par catégorie** (+ prompt global par défaut) placé en tête du
  contenu envoyé à ChatX3.
- Inventaire des **pièces jointes** (déduplication, tri images / documents),
  résumé dans la note, et point d'extension prêt pour l'envoi des images dès que
  l'API ChatX3 acceptera les fichiers.
- **Filet de rattrapage** : le cron récupère les tickets éligibles qu'aucun hook
  n'a captés (orphelins, imports, tickets antérieurs à l'activation).
- Garde-fous : anti-retraitement, verrou de concurrence, gestion du rate-limit
  (HTTP 429), nouvelles tentatives, délai d'expiration, journaux GLPI.
- Traductions **français** et anglais.
