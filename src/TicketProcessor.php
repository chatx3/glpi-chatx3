<?php

/**
 * Logique métier de qualification d'un ticket via ChatX3.
 *
 * v1 — « note interne seule » : on appelle ChatX3 avec le contenu du ticket et
 * on ajoute le résultat en note privée (visible technicien). Pas de réponse
 * publique au demandeur tant que l'API ne fournit pas de score de confiance.
 *
 * @license GPLv3+
 */

namespace GlpiPlugin\Chatx3;

use Glpi\RichText\RichText;
use Glpi\Toolbox\MarkdownRenderer;
use ITILFollowup;
use Ticket;

class TicketProcessor
{
    /** Marqueur HTML invisible (anti-retraitement / repérage des notes du bot). */
    public const MARKER = '<!-- chatx3 -->';

    /** Statuts de ticket pour lesquels on ne qualifie plus (résolu / clos). */
    private const SKIPPED_STATUSES = [Ticket::SOLVED, Ticket::CLOSED];

    /**
     * Traite un ticket : appel ChatX3 + ajout de la note interne.
     *
     * @return array{success: bool, error?: string, rate_limited?: bool}
     */
    public static function process(int $tickets_id): array
    {
        $ticket = new Ticket();
        if (!$ticket->getFromDB($tickets_id)) {
            return ['success' => false, 'error' => sprintf('Ticket %d introuvable', $tickets_id)];
        }

        // Ticket déjà résolu/clos entre-temps : rien à faire, on clôt la file.
        if (in_array((int) $ticket->fields['status'], self::SKIPPED_STATUSES, true)) {
            return ['success' => true];
        }

        $category_id = (int) ($ticket->fields['itilcategories_id'] ?? 0);

        // Catégorie hors périmètre (filtre activé) : on n'appelle pas ChatX3.
        if (!Category::shouldProcess($category_id)) {
            return ['success' => true];
        }

        // Pièces jointes (captures d'écran, PDF…) : inventoriées et dédupliquées.
        $attachments = Attachment::collect($tickets_id);

        $system_prompt = Category::getSystemPrompt($category_id);
        $message       = self::buildMessage($ticket, $system_prompt, $attachments);
        if ($message === '') {
            return ['success' => false, 'error' => 'Contenu de ticket vide'];
        }

        // Forward-compat : la liste d'images est prête, le client ne l'enverra
        // réellement que lorsque l'API ChatX3 acceptera les fichiers.
        $images = Config::sendAttachmentsToApi() ? Attachment::getForwardableImages($attachments) : [];

        $response = (new Client())->ask($message, $images);

        if (empty($response['success'])) {
            return [
                'success'      => false,
                'error'        => (string) ($response['error'] ?? 'Réponse ChatX3 invalide'),
                'rate_limited' => !empty($response['rate_limited']) || (($response['http_status'] ?? 0) === 429),
            ];
        }

        $markdown = trim((string) ($response['message'] ?? ''));
        if ($markdown === '') {
            return ['success' => false, 'error' => 'Réponse ChatX3 vide'];
        }

        self::addInternalNote($tickets_id, $markdown, $attachments);

        return ['success' => true];
    }

    /**
     * Construit le texte envoyé à ChatX3 à partir du ticket.
     *
     * L'API n'a pas de mémoire de conversation : on fournit tout le contexte
     * (prompt système + titre + description) dans un seul message.
     */
    public static function buildMessage(Ticket $ticket, string $system_prompt = '', array $attachments = []): string
    {
        $title = trim((string) ($ticket->fields['name'] ?? ''));
        $body  = RichText::getTextFromHtml((string) ($ticket->fields['content'] ?? ''), false, true);
        $body  = trim($body);

        $parts = [];
        if ($title !== '') {
            $parts[] = 'Titre : ' . $title;
        }
        if ($body !== '') {
            $parts[] = "Description :\n" . $body;
        }

        // Contexte sur les pièces jointes (noms de fichiers) : non transmises,
        // mais le nom porte parfois un code d'erreur utile.
        $attachment_context = Attachment::messageContext($attachments);
        if ($attachment_context !== '') {
            $parts[] = $attachment_context;
        }

        $ticket_block = implode("\n\n", $parts);

        $system_prompt = trim($system_prompt);
        if ($system_prompt !== '' && $ticket_block !== '') {
            return $system_prompt . "\n\n" . $ticket_block;
        }

        return $system_prompt !== '' ? $system_prompt : $ticket_block;
    }

    /**
     * Ajoute la note interne (privée) de qualification au ticket.
     *
     * @param list<array> $attachments Pièces jointes inventoriées (pour le résumé).
     */
    private static function addInternalNote(int $tickets_id, string $markdown, array $attachments = []): void
    {
        $html = (new MarkdownRenderer())->render($markdown);

        $header = '<p><strong>'
            . htmlspecialchars(__('Automatic qualification — ChatX3', 'chatx3'), ENT_QUOTES)
            . '</strong></p>';

        // Résumé des pièces jointes non analysées (captures d'écran, PDF…).
        $attachment_html = Attachment::noteSummaryHtml($attachments);

        $content = self::MARKER . $header . $html . $attachment_html;

        $input = [
            'itemtype'   => 'Ticket',
            'items_id'   => $tickets_id,
            'content'    => $content,
            'is_private' => 1,
        ];

        $bot_user = Config::getBotUserId();
        if ($bot_user > 0) {
            $input['users_id'] = $bot_user;
        }

        (new ITILFollowup())->add($input);
    }
}
