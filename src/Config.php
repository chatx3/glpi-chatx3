<?php

/**
 * Gestion de la configuration du plugin ChatX3.
 *
 * Les valeurs sont stockées dans la table `glpi_configs` de GLPI sous le
 * contexte `plugin:chatx3` (cf. SPECS §9). La clé API, sensible, est chiffrée
 * au repos avec la clé de sécurité GLPI (GLPIKey).
 *
 * @license GPLv3+
 */

namespace GlpiPlugin\Chatx3;

use Config as GlpiConfig;
use GLPIKey;
use Session;

class Config
{
    /** Contexte de stockage dans glpi_configs. */
    public const CONTEXT = 'plugin:chatx3';

    /** Valeurs par défaut posées à l'installation. */
    public const DEFAULTS = [
        'api_url' => 'https://akfcgzazfvqipbjvdemn.supabase.co/functions/v1/api-ask',
        'api_key' => '',
        // Timeout par appel en secondes. L'API ChatX3 peut répondre jusqu'à 120 s.
        'timeout' => '130',
        // Qualification automatique des nouveaux tickets activée (1) ou non (0).
        'enabled' => '1',
        // Compte GLPI qui poste la note interne (0 = système / aucun).
        'bot_user_id' => '0',
        // Restreindre la qualification aux catégories listées (1) ou tout traiter (0).
        'restrict_to_categories' => '0',
        // Prompt système global, utilisé quand la catégorie n'en définit pas.
        'system_prompt' => '',
        // Transmettre les images du ticket à ChatX3. Sans effet tant que l'API
        // n'accepte pas les fichiers : point d'extension prêt pour cette évolution.
        'send_attachments_to_api' => '0',
    ];

    /**
     * Pose les valeurs par défaut manquantes (idempotent : ne réécrase pas
     * une configuration existante lors d'une réinstallation).
     */
    public static function install(): void
    {
        $existing = GlpiConfig::getConfigurationValues(self::CONTEXT);

        $to_set = [];
        foreach (self::DEFAULTS as $name => $value) {
            if (!array_key_exists($name, $existing)) {
                $to_set[$name] = $value;
            }
        }

        if (!empty($to_set)) {
            GlpiConfig::setConfigurationValues(self::CONTEXT, $to_set);
        }
    }

    /**
     * Supprime toute la configuration du plugin.
     */
    public static function uninstall(): void
    {
        GlpiConfig::deleteConfigurationValues(self::CONTEXT, array_keys(self::DEFAULTS));
    }

    /**
     * Retourne l'ensemble des valeurs brutes (clé API encore chiffrée).
     */
    public static function getValues(): array
    {
        return GlpiConfig::getConfigurationValues(self::CONTEXT) + self::DEFAULTS;
    }

    /**
     * Retourne la clé API déchiffrée, ou une chaîne vide si non configurée.
     */
    public static function getApiKey(): string
    {
        $stored = self::getValues()['api_key'] ?? '';
        if ($stored === '') {
            return '';
        }

        return (string) (new GLPIKey())->decrypt($stored);
    }

    /**
     * Retourne l'URL de l'endpoint ChatX3.
     */
    public static function getApiUrl(): string
    {
        $url = trim((string) (self::getValues()['api_url'] ?? ''));

        return $url !== '' ? $url : self::DEFAULTS['api_url'];
    }

    /**
     * Retourne le timeout (secondes) à appliquer aux appels HTTP.
     */
    public static function getTimeout(): int
    {
        return max(1, (int) (self::getValues()['timeout'] ?? self::DEFAULTS['timeout']));
    }

    /**
     * Indique si la qualification automatique est activée.
     */
    public static function isEnabled(): bool
    {
        return (string) (self::getValues()['enabled'] ?? '1') === '1';
    }

    /**
     * Retourne l'ID du compte GLPI utilisé pour poster les notes (0 = aucun).
     */
    public static function getBotUserId(): int
    {
        return max(0, (int) (self::getValues()['bot_user_id'] ?? 0));
    }

    /**
     * Indique si la qualification est restreinte aux catégories configurées.
     */
    public static function isRestrictedToCategories(): bool
    {
        return (string) (self::getValues()['restrict_to_categories'] ?? '0') === '1';
    }

