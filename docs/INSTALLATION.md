# ChatX3 pour GLPI — Guide d'installation

> Qualification automatique des tickets GLPI via l'assistant **ChatX3**,
> spécialisé Sage X3. Propulsé par **IntellX** — <https://intellx.chat>

Ce guide s'adresse à l'administrateur GLPI du client. Deux méthodes
d'installation sont proposées : depuis le **Marketplace** intégré à GLPI, ou par
**téléchargement direct**.

---

## 1. Prérequis

| Élément | Exigence |
|---|---|
| GLPI | **11.0** ou supérieur |
| PHP | **8.2** ou supérieur |
| Base de données | MariaDB / MySQL (celle de GLPI) |
| Tâches automatiques | Le **cron de GLPI** doit être actif (voir §5) |
| Accès réseau | Le serveur GLPI doit pouvoir joindre l'API ChatX3 en HTTPS |
| Clé API ChatX3 | Fournie par IntellX (voir <https://intellx.chat>) |

---

## 2. Méthode A — Installation depuis le Marketplace GLPI (recommandée)

1. Connectez-vous à GLPI avec un compte **Super-Admin**.
2. Allez dans **Configuration → Plugins**, puis ouvrez l'onglet **Marketplace**.
3. Recherchez **« ChatX3 »**.
4. Cliquez sur **Installer** (téléchargement automatique), puis sur **Activer**.
5. Passez à la **configuration** (§4).

> Les mises à jour suivantes se font d'un clic depuis le Marketplace.

---

## 3. Méthode B — Installation par téléchargement direct

1. Téléchargez la dernière archive **`chatx3-x.y.z.tar.gz`** depuis la page des
   versions :
   - **Dernière version** : <https://github.com/chatx3/glpi-chatx3/releases/latest>
   - Toutes les versions : <https://github.com/chatx3/glpi-chatx3/releases>

   (sous **Assets**, fichier `chatx3-x.y.z.tar.gz`).
2. Décompressez-la. Vous obtenez un dossier nommé exactement **`chatx3`**.
3. Copiez ce dossier dans le répertoire **`plugins/`** de votre GLPI :
   ```
   <racine_glpi>/plugins/chatx3/
   ```
   (Vérifiez que le chemin est bien `plugins/chatx3/setup.php`.)
4. Ajustez si besoin les droits pour l'utilisateur du serveur web :
   ```bash
   chown -R www-data:www-data <racine_glpi>/plugins/chatx3
   ```
5. Dans GLPI : **Configuration → Plugins** → **Installer** puis **Activer**
   « ChatX3 ».
6. Passez à la **configuration** (§4).

> Mise à jour manuelle : remplacez le dossier `chatx3/` par la nouvelle version,
> puis, si demandé, relancez l'installation depuis la page Plugins.

---

## 4. Configuration

1. **Configuration → Plugins → ChatX3 → Configurer** (icône clé à molette).
2. Renseignez :
   - **Clé API** : votre clé ChatX3 (stockée **chiffrée**, jamais réaffichée).
   - **URL de l'endpoint** : pré-remplie, à ne modifier que sur indication d'IntellX.
   - **Délai d'expiration** : laisser la valeur par défaut (l'API peut répondre
     jusqu'à 120 s).
   - **Qualifier automatiquement les nouveaux tickets** : activé.
   - **ID de l'utilisateur posteur** *(optionnel)* : compte GLPI auteur des notes
     (0 = système).
3. *(Optionnel)* **Restreindre aux catégories** : n'activer la qualification que
   pour certaines catégories de tickets.
4. *(Optionnel)* **Prompts par catégorie** : associer à une catégorie un prompt
   système (consignes données à ChatX3, placées avant le contenu du ticket).
5. **Enregistrer**.

---

## 5. Activation du cron (important)

La qualification est faite par une **tâche automatique** (toutes les 5 minutes
par défaut). Elle nécessite que le cron de GLPI s'exécute.

- **Cron système (recommandé)** : une ligne de crontab appelle le cron GLPI, ex.
  ```cron
  */2 * * * * www-data /usr/bin/php <racine_glpi>/front/cron.php
  ```
  C'est la méthode conseillée car un appel ChatX3 peut durer jusqu'à 120 s.
- **Vérification / lancement manuel** : **Configuration → Actions automatiques →
  `chatx3`** → bouton **Exécuter**.

Vous pouvez ajuster la fréquence de la tâche `chatx3` dans **Actions
automatiques**.

---

## 6. Résultat attendu

À la création (ou à la mise à jour de la catégorie) d'un ticket éligible, le
plugin l'enfile. Au passage suivant du cron, ChatX3 est interrogé et une **note
interne privée** « Qualification automatique — ChatX3 » est ajoutée au ticket,
visible par les techniciens uniquement.

> Pièces jointes / captures d'écran : l'API ChatX3 ne traite pas encore les
> fichiers. Le plugin les **signale** dans la note et est déjà prêt à les
> transmettre dès que l'API le permettra (aucune installation côté client).

---

## 7. Désinstallation

**Configuration → Plugins → ChatX3** : **Désactiver** puis **Désinstaller**.
Les tables et la configuration du plugin sont supprimées. Les notes déjà
ajoutées aux tickets sont conservées.

---

## 8. Support

- Éditeur : **IntellX** — <https://intellx.chat>
- Dépôt & documentation : <https://github.com/chatx3/glpi-chatx3>
- Signaler un problème : <https://github.com/chatx3/glpi-chatx3/issues>

Licence **GPL v3+**.
