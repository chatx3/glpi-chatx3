<?php

/**
 * Hooks d'installation / désinstallation et hooks fonctionnels du plugin ChatX3.
 *
 * @license GPLv3+
 */

use GlpiPlugin\Chatx3\Category;
use GlpiPlugin\Chatx3\Config;
use GlpiPlugin\Chatx3\Queue;

/**
 * Installation : configuration par défaut, tables et CronTask.
 */
function plugin_chatx3_install(): bool
{
    Config::install();
    Queue::installTable();
    Category::installTable();

    // Tâche planifiée de traitement de la file (toutes les 5 minutes).
    CronTask::register(
        Queue::class,
        'chatx3',
        5 * MINUTE_TIMESTAMP,
        [
            'state'   => CronTask::STATE_WAITING,
            'mode'    => CronTask::MODE_EXTERNAL,
            'comment' => 'Traitement de la file de qualification ChatX3 des tickets.',
        ]
    );

    return true;
}

/**
 * Désinstallation : supprime CronTask, table de file et configuration.
 */
function plugin_chatx3_uninstall(): bool
{
    $cron = new CronTask();
    if ($cron->getFromDBbyName(Queue::class, 'chatx3')) {
        $cron->delete(['id' => $cron->getID()]);
    }

    Category::uninstallTable();
    Queue::uninstallTable();
    Config::uninstall();

    return true;
}

/**
 * Hook `item_add` sur Ticket : enfile le ticket pour qualification.
 *
 * @param CommonDBTM $item Ticket nouvellement créé.
 */
function plugin_chatx3_ticket_add($item): void
{
    plugin_chatx3_enqueue_ticket($item);
}

/**
 * Hook `item_update` sur Ticket : rattrape les tickets dont la catégorie
 * (éligible) est renseignée après la création. La déduplication par
 * `tickets_id` garantit qu'un ticket déjà enfilé/traité n'est pas réinséré.
 *
 * @param CommonDBTM $item Ticket modifié (champs à jour).
 */
function plugin_chatx3_ticket_update($item): void
{
    plugin_chatx3_enqueue_ticket($item);
}

/**
 * Enfile un ticket s'il est éligible (plugin actif + catégorie au périmètre).
 *
 * Aucun appel réseau ici (UI non bloquée) : la CronTask fait le travail.
 */
function plugin_chatx3_enqueue_ticket($item): void
{
    if (!($item instanceof Ticket) || !Config::isEnabled()) {
        return;
    }

    // Filtre par catégorie : si la restriction est active, on n'enfile que les
    // tickets des catégories configurées.
    $category_id = (int) ($item->fields['itilcategories_id'] ?? 0);
    if (!Category::shouldProcess($category_id)) {
        return;
    }

    $tickets_id = (int) ($item->fields['id'] ?? 0);
    if ($tickets_id > 0) {
        Queue::enqueue($tickets_id);
    }
}