    /**
     * Retourne le prompt système global (peut être vide).
     */
    public static function getGlobalSystemPrompt(): string
    {
        return (string) (self::getValues()['system_prompt'] ?? '');
    }

    /**
     * Indique si l'on doit transmettre les images du ticket à ChatX3.
     *
     * Forward-compat : retourne false tant que l'API ne gère pas les fichiers
     * (le réglage existe pour être activé sans changement de code le moment venu).
     */
    public static function sendAttachmentsToApi(): bool
    {
        return (string) (self::getValues()['send_attachments_to_api'] ?? '0') === '1';
    }

    /**
     * Enregistre la configuration depuis les données POST du formulaire.
     *
     * La clé API n'est mise à jour que si l'utilisateur saisit une nouvelle
     * valeur (champ laissé vide = clé inchangée).
     */
    public static function saveFromInput(array $input): void
    {
        $to_set = [];

        if (isset($input['api_url'])) {
            $to_set['api_url'] = trim((string) $input['api_url']);
        }

        if (isset($input['timeout'])) {
            $to_set['timeout'] = (string) max(1, (int) $input['timeout']);
        }

        // Cases à cocher : absentes du POST = décochées.
        $to_set['enabled']                = !empty($input['enabled']) ? '1' : '0';
        $to_set['restrict_to_categories'] = !empty($input['restrict_to_categories']) ? '1' : '0';

        if (isset($input['bot_user_id'])) {
            $to_set['bot_user_id'] = (string) max(0, (int) $input['bot_user_id']);
        }

        if (isset($input['system_prompt'])) {
            $to_set['system_prompt'] = trim((string) $input['system_prompt']);
        }

        if (isset($input['api_key']) && trim((string) $input['api_key']) !== '') {
            $to_set['api_key'] = (new GLPIKey())->encrypt(trim((string) $input['api_key']));
        }

        if (!empty($to_set)) {
            GlpiConfig::setConfigurationValues(self::CONTEXT, $to_set);
        }
    }

