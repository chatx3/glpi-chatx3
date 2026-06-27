<?php

/**
 * Configuration par catégorie de ticket pour ChatX3.
 *
 * Deux usages, tous deux indexés par `itilcategories_id` :
 *   - Filtre : si « restreindre aux catégories » est actif, seuls les tickets
 *     dont la catégorie figure ici sont qualifiés.
 *   - Prompt système : texte optionnel placé EN TÊTE du message envoyé à ChatX3,
 *     avant le contenu du ticket. Un prompt global par défaut (Config) prend le
 *     relais quand la catégorie n'a pas de prompt propre.
 *
 * @license GPLv3+
 */

namespace GlpiPlugin\Chatx3;

use DBConnection;
use Dropdown;
use ITILCategory;
use Session;

class Category
{
    public const TABLE = 'glpi_plugin_chatx3_categories';

    /**
     * Crée la table si elle n'existe pas.
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
            `itilcategories_id` int unsigned NOT NULL,
            `system_prompt` text,
            `date_creation` timestamp NULL DEFAULT NULL,
            `date_mod` timestamp NULL DEFAULT NULL,
            PRIMARY KEY (`id`),
            UNIQUE KEY `itilcategories_id` (`itilcategories_id`)
        ) ENGINE=InnoDB DEFAULT CHARSET={$charset} COLLATE={$collation}";

        $DB->doQuery($sql);
    }

    /**
     * Supprime la table.
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
     * Retourne toutes les catégories configurées (triées par nom).
     *
     * @return array<int, array{id:int, itilcategories_id:int, system_prompt:string, name:string}>
     */
    public static function getAll(): array
    {
        /** @var \DBmysql $DB */
        global $DB;

        if (!$DB->tableExists(self::TABLE)) {
            return [];
        }

        $rows = [];
        foreach ($DB->request(['FROM' => self::TABLE]) as $row) {
            $rows[] = [
                'id'                => (int) $row['id'],
                'itilcategories_id' => (int) $row['itilcategories_id'],
                'system_prompt'     => (string) ($row['system_prompt'] ?? ''),
                'name'              => Dropdown::getDropdownName('glpi_itilcategories', (int) $row['itilcategories_id']),
            ];
        }

        usort($rows, static fn($a, $b) => strcasecmp($a['name'], $b['name']));

        return $rows;
    }

    /**
     * Retourne les IDs des catégories configurées (pour le filtre du cron).
     *
     * @return list<int>
     */
    public static function getConfiguredIds(): array
    {
        /** @var \DBmysql $DB */
        global $DB;

        if (!$DB->tableExists(self::TABLE)) {
            return [];
        }

        $ids = [];
        foreach ($DB->request(['SELECT' => 'itilcategories_id', 'FROM' => self::TABLE]) as $row) {
            $ids[] = (int) $row['itilcategories_id'];
        }

        return $ids;
    }

    /**
     * Retourne la configuration d'une catégorie, ou null.
     *
     * @return array{system_prompt:string}|null
     */
    public static function get(int $itilcategories_id): ?array
    {
        /** @var \DBmysql $DB */
        global $DB;

        if ($itilcategories_id <= 0 || !$DB->tableExists(self::TABLE)) {
            return null;
        }

        foreach ($DB->request(['FROM' => self::TABLE, 'WHERE' => ['itilcategories_id' => $itilcategories_id]]) as $row) {
            return ['system_prompt' => (string) ($row['system_prompt'] ?? '')];
        }

        return null;
    }

    /**
     * Indique si la catégorie est explicitement configurée.
     */
    public static function isListed(int $itilcategories_id): bool
    {
        return self::get($itilcategories_id) !== null;
    }

    /**
     * Indique si un ticket de cette catégorie doit être qualifié.
     */
    public static function shouldProcess(int $itilcategories_id): bool
    {
        if (!Config::isRestrictedToCategories()) {
            return true;
        }

        return self::isListed($itilcategories_id);
    }

    /**
     * Retourne le prompt système à appliquer : celui de la catégorie si défini,
     * sinon le prompt global par défaut.
     */
    public static function getSystemPrompt(int $itilcategories_id): string
    {
        $entry = self::get($itilcategories_id);
        if ($entry !== null && trim($entry['system_prompt']) !== '') {
            return $entry['system_prompt'];
        }

        return Config::getGlobalSystemPrompt();
    }

    /**
     * Crée ou met à jour la configuration d'une catégorie (upsert).
     */
    public static function save(int $itilcategories_id, string $system_prompt): void
    {
        /** @var \DBmysql $DB */
        global $DB;

        if ($itilcategories_id <= 0) {
            return;
        }

        $now    = date('Y-m-d H:i:s');
        $prompt = trim($system_prompt);

        if (self::isListed($itilcategories_id)) {
            $DB->update(self::TABLE, [
                'system_prompt' => $prompt,
                'date_mod'      => $now,
            ], ['itilcategories_id' => $itilcategories_id]);
        } else {
            $DB->insert(self::TABLE, [
                'itilcategories_id' => $itilcategories_id,
                'system_prompt'     => $prompt,
                'date_creation'     => $now,
                'date_mod'          => $now,
            ]);
        }
    }

