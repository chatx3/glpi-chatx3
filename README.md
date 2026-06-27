<p align="center">
  <img src="chatx3.png" alt="ChatX3" width="120">
</p>

# ChatX3 — plugin GLPI

Qualification automatique des tickets GLPI via l'assistant **ChatX3**, spécialisé
dans l'écosystème **Sage X3**.

**Éditeur : [IntellX — intellx.chat](https://intellx.chat)** · Licence **GPL v3+** ·
GLPI **11.0+** · PHP **8.2+**

> 📦 **Installation** (client) : voir **[docs/INSTALLATION.md](docs/INSTALLATION.md)**
> — depuis le Marketplace GLPI ou par téléchargement direct.

> État : **v0.1.0** — première version fonctionnelle « note interne seule ».
> À la création d'un ticket, le plugin l'enfile, puis une CronTask appelle
> ChatX3 et ajoute le résultat en **note privée** sur le ticket. Pas de réponse
> publique au demandeur dans cette version (l'API ne fournit pas de score de
> confiance — voir l'écart ci-dessous).

## Fonctionnement (v1)

1. **Hook `item_add`** sur `Ticket` → insertion dans la file
   `glpi_plugin_chatx3_queue` (état `pending`). Aucun appel réseau : l'UI n'est
   pas bloquée.
2. **CronTask `chatx3`** (toutes les 5 min) → pour chaque ticket `pending` :
   appel **synchrone** unique à ChatX3 (`message_content` = titre + description),
   puis ajout d'une **note interne privée** (`ITILFollowup`, `is_private=1`)
   contenant la réponse rendue en HTML. État → `done`.
3. **Garde-fous** : une seule ligne de file par ticket (anti-retraitement),
   3 tentatives max avant `error`, gestion du `429` (rate-limit : report sans
   consommer de tentative), tickets résolus/clos ignorés, logs GLPI standard.

> La CronTask est enregistrée en mode **externe** (frequency 5 min) : sur le VPS,
> prévoir le cron CLI GLPI (`bin/console`/`front/cron.php`) car un appel ChatX3
> peut durer jusqu'à 120 s, au-delà du `max_execution_time` du cron web interne.
> En local, utiliser le bouton **Exécuter** de l'action automatique pour tester.

## Compatibilité

- GLPI **11.0+**
- PHP **8.2+**

## Installation

Guide complet pour les administrateurs : **[docs/INSTALLATION.md](docs/INSTALLATION.md)**
(Marketplace GLPI ou téléchargement direct).

En résumé :
1. Installer depuis **Configuration → Plugins → Marketplace**, ou copier le
   dossier `chatx3/` dans `glpi/plugins/`.
2. **Installer** puis **Activer** « ChatX3 ».
3. **Configurer** pour renseigner la clé API, et vérifier que le cron GLPI tourne.

## Configuration

| Paramètre                  | Description                                                            |
|----------------------------|------------------------------------------------------------------------|
| URL de l'endpoint          | Endpoint de l'API ChatX3 (valeur par défaut pré-remplie).             |
| Clé API                    | Envoyée dans l'en-tête `x-api-key`. **Chiffrée au repos** (GLPIKey).  |
| Timeout                    | Délai max par appel HTTP (l'API peut répondre jusqu'à 120 s).         |
| Qualifier automatiquement  | Active/désactive le traitement des nouveaux tickets.                  |
| ID utilisateur posteur     | Compte GLPI auteur de la note interne (0 = système).                  |
| Restreindre aux catégories | Si activé, seuls les tickets des catégories listées sont qualifiés.  |
| Prompt système par défaut  | Texte global ajouté en tête, si la catégorie n'a pas son propre prompt.|

La clé API est stockée chiffrée dans `glpi_configs` (contexte `plugin:chatx3`)
et n'est jamais réaffichée en clair dans le formulaire.

### Filtre et prompts par catégorie

Dans la section **Per-category prompts** de la page de configuration, on associe
à chaque catégorie de ticket (`ITILCategory`) un **prompt système** optionnel,
placé en tête du message envoyé à ChatX3, avant le contenu du ticket.

- **Filtre** : si « Restreindre aux catégories » est activé, seuls les tickets
  dont la catégorie est listée sont enfilés et qualifiés ; les autres sont
  ignorés dès le hook (pas d'entrée en file).
- **Prompt** : pour un ticket traité, le prompt utilisé est celui de sa
  catégorie s'il est défini, sinon le prompt système global par défaut, sinon
  aucun. Stockage dans `glpi_plugin_chatx3_categories`.

## Pièces jointes / captures d'écran

L'API ChatX3 **ne traite pas encore les fichiers** (« File-based input is planned
but not yet available »). Le plugin :

- **inventorie** les documents liés au ticket (captures collées dans la
  description *et* fichiers joints — GLPI les stocke tous comme `Document`) ;
- les **déduplique** par empreinte SHA1 et les **trie** images / autres documents ;
- ajoute à la note interne un **résumé** « 📎 N pièces jointes — non analysées par
  ChatX3 » avec noms et types, pour que le technicien sache qu'il doit les ouvrir ;
- glisse les **noms de fichiers** dans le message envoyé (un nom porte parfois un
  code d'erreur exploitable) ;
- garde un **point d'extension prêt** (`Client::ask($message, $images)` +
  `Attachment::getForwardableImages()` + réglage `send_attachments_to_api`) pour
  transmettre réellement les images **dès que l'API ChatX3 acceptera les
  fichiers** — sans rien à installer côté client (pas d'OCR local à déployer).

Garde-fous multi-fichiers : déduplication SHA1, plafond `MAX_IMAGES` (5) et
`MAX_IMAGE_SIZE` (5 Mo), filtrage des formats transmissibles, envoi **opt-in**
(confidentialité : une capture peut contenir des données sensibles).

## Note sur l'API ChatX3

L'API ChatX3 est **synchrone, en un seul appel** (`POST /api-ask`, réponse en
20–120 s) et renvoie un message Markdown. Elle ne fournit pas encore de score de
confiance ni de traitement des fichiers : la réponse publique conditionnelle au
demandeur et l'envoi des captures d'écran sont donc prévus pour une version
ultérieure, dès que l'API évoluera.

## Éditeur

**IntellX** — <https://intellx.chat>

## Licence

**GPL v3+** — voir [LICENSE](LICENSE).
