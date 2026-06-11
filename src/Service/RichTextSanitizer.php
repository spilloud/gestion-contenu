<?php

namespace App\Service;

/**
 * Nettoyage minimal du HTML saisi dans l’éditeur riche (gras, listes, etc.).
 */
final class RichTextSanitizer
{
    private const ALLOWED_TAGS = '<p><br><strong><b><em><i><u><ul><ol><li><h2><h3><blockquote>';

    public function sanitize(?string $html): ?string
    {
        if ($html === null) {
            return null;
        }

        $html = trim($html);
        if ($html === '' || $html === '<p><br></p>' || $html === '<p></p>') {
            return null;
        }

        $clean = trim(strip_tags($html, self::ALLOWED_TAGS));

        return $clean !== '' ? $clean : null;
    }

    public function toPlainText(?string $html): string
    {
        if ($html === null || trim($html) === '') {
            return '';
        }

        $text = html_entity_decode(strip_tags(str_replace(['</p>', '<br>', '<br/>', '<br />', '</li>'], ["\n", "\n", "\n", "\n", "\n"], $html)), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $text = preg_replace("/\n{3,}/", "\n\n", $text) ?? $text;

        return trim($text);
    }
}