    /**
     * Supprime une entrée de catégorie par son id interne.
     */
    public static function delete(int $id): void
    {
        /** @var \DBmysql $DB */
        global $DB;

        if ($id > 0) {
            $DB->delete(self::TABLE, ['id' => $id]);
        }
    }

    /**
     * Affiche la section « Catégories » de la page de configuration.
     */
    public static function renderConfigSection(): void
    {
        $action = \Plugin::getWebDir('chatx3') . '/front/config.form.php';
        $csrf   = Session::getNewCSRFToken();

        $l_title   = htmlspecialchars(__('Per-category prompts', 'chatx3'), ENT_QUOTES);
        $l_intro   = htmlspecialchars(__('Define a system prompt per ticket category. It is prepended to the ticket content sent to ChatX3. When "restrict to categories" is enabled, only the categories listed below are qualified.', 'chatx3'), ENT_QUOTES);
        $l_cat      = htmlspecialchars(__('Category', 'chatx3'), ENT_QUOTES);
        $l_prompt   = htmlspecialchars(__('System prompt', 'chatx3'), ENT_QUOTES);
        $l_save     = htmlspecialchars(_x('button', 'Save', 'chatx3'), ENT_QUOTES);
        $l_delete   = htmlspecialchars(_x('button', 'Delete', 'chatx3'), ENT_QUOTES);
        $l_add      = htmlspecialchars(__('Add a category', 'chatx3'), ENT_QUOTES);
        $l_none     = htmlspecialchars(__('No category configured yet.', 'chatx3'), ENT_QUOTES);
        $l_ph       = htmlspecialchars(__('e.g. You are a Sage X3 support expert. Answer concisely…', 'chatx3'), ENT_QUOTES);

        $rows = self::getAll();

        echo '<div class="card mx-auto mt-4" style="max-width: 760px;">';
        echo '<div class="card-body">';
        echo '<h2 class="card-title mb-2">' . $l_title . '</h2>';
        echo '<p class="text-muted">' . $l_intro . '</p>';

        // Liste des catégories configurées (un formulaire par ligne).
        if (empty($rows)) {
            echo '<p class="fst-italic text-muted">' . $l_none . '</p>';
        } else {
            foreach ($rows as $row) {
                $cat_name = htmlspecialchars($row['name'], ENT_QUOTES);
                $prompt   = htmlspecialchars($row['system_prompt'], ENT_QUOTES);
                $row_id   = (int) $row['id'];

                echo <<<HTML
<div class="border rounded p-3 mb-3">
    <div class="d-flex justify-content-between align-items-center mb-2">
        <strong>{$cat_name}</strong>
        <form method="post" action="{$action}" onsubmit="return confirm('OK ?');" class="m-0">
            <input type="hidden" name="_glpi_csrf_token" value="{$csrf}">
            <input type="hidden" name="category_row_id" value="{$row_id}">
            <button type="submit" name="delete_category" class="btn btn-sm btn-outline-danger">
                <i class="ti ti-trash"></i> {$l_delete}
            </button>
        </form>
    </div>
    <form method="post" action="{$action}">
        <input type="hidden" name="_glpi_csrf_token" value="{$csrf}">
        <input type="hidden" name="itilcategories_id" value="{$row['itilcategories_id']}">
        <label class="form-label">{$l_prompt}</label>
        <textarea class="form-control mb-2" name="system_prompt" rows="3" placeholder="{$l_ph}">{$prompt}</textarea>
        <div class="d-flex justify-content-end">
            <button type="submit" name="save_category" class="btn btn-sm btn-primary">
                <i class="ti ti-device-floppy"></i> {$l_save}
            </button>
        </div>
    </form>
</div>
HTML;
            }
        }

        // Formulaire d'ajout d'une nouvelle catégorie.
        $cat_dropdown = Dropdown::show(ITILCategory::class, [
            'name'    => 'itilcategories_id',
            'value'   => 0,
            'display' => false,
            'width'   => '100%',
        ]);

        echo <<<HTML
<hr class="my-4">
<h3 class="h6 mb-3">{$l_add}</h3>
<form method="post" action="{$action}">
    <input type="hidden" name="_glpi_csrf_token" value="{$csrf}">
    <div class="mb-2">
        <label class="form-label">{$l_cat}</label>
        {$cat_dropdown}
    </div>
    <div class="mb-2">
        <label class="form-label">{$l_prompt}</label>
        <textarea class="form-control" name="system_prompt" rows="3" placeholder="{$l_ph}"></textarea>
    </div>
    <div class="d-flex justify-content-end">
        <button type="submit" name="add_category" class="btn btn-success">
            <i class="ti ti-plus"></i> {$l_add}
        </button>
    </div>
</form>
HTML;

        echo '</div></div>';
    }
}