    /**
     * Affiche le formulaire de configuration.
     */
    public static function showForm(): void
    {
        $values  = self::getValues();
        $api_url = htmlspecialchars((string) ($values['api_url'] ?? self::DEFAULTS['api_url']), ENT_QUOTES);
        $timeout = (int) ($values['timeout'] ?? self::DEFAULTS['timeout']);
        $bot_id  = (int) ($values['bot_user_id'] ?? 0);
        $has_key = !empty($values['api_key']);
        $enabled = (string) ($values['enabled'] ?? '1') === '1';
        $checked = $enabled ? 'checked' : '';
        $restrict_checked = (string) ($values['restrict_to_categories'] ?? '0') === '1' ? 'checked' : '';
        $sys_prompt = htmlspecialchars((string) ($values['system_prompt'] ?? ''), ENT_QUOTES);

        $csrf   = Session::getNewCSRFToken();
        $action = \Plugin::getWebDir('chatx3') . '/front/config.form.php';

        $l_title       = htmlspecialchars(__('ChatX3 configuration', 'chatx3'), ENT_QUOTES);
        $l_section_api = htmlspecialchars(__('API connection', 'chatx3'), ENT_QUOTES);
        $l_section_beh = htmlspecialchars(__('Behaviour', 'chatx3'), ENT_QUOTES);
        $l_url         = htmlspecialchars(__('API endpoint URL', 'chatx3'), ENT_QUOTES);
        $l_key         = htmlspecialchars(__('API key', 'chatx3'), ENT_QUOTES);
        $l_timeout     = htmlspecialchars(__('Request timeout (seconds)', 'chatx3'), ENT_QUOTES);
        $l_enabled     = htmlspecialchars(__('Automatically qualify new tickets', 'chatx3'), ENT_QUOTES);
        $l_enabled_h   = htmlspecialchars(__('When on, each new ticket is queued and qualified by ChatX3 (internal private note only in this version).', 'chatx3'), ENT_QUOTES);
        $l_bot         = htmlspecialchars(__('Posting user ID (optional)', 'chatx3'), ENT_QUOTES);
        $l_bot_h       = htmlspecialchars(__('GLPI user ID that authors the internal note. Leave 0 to post as system.', 'chatx3'), ENT_QUOTES);
        $l_restrict    = htmlspecialchars(__('Restrict qualification to selected categories', 'chatx3'), ENT_QUOTES);
        $l_restrict_h  = htmlspecialchars(__('When on, only tickets whose category is configured below are qualified. When off, all tickets are processed.', 'chatx3'), ENT_QUOTES);
        $l_sysprompt   = htmlspecialchars(__('Default system prompt (optional)', 'chatx3'), ENT_QUOTES);
        $l_sysprompt_h = htmlspecialchars(__('Prepended to the ticket content for categories that do not define their own prompt.', 'chatx3'), ENT_QUOTES);
        $l_save        = htmlspecialchars(_x('button', 'Save', 'chatx3'), ENT_QUOTES);
        $l_key_help    = $has_key
            ? htmlspecialchars(__('A key is already stored. Leave blank to keep it unchanged.', 'chatx3'), ENT_QUOTES)
            : htmlspecialchars(__('No key configured yet. Sent in the x-api-key header.', 'chatx3'), ENT_QUOTES);
        $l_key_status  = $has_key
            ? htmlspecialchars(__('Configured', 'chatx3'), ENT_QUOTES)
            : htmlspecialchars(__('Not configured', 'chatx3'), ENT_QUOTES);
        $key_badge = $has_key
            ? '<span class="badge bg-success">' . $l_key_status . '</span>'
            : '<span class="badge bg-secondary">' . $l_key_status . '</span>';

        echo <<<HTML
<div class="card mx-auto" style="max-width: 760px;">
    <div class="card-body">
        <h2 class="card-title mb-4">{$l_title}</h2>
        <form method="post" action="{$action}" autocomplete="off">
            <input type="hidden" name="_glpi_csrf_token" value="{$csrf}">

            <h3 class="h5 text-muted mb-3">{$l_section_api}</h3>

            <div class="mb-3">
                <label class="form-label" for="chatx3_api_url">{$l_url}</label>
                <input type="url" class="form-control" id="chatx3_api_url"
                       name="api_url" value="{$api_url}" required>
            </div>

            <div class="mb-3">
                <label class="form-label" for="chatx3_api_key">{$l_key} {$key_badge}</label>
                <input type="password" class="form-control" id="chatx3_api_key"
                       name="api_key" value="" autocomplete="new-password"
                       placeholder="••••••••••••••••">
                <div class="form-text">{$l_key_help}</div>
            </div>

            <div class="mb-4">
                <label class="form-label" for="chatx3_timeout">{$l_timeout}</label>
                <input type="number" class="form-control" id="chatx3_timeout"
                       name="timeout" value="{$timeout}" min="1" max="600" style="max-width: 160px;">
            </div>

            <hr class="my-4">
            <h3 class="h5 text-muted mb-3">{$l_section_beh}</h3>

            <div class="mb-3 form-check form-switch">
                <input type="checkbox" class="form-check-input" id="chatx3_enabled"
                       name="enabled" value="1" {$checked}>
                <label class="form-check-label" for="chatx3_enabled">{$l_enabled}</label>
                <div class="form-text">{$l_enabled_h}</div>
            </div>

            <div class="mb-3">
                <label class="form-label" for="chatx3_bot_user_id">{$l_bot}</label>
                <input type="number" class="form-control" id="chatx3_bot_user_id"
                       name="bot_user_id" value="{$bot_id}" min="0" style="max-width: 160px;">
                <div class="form-text">{$l_bot_h}</div>
            </div>

            <div class="mb-3 form-check form-switch">
                <input type="checkbox" class="form-check-input" id="chatx3_restrict"
                       name="restrict_to_categories" value="1" {$restrict_checked}>
                <label class="form-check-label" for="chatx3_restrict">{$l_restrict}</label>
                <div class="form-text">{$l_restrict_h}</div>
            </div>

            <div class="mb-4">
                <label class="form-label" for="chatx3_system_prompt">{$l_sysprompt}</label>
                <textarea class="form-control" id="chatx3_system_prompt"
                          name="system_prompt" rows="3">{$sys_prompt}</textarea>
                <div class="form-text">{$l_sysprompt_h}</div>
            </div>

            <div class="d-flex justify-content-end">
                <button type="submit" name="update" class="btn btn-primary">
                    <i class="ti ti-device-floppy me-1"></i>{$l_save}
                </button>
            </div>
        </form>
    </div>
</div>
HTML;
    }
}
