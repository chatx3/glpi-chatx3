<?php

/**
 * Collecte et préparation des pièces jointes d'un ticket pour ChatX3.
 *
 * État de l'API : ChatX3 ne traite PAS encore les fichiers/images
 * (« File-based input is planned but not yet available »). Cette classe :
 *   - inventorie les documents liés au ticket (jointes ET images collées dans
 *     la description, toutes stockées comme `Document` par GLPI),
 *   - les déduplique (empreinte SHA1) et les trie images / autres documents,
 *   - produit un résumé lisible pour la note interne et un contexte court pour
 *     le message,
 *   - expose `getForwardableImages()` : la liste prête à être envoyée le jour où
 *     l'API acceptera les fichiers (cf. Client::ask()).
 *
 * @license GPLv3+
 */

namespace GlpiPlugin\Chatx3;

use Document_Item;

class Attachment
{
    /** Nombre maximal d'images que l'on transmettrait à l'API (garde-fou payload). */
    public const MAX_IMAGES = 5;

    /** Taille maximale par image transmissible (octets). */
    public const MAX_IMAGE_SIZE = 5 * 1024 * 1024;

    /** Types MIME image que l'on saurait transmettre à une API vision. */
    private const FORWARDABLE_IMAGE_MIMES = ['image/png', 'image/jpeg', 'image/webp', 'image/gif'];

    /**
     * Inventorie les pièces jointes d'un ticket, dédupliquées par SHA1.
     *
     * @return list<array{id:int, filename:string, mime:string, sha1sum:string,
     *                    filepath:string, is_image:bool, size:int}>
     */
    public static function collect(int $tickets_id): array
    {
        /** @var \DBmysql $DB */
        global $DB;

        if ($tickets_id <= 0) {
            return [];
        }

        $iterator = $DB->request([
            'SELECT'     => [
                'glpi_documents.id AS id',
                'glpi_documents.filename AS filename',
                'glpi_documents.mime AS mime',
                'glpi_documents.sha1sum AS sha1sum',
                'glpi_documents.filepath AS filepath',
            ],
            'FROM'       => Document_Item::getTable(),
            'INNER JOIN' => [
                'glpi_documents' => [
                    'ON' => [
                        Document_Item::getTable() => 'documents_id',
                        'glpi_documents'          => 'id',
                    ],
                ],
            ],
            'WHERE'      => [
                Document_Item::getTable() . '.itemtype' => 'Ticket',
                Document_Item::getTable() . '.items_id' => $tickets_id,
            ],
            'ORDER'      => 'glpi_documents.id ASC',
        ]);

        $seen        = [];
        $attachments = [];

        foreach ($iterator as $row) {
            $sha = (string) ($row['sha1sum'] ?? '');
            // Déduplication : même fichier collé + joint = une seule entrée.
            if ($sha !== '' && isset($seen[$sha])) {
                continue;
            }
            if ($sha !== '') {
                $seen[$sha] = true;
            }

            $mime = (string) ($row['mime'] ?? '');
            $path = (string) ($row['filepath'] ?? '');
            $full = defined('GLPI_DOC_DIR') ? GLPI_DOC_DIR . '/' . $path : '';
            $size = ($full !== '' && is_file($full)) ? (int) filesize($full) : 0;

            $attachments[] = [
                'id'       => (int) $row['id'],
                'filename' => (string) ($row['filename'] ?? ''),
                'mime'     => $mime,
                'sha1sum'  => $sha,
                'filepath' => $path,
                'is_image' => str_starts_with($mime, 'image/'),
                'size'     => $size,
            ];
        }

        return $attachments;
    }

    /**
     * Filtre les images.
     *
     * @param list<array> $attachments
     * @return list<array>
     */
    public static function images(array $attachments): array
    {
        return array_values(array_filter($attachments, static fn($a) => $a['is_image']));
    }

    /**
     * Filtre les documents non-image (PDF, bureautique, …).
     *
     * @param list<array> $attachments
     * @return list<array>
     */
    public static function others(array $attachments): array
    {
        return array_values(array_filter($attachments, static fn($a) => !$a['is_image']));
    }

    /**
     * Liste des images réellement transmissibles à l'API (format + taille + nombre).
     * Prête pour le jour où ChatX3 acceptera les fichiers.
     *
     * @param list<array> $attachments
     * @return list<array>
     */
    public static function getForwardableImages(array $attachments): array
    {
        $forwardable = array_filter(
            self::images($attachments),
            static fn($a) => in_array($a['mime'], self::FORWARDABLE_IMAGE_MIMES, true)
                && $a['size'] > 0
                && $a['size'] <= self::MAX_IMAGE_SIZE
        );

        return array_slice(array_values($forwardable), 0, self::MAX_IMAGES);
    }

    /**
     * Libellé court d'un type de fichier à partir du MIME (« image PNG », « PDF »…).
     */
    public static function typeLabel(string $mime): string
    {
        return match (true) {
            $mime === 'application/pdf'              => 'PDF',
            str_starts_with($mime, 'image/')         => 'image ' . strtoupper(substr($mime, 6)),
            str_contains($mime, 'word')              => 'Word',
            str_contains($mime, 'sheet'),
            str_contains($mime, 'excel')             => 'Excel',
            $mime === 'text/plain'                   => 'texte',
            $mime === ''                             => 'inconnu',
            default                                  => $mime,
        };
    }

    /**
     * Résumé HTML des pièces jointes pour la note interne.
     * Retourne une chaîne vide s'il n'y en a aucune.
     *
     * @param list<array> $attachments
     */
    public static function noteSummaryHtml(array $attachments): string
    {
        if (empty($attachments)) {
            return '';
        }

        $count = count($attachments);
        $title = sprintf(
            _n(
                '%d attachment — not analysed by ChatX3 (the API does not handle files yet)',
                '%d attachments — not analysed by ChatX3 (the API does not handle files yet)',
                $count,
                'chatx3'
            ),
            $count
        );

        $items = '';
        foreach ($attachments as $a) {
            $name = htmlspecialchars($a['filename'], ENT_QUOTES);
            $type = htmlspecialchars(self::typeLabel($a['mime']), ENT_QUOTES);
            $items .= "<li>{$name} — {$type}</li>";
        }

        return '<p><strong>📎 ' . htmlspecialchars($title, ENT_QUOTES) . '</strong></p><ul>' . $items . '</ul>';
    }

    /**
     * Ligne de contexte (texte brut) ajoutée au message envoyé à ChatX3 :
     * les noms de fichiers portent parfois une information utile (code d'erreur).
     *
     * @param list<array> $attachments
     */
    public static function messageContext(array $attachments): string
    {
        if (empty($attachments)) {
            return '';
        }

        $names = array_map(static fn($a) => $a['filename'], $attachments);

        return "Pièces jointes au ticket (non transmises, l'assistant ne peut pas les ouvrir) : "
            . implode(', ', $names);
    }
}
