<?php

/**
 * File d'attente de qualification des tickets ChatX3.
 *
 * Le hook `item_add` enfile chaque nouveau ticket (state = pending). La CronTask
 * `cronchatx3` dépile et déclenche le traitement (un appel synchrone à ChatX3
 * par ticket — cf. [[chatx3-api-vs-spec]] : pas de polling ni de session_id avec
 * l'API actuelle).
 *
 * @license GPLv3+
 */

namespace GlpiPlugin\Chatx3;

use CronTask;
use DBConnection;
use Toolbox;

class Queue
{
    public const TABLE = 'glpi_plugin_chatx3_queue';

    public const STATE_PENDING    = 'pending';
    public const STATE_PROCESSING = 'processing';
    public const STATE_DONE       = 'done';
    public const STATE_ERROR      = 'error';

    /** Nombre de tentatives avant de basculer un ticket en erreur. */
    public const MAX_ATTEMPTS = 3;

    /** Nombre de tickets traités au maximum par exécution de la CronTask. */
    public const BATCH_SIZE = 5;

    /**
     * Délai (secondes) au-delà duquel un ticket resté en `processing` est
     * considéré comme bloqué (script interrompu en plein appel) et remis en
     * `pending`. Doit être supérieur au temps d'appel max de ChatX3 (120 s).
     */
    public const RECLAIM_TIMEOUT = 900;

    /**
     * Fenêtre (secondes) du filet de rattrapage : on ré-inspecte les tickets
     * éligibles créés dans ce délai pour rattraper ceux qu'aucun hook n'a
     * enfilés (orphelins d'avant un correctif, imports, tickets antérieurs à
     * l'activation du plugin…). 2 jours par défaut.
     */
    public const SWEEP_WINDOW = 2 * DAY_TIMESTAMP;

    /** Nombre maximal de tickets rattrapés par balayage (anti-flood). */
    public const SWEEP_LIMIT = 50;

    /**
     * Crée la table de file si elle n'existe pas.
     */
    public static function installTable(): void
    {
        /** @var \DBmysql $DB */
        global $DB;

        if ($DB->tableExists(self::TABLE)) {
            return;
        }

        $charset   = DBConnection::getDefaultCharset();
        $collation = DBConnection::getDefaultCollation();

        $sql = "CREATE TABLE `" . self::TABLE . "` (
            `id` int unsigned NOT NULL AUTO_INCREMENT,
            `tickets_id` int unsigned NOT NULL,
            `state` varchar(20) NOT NULL DEFAULT 'pending',
            `attempts` tinyint unsigned NOT NULL DEFAULT 0,
            `last_error` text,
            `date_creation` timestamp NULL DEFAULT NULL,
            `date_mod` timestamp NULL DEFAULT NULL,
            PRIMARY KEY (`id`),
            UNIQUE KEY `tickets_id` (`tickets_id`),
            KEY `state` (`state`)
        ) ENGINE=InnoDB DEFAULT CHARSET={$charset} COLLATE={$collation}";

