<?php

/**
 * Page de configuration du plugin ChatX3.
 *
 * Sous GLPI 11, cette page est chargée par le routeur (public/index.php) une
 * fois le noyau initialisé : inutile d'inclure `inc/includes.php`.
 *
 * @license GPLv3+
 */

use GlpiPlugin\Chatx3\Category;
use GlpiPlugin\Chatx3\Config as Chatx3Config;

// Droit requis : gestion de la configuration GLPI.
Session::checkRight('config', UPDATE);

// Traitement des formulaires (POST).
// NB : la vérification CSRF est assurée en amont par le middleware GLPI 11
// (CheckCsrfListener) pour toute requête POST d'une page déclarée
// `csrf_compliant`. Inutile (et nuisible) de la refaire ici : le middleware
// consomme déjà le jeton.
if (isset($_POST['update'])) {
    Chatx3Config::saveFromInput($_POST);
    Session::addMessageAfterRedirect(htmlescape(__('Configuration saved.', 'chatx3')), false, INFO);
    Html::back();
} elseif (isset($_POST['add_category']) || isset($_POST['save_category'])) {
    Category::save((int) ($_POST['itilcategories_id'] ?? 0), (string) ($_POST['system_prompt'] ?? ''));
    Session::addMessageAfterRedirect(htmlescape(__('Category saved.', 'chatx3')), false, INFO);
    Html::back();
} elseif (isset($_POST['delete_category'])) {
    Category::delete((int) ($_POST['category_row_id'] ?? 0));
    Session::addMessageAfterRedirect(htmlescape(__('Category removed.', 'chatx3')), false, INFO);
    Html::back();
}

Html::header(
    __('ChatX3', 'chatx3'),
    $_SERVER['PHP_SELF'],
    'config',
    'plugins'
);

Chatx3Config::showForm();
Category::renderConfigSection();

Html::footer();
