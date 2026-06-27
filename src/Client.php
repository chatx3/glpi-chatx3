<?php

/**
 * Client de l'API ChatX3.
 *
 * Conforme à la documentation « ChatX3 API — Quick Start Guide v1 » :
 *   POST {endpoint}
 *   Header : x-api-key: <clé>
 *   Body   : { "message_content": "..." }
 *   Réponse 200 : { success, message (Markdown), message_id, conversation_id }
 *
 * NB : l'API réelle est SYNCHRONE en un seul appel (20–120 s), sans session_id,
 * sans polling, et ne renvoie pas de score de confiance. Cela diffère de
 * l'architecture asynchrone à deux appels décrite dans les SPECS §5 — point à
 * arbitrer avant d'implémenter la file et la CronTask.
 *
 * @license GPLv3+
 */

namespace GlpiPlugin\Chatx3;

use GuzzleHttp\Client as GuzzleClient;
use GuzzleHttp\Exception\GuzzleException;
use Toolbox;

class Client
{
    private string $url;
    private string $api_key;
    private int $timeout;

    public function __construct(?string $url = null, ?string $api_key = null, ?int $timeout = null)
    {
        $this->url     = $url     ?? Config::getApiUrl();
        $this->api_key = $api_key ?? Config::getApiKey();
        $this->timeout = $timeout ?? Config::getTimeout();
    }

    /**
     * Indique si une clé API est configurée.
     */
    public function hasApiKey(): bool
    {
        return $this->api_key !== '';
    }

    /**
     * Envoie une question à ChatX3 et retourne la réponse décodée.
     *
     * @param string      $message_content Texte du ticket (prompt + contenu).
     * @param list<array> $images          Images à transmettre. IGNORÉ pour
     *        l'instant : l'API ChatX3 ne gère pas encore les fichiers. Point
     *        d'extension : dès que le contrat d'upload sera publié, formater
     *        ces images ici (base64 / multipart selon l'API) — chaque entrée
     *        contient au moins `filepath`, `mime`, `filename` (cf. Attachment).
     *
     * @return array{success: bool, message?: string, error?: string,
     *               message_id?: string, conversation_id?: string,
     *               http_status?: int, rate_limited?: bool}
     */
    public function ask(string $message_content, array $images = []): array
    {
        if (!$this->hasApiKey()) {
            return ['success' => false, 'error' => __('No ChatX3 API key configured.', 'chatx3')];
        }

        $payload = ['message_content' => $message_content];

        // TODO(api-files) : quand ChatX3 acceptera les fichiers, ajouter ici les
        // images au payload selon le format documenté. Volontairement inactif
        // tant que le contrat n'est pas publié, pour ne pas envoyer un champ
        // inconnu qui ferait échouer la requête.
        // if (!empty($images)) { $payload['images'] = self::encodeImages($images); }

        try {
            $client = new GuzzleClient([
                'timeout'         => max($this->timeout, 120),
                'connect_timeout' => 10,
                'http_errors'     => false,
            ]);

            $response = $client->post($this->url, [
                'headers' => [
                    'x-api-key'    => $this->api_key,
                    'Content-Type' => 'application/json',
                    'Accept'       => 'application/json',
                ],
                'json' => $payload,
            ]);

            $status = $response->getStatusCode();
            $body   = (string) $response->getBody();
            $data   = json_decode($body, true);

            if (!is_array($data)) {
                return [
                    'success'     => false,
                    'error'       => __('Invalid response from ChatX3.', 'chatx3'),
                    'http_status' => $status,
                ];
            }

            $data['http_status'] = $status;

            return $data;
        } catch (GuzzleException $e) {
            Toolbox::logError('[chatx3] ' . $e->getMessage());

            return ['success' => false, 'error' => $e->getMessage()];
        }
    }
}