        $DB->doQuery($sql);
    }

    /**
     * Supprime la table de file.
     */
    public static function uninstallTable(): void
    {
        /** @var \DBmysql $DB */
        global $DB;

        if ($DB->tableExists(self::TABLE)) {
            $DB->doQuery("DROP TABLE `" . self::TABLE . "`");
        }
    }

    /**
     * Enfile un ticket (idempotent grâce à la contrainte UNIQUE sur tickets_id).
     */
    public static function enqueue(int $tickets_id): void
    {
        /** @var \DBmysql $DB */
        global $DB;

        if ($tickets_id <= 0 || !$DB->tableExists(self::TABLE)) {
            return;
        }

        $already = $DB->request([
            'FROM'  => self::TABLE,
            'WHERE' => ['tickets_id' => $tickets_id],
        ]);
        if (count($already) > 0) {
            return;
        }

        $now = $_SESSION['glpi_currenttime'] ?? date('Y-m-d H:i:s');
        $DB->insert(self::TABLE, [
            'tickets_id'    => $tickets_id,
            'state'         => self::STATE_PENDING,
            'attempts'      => 0,
            'date_creation' => $now,
            'date_mod'      => $now,
        ]);
    }

    /**
     * Description affichée dans Configuration > Actions automatiques.
     */
    public static function cronInfo($name): array
    {
        return [
            'description' => __('Process the ChatX3 ticket qualification queue', 'chatx3'),
        ];
    }

    /**
     * Tâche planifiée : traite les tickets en attente.
     *
     * @return int  >0 si au moins un ticket a été traité, 0 sinon.
     */
    public static function cronchatx3(CronTask $task): int
    {
        /** @var \DBmysql $DB */
        global $DB;

        if (!Config::isEnabled()) {
            return 0;
        }

        // Filet de rattrapage : enfile les tickets éligibles récents qu'aucun
        // hook n'a captés (orphelins, imports, tickets antérieurs au plugin…).
        self::sweepUnqueued();

        // Remet en file les tickets bloqués en `processing` (run interrompu).
        self::reclaimStale();

        $processed = 0;

        $rows = $DB->request([
            'FROM'  => self::TABLE,
            'WHERE' => ['state' => self::STATE_PENDING],
            'ORDER' => 'id ASC',
            'LIMIT' => self::BATCH_SIZE,
        ]);

        foreach ($rows as $row) {
            $tickets_id = (int) $row['tickets_id'];
            $attempts   = (int) $row['attempts'];

            // Verrou atomique : seul le passage qui bascule pending->processing
            // traite le ticket. Évite le double traitement en cas d'exécutions
            // concurrentes (cron + déclenchement manuel, ou deux crons).
            if (!self::claim($tickets_id)) {
                continue;
            }

            $result = TicketProcessor::process($tickets_id);

            if (!empty($result['success'])) {
                self::setState($tickets_id, self::STATE_DONE);
                $task->addVolume(1);
                $task->log(sprintf('Ticket %d : note de qualification ChatX3 ajoutée.', $tickets_id));
                $processed++;
                continue;
            }

            // Rate-limit (429) : on libère sans consumer de tentative.
            if (!empty($result['rate_limited'])) {
                self::release($tickets_id, (string) ($result['error'] ?? 'rate limited'));
                $task->log(sprintf('Ticket %d : ChatX3 rate-limité, report au prochain cycle.', $tickets_id));
                continue;
            }

            $attempts++;
            $error = (string) ($result['error'] ?? 'unknown error');

            if ($attempts >= self::MAX_ATTEMPTS) {
                self::setError($tickets_id, $error, $attempts);
                Toolbox::logError(sprintf('[chatx3] Ticket %d en erreur après %d tentatives : %s', $tickets_id, $attempts, $error));
            } else {
                self::setError($tickets_id, $error, $attempts, self::STATE_PENDING);
            }
        }

        return $processed > 0 ? 1 : 0;
    }

    /**
     * Tente de réserver un ticket pour traitement (pending -> processing).
     *
     * @return bool true si ce passage a obtenu le verrou.
     */
    private static function claim(int $tickets_id): bool
    {
        /** @var \DBmysql $DB */
        global $DB;

        $DB->update(self::TABLE, [
            'state'    => self::STATE_PROCESSING,
            'date_mod' => date('Y-m-d H:i:s'),
        ], [
            'tickets_id' => $tickets_id,
            'state'      => self::STATE_PENDING,
        ]);

        return $DB->affectedRows() > 0;
    }

    /**
     * Filet de rattrapage : enfile les tickets éligibles (catégorie au
     * périmètre, non clos/résolus, créés dans la fenêtre) qui ne sont pas déjà
     * en file. `enqueue()` déduplique, donc un ticket déjà traité est ignoré.
     */
    private static function sweepUnqueued(): void
    {
        /** @var \DBmysql $DB */
        global $DB;

        $where = [
            'is_deleted'    => 0,
            'date_creation' => ['>=', date('Y-m-d H:i:s', time() - self::SWEEP_WINDOW)],
            'NOT'           => ['status' => [\Ticket::SOLVED, \Ticket::CLOSED]],
        ];

        // Respecte le filtre par catégorie.
        if (Config::isRestrictedToCategories()) {
            $category_ids = Category::getConfiguredIds();
            if (empty($category_ids)) {
                return; // restriction active mais aucune catégorie → rien à faire
            }
            $where['itilcategories_id'] = $category_ids;
        }

        $iterator = $DB->request([
            'SELECT' => 'id',
            'FROM'   => 'glpi_tickets',
            'WHERE'  => $where,
            'ORDER'  => 'id DESC',
            'LIMIT'  => self::SWEEP_LIMIT,
        ]);

        foreach ($iterator as $row) {
            self::enqueue((int) $row['id']);
        }
    }

    /**
     * Remet en `pending` les tickets restés trop longtemps en `processing`.
     */
    private static function reclaimStale(): void
    {
        /** @var \DBmysql $DB */
        global $DB;

        $limit = date('Y-m-d H:i:s', time() - self::RECLAIM_TIMEOUT);

        $DB->update(self::TABLE, [
            'state' => self::STATE_PENDING,
        ], [
            'state'    => self::STATE_PROCESSING,
            'date_mod' => ['<', $limit],
        ]);
    }

    /**
     * Met à jour l'état d'un ticket dans la file.
     */
    private static function setState(int $tickets_id, string $state): void
    {
        /** @var \DBmysql $DB */
        global $DB;

        $DB->update(self::TABLE, [
            'state'    => $state,
            'date_mod' => date('Y-m-d H:i:s'),
        ], ['tickets_id' => $tickets_id]);
    }

    /**
     * Enregistre une tentative en échec (état pending pour réessai, ou error).
     */
    private static function setError(int $tickets_id, string $error, int $attempts, string $state = self::STATE_ERROR): void
    {
        /** @var \DBmysql $DB */
        global $DB;

        $DB->update(self::TABLE, [
            'state'      => $state,
            'attempts'   => $attempts,
            'last_error' => mb_substr($error, 0, 1000),
            'date_mod'   => date('Y-m-d H:i:s'),
        ], ['tickets_id' => $tickets_id]);
    }

    /**
     * Libère un ticket réservé en le remettant en `pending` sans consommer de
     * tentative (cas du rate-limit).
     */
    private static function release(int $tickets_id, string $note): void
    {
        /** @var \DBmysql $DB */
        global $DB;

        $DB->update(self::TABLE, [
            'state'      => self::STATE_PENDING,
            'last_error' => mb_substr($note, 0, 1000),
            'date_mod'   => date('Y-m-d H:i:s'),
        ], ['tickets_id' => $tickets_id]);
    }
}
