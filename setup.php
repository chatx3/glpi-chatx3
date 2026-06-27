<?php

/**
 * ChatX3 — Plugin GLPI de qualification automatique des tickets via le service ChatX3.
 *
 * @license   GPLv3+
 * @link      https://plugins.glpi-project.org
 */

define('PLUGIN_CHATX3_VERSION', '0.1.0');

// Bornes de compatibilité GLPI (cf. SPECS §4 : GLPI 11 uniquement).
define('PLUGIN_CHATX3_MIN_GLPI', '11.0.0');
define('PLUGIN_CHATX3_MAX_GLPI', '11.99.99');

/**
 * Initialisation du plugin (appelée à chaque chargement de page).
 */
function plugin_init_chatx3(): void
{
    /** @var array $PLUGIN_HOOKS */
    global $PLUGIN_HOOKS;

    // Les formulaires du plugin embarquent le jeton CSRF de GLPI.
    $PLUGIN_HOOKS['csrf_compliant']['chatx3'] = true;

    // Lien « Configurer » affiché dans Configuration > Plugins.
    $PLUGIN_HOOKS['config_page']['chatx3'] = 'front/config.form.php';

    // À la création d'un ticket : enfiler pour qualification ChatX3.
    $PLUGIN_HOOKS['item_add']['chatx3'] = [
        'Ticket' => 'plugin_chatx3_ticket_add',
    ];

    // À la modification d'un ticket : rattrape le cas où la catégorie (éligible)
    // est renseignée APRÈS la création.
    $PLUGIN_HOOKS['item_update']['chatx3'] = [
        'Ticket' => 'plugin_chatx3_ticket_update',
    ];
}

/**
 * Métadonnées du plugin (nom, version, compatibilité).
 */
function plugin_version_chatx3(): array
{
    return [
        'name'         => 'ChatX3',
        'version'      => PLUGIN_CHATX3_VERSION,
        'author'       => '<a href="https://intellx.chat">IntellX</a>',
        'license'      => 'GPLv3+',
        'homepage'     => 'https://intellx.chat',
        'requirements' => [
            'glpi' => [
                'min' => PLUGIN_CHATX3_MIN_GLPI,
                'max' => PLUGIN_CHATX3_MAX_GLPI,
            ],
            'php' => [
                'min' => '8.2',
            ],
        ],
    ];
}

/**
 * Prérequis techniques avant installation.
 */
function plugin_chatx3_check_prerequisites(): bool
{
    return true;
}

/**
 * Vérifie que la configuration minimale est présente.
 */
function plugin_chatx3_check_config($verbose = false): bool
{
    return true;
}
