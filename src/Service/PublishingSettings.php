<?php

namespace App\Service;

use App\Entity\Client;

/**
 * Configuration de publication d'un client (box « Configuration publication » de la fiche client).
 * Stockée en JSON sur le client, lue par Lucy dans l'export pour préparer les brouillons Metricool.
 * Le CMS n'appelle jamais Metricool.
 */
final class PublishingSettings
{
    /** Heures proposées dans les menus (zone Metricool, heure par défaut). */
    public const HOURS = [
        '07:00', '07:30', '08:00', '08:30', '09:00', '09:30', '10:00', '10:30', '11:00', '11:30',
        '12:00', '12:30', '13:00', '14:00', '15:00', '16:00', '17:00', '18:00', '19:00', '20:00', '21:00',
    ];

    public const DEFAULT_HOUR = '08:00';

    public const NETWORKS = [
        'instagram' => 'Instagram',
        'facebook' => 'Facebook',
        'linkedin' => 'LinkedIn',
        'tiktok' => 'TikTok',
        'youtube' => 'YouTube',
        'gbp' => 'Google Business',
    ];

    public const YOUTUBE_PRIVACY = [
        'public' => 'Publique',
        'unlisted' => 'Non répertoriée',
        'private' => 'Privée',
    ];

    /**
     * @return array<string, mixed>
     */
    public static function defaults(): array
    {
        return [
            'metricool' => [
                'brand' => '',
            ],
            'defaultTime' => self::DEFAULT_HOUR,
            'networks' => array_fill_keys(array_keys(self::NETWORKS), false),
            'common' => [
                'captionFooter' => '',
                'hashtags' => '',
            ],
            'instagram' => [
                'collaborators' => [],
                'firstComment' => '',
            ],
            'youtube' => [
                'titleTemplate' => '{titre}',
                'descriptionTemplate' => '{legende}',
                'tags' => '',
                'playlist' => '',
                'privacy' => 'public',
                'madeForKids' => false,
            ],
            'notes' => '',
        ];
    }

    /**
     * Réglages effectifs du client (valeurs par défaut complétées).
     *
     * @return array<string, mixed>
     */
    public static function forClient(Client $client): array
    {
        return self::normalize($client->getPublishingSettings() ?? []);
    }

    /**
     * Heure par défaut du client pour la zone Metricool.
     */
    public static function defaultTimeFor(?Client $client): string
    {
        return $client !== null ? self::forClient($client)['defaultTime'] : self::DEFAULT_HOUR;
    }

    /**
     * Nettoie une saisie (formulaire ou JSON stocké) et complète avec les valeurs par défaut.
     *
     * @param array<string, mixed> $input
     *
     * @return array<string, mixed>
     */
    public static function normalize(array $input): array
    {
        $d = self::defaults();
        $text = static fn ($v, int $max = 2000): string => mb_substr(trim((string) (is_scalar($v) ? $v : '')), 0, $max);
        $bool = static fn ($v): bool => $v === true || $v === '1' || $v === 1 || $v === 'on';

        $time = $text($input['defaultTime'] ?? '');
        $networks = [];
        foreach (array_keys(self::NETWORKS) as $key) {
            $networks[$key] = $bool($input['networks'][$key] ?? false);
        }

        $collaborators = $input['instagram']['collaborators'] ?? [];
        if (is_string($collaborators)) {
            $collaborators = preg_split('/[\s,;]+/', $collaborators) ?: [];
        }
        $collaborators = array_values(array_unique(array_filter(array_map(
            static fn ($h): string => ltrim(trim((string) $h), '@'),
            is_array($collaborators) ? $collaborators : [],
        ), static fn (string $h): bool => $h !== '')));

        $privacy = $text($input['youtube']['privacy'] ?? '');

        return [
            'metricool' => [
                'brand' => $text($input['metricool']['brand'] ?? '', 255),
            ],
            'defaultTime' => in_array($time, self::HOURS, true) ? $time : $d['defaultTime'],
            'networks' => $networks,
            'common' => [
                'captionFooter' => $text($input['common']['captionFooter'] ?? ''),
                'hashtags' => $text($input['common']['hashtags'] ?? '', 1000),
            ],
            'instagram' => [
                'collaborators' => array_slice($collaborators, 0, 5),
                'firstComment' => $text($input['instagram']['firstComment'] ?? ''),
            ],
            'youtube' => [
                'titleTemplate' => $text($input['youtube']['titleTemplate'] ?? '', 255) ?: $d['youtube']['titleTemplate'],
                'descriptionTemplate' => $text($input['youtube']['descriptionTemplate'] ?? '', 5000) ?: $d['youtube']['descriptionTemplate'],
                'tags' => $text($input['youtube']['tags'] ?? '', 500),
                'playlist' => $text($input['youtube']['playlist'] ?? '', 255),
                'privacy' => array_key_exists($privacy, self::YOUTUBE_PRIVACY) ? $privacy : $d['youtube']['privacy'],
                'madeForKids' => $bool($input['youtube']['madeForKids'] ?? false),
            ],
            'notes' => $text($input['notes'] ?? '', 5000),
        ];
    }
}
