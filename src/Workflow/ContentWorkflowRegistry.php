<?php

namespace App\Workflow;

use App\Entity\Content;
use App\Entity\Status;
use App\Repository\ContentActionLogRepository;
use App\Service\ContentFormatHelper;

/**
 * Parcours statuts validés par l'équipe (boutons d'avancement + recul).
 */
final class ContentWorkflowRegistry
{
    /**
     * Certains statuts existent en double (legacy / synonymes) mais représentent la même étape.
     * On les "aplatit" pour que le recul revienne à l'étape métier précédente.
     */
    private const VIDEO_EQUIVALENT_STATUS_GROUPS = [
        // Étape "Client" (deux libellés possibles) → étape précédente = validation CM
        ['À valider (Client)', 'À faire valider au client'],
    ];

    /** Statuts conservés en base mais hors parcours standard (recul, barre de progression). */
    private const VIDEO_LEGACY_STEP_STATUSES = [
        'Brouillon (Dérush)',
        'Rushs / à dispatcher',
        'Montage en cours',
    ];

    /**
     * Phases affichées dans la barre de progression (fiche vidéo).
     *
     * @var list<array{label: string, statuses: list<string>}>
     */
    public const VIDEO_PHASES = [
        ['label' => 'Planif.', 'statuses' => ['Tournage à prévoir']],
        ['label' => 'Montage', 'statuses' => ['Montage à faire', 'Retouches (Monteur)', 'Montage en cours', 'Brouillon (Dérush)', 'Rushs / à dispatcher']],
        ['label' => 'Production', 'statuses' => ['À valider (Prod)', 'Sous-titrage (SubMagic)', 'Prépa CM (sans sous-titres)']],
        ['label' => 'Qualité', 'statuses' => ['Sous-titres à valider', 'À valider (CM)']],
        ['label' => 'Client', 'statuses' => ['À valider (Client)', 'À faire valider au client']],
        ['label' => 'Diffusion', 'statuses' => ['Prête à programmer', 'Programmée', 'Publiée']],
    ];

    /** @var list<string> */
    public const VIDEO_ORDER = [
        'Tournage à prévoir',
        'Brouillon (Dérush)',
        'Rushs / à dispatcher',
        'Montage à faire',
        'Montage en cours',
        'Retouches (Monteur)',
        'À valider (Prod)',
        'Sous-titrage (SubMagic)',
        'Prépa CM (sans sous-titres)',
        'Sous-titres à valider',
        'À valider (CM)',
        'À valider (Client)',
        'À faire valider au client',
        'Prête à programmer',
        'Programmée',
        'Publiée',
    ];

    /**
     * @var list<array{label: string, statuses: list<string>}>
     */
    public const STANDARD_PHASES = [
        ['label' => 'Idée', 'statuses' => ['Brouillon (idée)']],
        ['label' => 'Préparation', 'statuses' => ['En préparation']],
        ['label' => 'Validation', 'statuses' => ['À valider (post)']],
        ['label' => 'Diffusion', 'statuses' => ['Prêt à publier', 'Publiée']],
    ];

    /** @var list<string> */
    public const STANDARD_ORDER = [
        'Brouillon (idée)',
        'En préparation',
        'À valider (post)',
        'Prêt à publier',
        'Publiée',
    ];

    /**
     * Statuts proposés dans les menus déroulants (filtres, correction manuelle).
     * Exclut les étapes legacy vidéo et les anciens libellés hors parcours actuel.
     *
     * @return list<string>
     */
    public static function selectableStatusNames(string $workflow): array
    {
        if ($workflow === Status::WORKFLOW_VIDEO) {
            return array_values(array_filter(
                self::VIDEO_ORDER,
                static fn (string $name): bool => !in_array($name, self::VIDEO_LEGACY_STEP_STATUSES, true),
            ));
        }

        return self::STANDARD_ORDER;
    }

    public function __construct(
        private readonly ContentFormatHelper $formatHelper,
        private readonly ContentActionLogRepository $actionLogRepository,
    ) {
    }

    /**
     * @return list<string>
     */
    public function orderedStatusNamesFor(Content $content): array
    {
        return $this->formatHelper->isVideoContent($content)
            ? self::VIDEO_ORDER
            : self::STANDARD_ORDER;
    }

    public function currentVideoPhaseIndex(?string $statusName): int
    {
        return $this->phaseIndexIn(self::VIDEO_PHASES, $statusName);
    }

    public function currentStandardPhaseIndex(?string $statusName): int
    {
        return $this->phaseIndexIn(self::STANDARD_PHASES, $statusName);
    }

    /**
     * @return list<array{label: string, statuses: list<string>}>
     */
    public function phasesFor(Content $content): array
    {
        return $this->formatHelper->isVideoContent($content)
            ? self::VIDEO_PHASES
            : self::STANDARD_PHASES;
    }

    public function phaseIndexFor(Content $content): int
    {
        $statusName = $content->getStatus()?->getName();

        return $this->formatHelper->isVideoContent($content)
            ? $this->currentVideoPhaseIndex($statusName)
            : $this->currentStandardPhaseIndex($statusName);
    }

    /**
     * @param list<array{label: string, statuses: list<string>}> $phases
     */
    private function phaseIndexIn(array $phases, ?string $statusName): int
    {
        if ($statusName === null || $statusName === '') {
            return 0;
        }

        foreach ($phases as $index => $phase) {
            if (in_array($statusName, $phase['statuses'], true)) {
                return $index;
            }
        }

        return 0;
    }

    public function previousStatusName(Content $content): ?string
    {
        $current = $content->getStatus()?->getName();
        if ($current === null || $current === '') {
            return null;
        }

        // Si on est sur un statut "équivalent" vidéo, on force le recul vers l'étape précédente.
        if ($this->formatHelper->isVideoContent($content)) {
            foreach (self::VIDEO_EQUIVALENT_STATUS_GROUPS as $group) {
                if (in_array($current, $group, true)) {
                    return 'À valider (CM)';
                }
            }
        }

        $fromJournal = $this->actionLogRepository->resolvePreviousStatusName($content);
        if ($this->formatHelper->isVideoContent($content)) {
            $fromJournal = $this->normalizeVideoStepBackCandidate($content, $fromJournal);
        }
        if ($fromJournal !== null) {
            return $fromJournal;
        }

        $order = $this->orderedStatusNamesFor($content);
        $index = array_search($current, $order, true);
        if ($index === false || $index <= 0) {
            return null;
        }

        if ($this->formatHelper->isVideoContent($content)) {
            return $this->walkBackInVideoOrder($order, $index);
        }

        return $order[$index - 1];
    }

    /**
     * Évite les étapes legacy ou « fantômes » (ex. Montage à faire jamais enregistré avant Montage en cours).
     */
    private function normalizeVideoStepBackCandidate(Content $content, ?string $candidate): ?string
    {
        if ($candidate === null) {
            return null;
        }

        if (in_array($candidate, self::VIDEO_LEGACY_STEP_STATUSES, true)) {
            $index = array_search($candidate, self::VIDEO_ORDER, true);

            return $index !== false ? $this->walkBackInVideoOrder(self::VIDEO_ORDER, $index) : null;
        }

        if (
            $candidate === 'Montage à faire'
            && !$this->actionLogRepository->hasTransitionToStatus($content, 'Montage à faire')
        ) {
            $index = array_search('Montage à faire', self::VIDEO_ORDER, true);

            return $index !== false ? $this->walkBackInVideoOrder(self::VIDEO_ORDER, $index) : null;
        }

        return $candidate;
    }

    /**
     * @param list<string> $order
     */
    private function walkBackInVideoOrder(array $order, int $index): ?string
    {
        while ($index > 0) {
            --$index;
            $candidate = $order[$index];
            if (in_array($candidate, self::VIDEO_LEGACY_STEP_STATUSES, true)) {
                continue;
            }

            return $candidate;
        }

        return null;
    }

    /**
     * @return list<array{id: string, label: string, variant: string, group: ?string, subtitle: ?string}>
     */
    public function availableActions(Content $content): array
    {
        $statusName = $content->getStatus()?->getName() ?? '';

        if ($this->formatHelper->isVideoContent($content)) {
            return $this->videoActions($content, $statusName);
        }

        return $this->standardActions($statusName);
    }

    /**
     * @return array{from: list<string>, to: string, label: string, effects: list<string>}|null
     */
    public function getTransition(string $actionId, Content $content): ?array
    {
        if ($this->formatHelper->isVideoContent($content)) {
            return $this->videoTransitions()[$actionId] ?? null;
        }

        return $this->standardTransitions()[$actionId] ?? null;
    }

    /**
     * @return list<array{id: string, label: string, variant: string, group: ?string}>
     */
    private function standardActions(string $statusName): array
    {
        return match ($statusName) {
            'Brouillon (idée)' => [
                ['id' => 'to_preparation', 'label' => 'Passer en préparation', 'variant' => 'primary', 'group' => null],
            ],
            'En préparation' => [
                ['id' => 'to_validation', 'label' => 'Envoyer en validation', 'variant' => 'primary', 'group' => null],
            ],
            'À valider (post)' => [
                ['id' => 'to_ready', 'label' => 'Marquer prêt à publier', 'variant' => 'primary', 'group' => null],
            ],
            'Prêt à publier' => [
                ['id' => 'to_published', 'label' => 'Marquer comme publié', 'variant' => 'success', 'group' => null],
            ],
            default => [],
        };
    }

    /**
     * @return list<array{id: string, label: string, variant: string, group: ?string, subtitle: ?string}>
     */
    private function videoActions(Content $content, string $statusName): array
    {
        return match ($statusName) {
            'Tournage à prévoir' => [
                [
                    'id' => 'montage_request',
                    'label' => 'Demande de montage (créer la tâche)',
                    'subtitle' => 'Après le tournage : rushs sur le KDrive. Crée la tâche Asana pour le monteur assigné.',
                    'variant' => 'primary',
                    'group' => null,
                ],
            ],
            'Brouillon (Dérush)', 'Rushs / à dispatcher' => [
                [
                    'id' => 'montage_request',
                    'label' => 'Demande de montage (créer la tâche)',
                    'subtitle' => 'Crée la tâche Asana pour le monteur assigné.',
                    'variant' => 'primary',
                    'group' => null,
                ],
            ],
            'Montage à faire', 'Montage en cours' => [
                [
                    'id' => 'montage_done',
                    'label' => 'Montage terminé',
                    'subtitle' => 'Clôture la tâche Asana montage. Fichier prêt pour la validation prod.',
                    'variant' => 'primary',
                    'group' => null,
                ],
                [
                    'id' => 'retouches',
                    'label' => 'Demande de retouches',
                    'subtitle' => 'Le monteur doit corriger avant validation prod.',
                    'variant' => 'secondary',
                    'group' => null,
                ],
            ],
            'Retouches (Monteur)' => [
                [
                    'id' => 'montage_done',
                    'label' => 'Montage terminé',
                    'subtitle' => 'Clôture la tâche Asana montage après retouches.',
                    'variant' => 'primary',
                    'group' => null,
                ],
            ],
            'À valider (Prod)' => [
                [
                    'id' => 'subtitles_yes',
                    'label' => 'Avec sous-titres (SubMagic)',
                    'subtitle' => null,
                    'variant' => 'primary',
                    'group' => 'subtitles',
                ],
                [
                    'id' => 'subtitles_no',
                    'label' => 'Sans sous-titres',
                    'subtitle' => null,
                    'variant' => 'secondary',
                    'group' => 'subtitles',
                ],
            ],
            'Sous-titrage (SubMagic)' => [
                [
                    'id' => 'subtitles_review',
                    'label' => 'Sous-titres à valider (CM)',
                    'subtitle' => 'Crée la tâche Asana pour la CM.',
                    'variant' => 'primary',
                    'group' => null,
                ],
            ],
            'Prépa CM (sans sous-titres)' => [
                [
                    'id' => 'cm_validation',
                    'label' => 'À valider (CM)',
                    'subtitle' => null,
                    'variant' => 'primary',
                    'group' => null,
                ],
            ],
            'Sous-titres à valider' => [
                [
                    'id' => 'subtitles_validated',
                    'label' => 'Sous-titres validés',
                    'subtitle' => null,
                    'variant' => 'primary',
                    'group' => null,
                ],
            ],
            'À valider (CM)' => [
                [
                    'id' => 'client_validation',
                    'label' => 'À valider par le client',
                    'subtitle' => null,
                    'variant' => 'primary',
                    'group' => null,
                ],
            ],
            'À valider (Client)', 'À faire valider au client' => [
                [
                    'id' => 'ready_to_schedule',
                    'label' => 'Prête à programmer',
                    'subtitle' => null,
                    'variant' => 'primary',
                    'group' => null,
                ],
            ],
            'Prête à programmer' => [
                [
                    'id' => 'scheduled',
                    'label' => 'Programmée',
                    'subtitle' => null,
                    'variant' => 'primary',
                    'group' => null,
                ],
            ],
            'Programmée' => [
                [
                    'id' => 'published',
                    'label' => 'Publiée',
                    'subtitle' => null,
                    'variant' => 'success',
                    'group' => null,
                ],
            ],
            default => [],
        };
    }

    /**
     * @return array<string, array{from: list<string>, to: string, label: string, effects: list<string>}>
     */
    private function standardTransitions(): array
    {
        return [
            'to_preparation' => [
                'from' => ['Brouillon (idée)'],
                'to' => 'En préparation',
                'label' => 'Passage en préparation',
                'effects' => [],
            ],
            'to_validation' => [
                'from' => ['En préparation'],
                'to' => 'À valider (post)',
                'label' => 'Envoi en validation',
                'effects' => [],
            ],
            'to_ready' => [
                'from' => ['À valider (post)'],
                'to' => 'Prêt à publier',
                'label' => 'Contenu prêt à publier',
                'effects' => [],
            ],
            'to_published' => [
                'from' => ['Prêt à publier'],
                'to' => 'Publiée',
                'label' => 'Contenu publié',
                'effects' => [],
            ],
        ];
    }

    /**
     * @return array<string, array{from: list<string>, to: string, label: string, effects: list<string>}>
     */
    private function videoTransitions(): array
    {
        return [
            'montage_request' => [
                'from' => ['Tournage à prévoir', 'Brouillon (Dérush)', 'Rushs / à dispatcher'],
                'to' => 'Montage à faire',
                'label' => 'Demande de montage (créer la tâche)',
                'effects' => [],
            ],
            // Transitions historiques (recul journal, anciennes fiches).
            'derush_launch_montage' => [
                'from' => ['Tournage à prévoir'],
                'to' => 'Montage à faire',
                'label' => 'Demande de montage (créer la tâche)',
                'effects' => [],
            ],
            'rushes_ready' => [
                'from' => ['Brouillon (Dérush)'],
                'to' => 'Rushs / à dispatcher',
                'label' => 'Rushs prêtes — à dispatcher',
                'effects' => [],
            ],
            'montage_queued' => [
                'from' => ['Rushs / à dispatcher'],
                'to' => 'Montage à faire',
                'label' => 'Demande de montage (créer la tâche)',
                'effects' => [],
            ],
            'montage_started' => [
                'from' => ['Montage à faire'],
                'to' => 'Montage en cours',
                'label' => 'Le monteur a démarré',
                'effects' => [],
            ],
            'retouches' => [
                'from' => ['Montage à faire', 'Montage en cours'],
                'to' => 'Retouches (Monteur)',
                'label' => 'Demande de retouches',
                'effects' => [],
            ],
            'montage_done' => [
                'from' => ['Montage à faire', 'Montage en cours', 'Retouches (Monteur)'],
                'to' => 'À valider (Prod)',
                'label' => 'Montage terminé',
                'effects' => ['complete_asana_montage'],
            ],
            'subtitles_yes' => [
                'from' => ['À valider (Prod)'],
                'to' => 'Sous-titrage (SubMagic)',
                'label' => 'Avec sous-titres (SubMagic)',
                'effects' => ['set_subtitles_yes'],
            ],
            'subtitles_no' => [
                'from' => ['À valider (Prod)'],
                'to' => 'Prépa CM (sans sous-titres)',
                'label' => 'Sans sous-titres',
                'effects' => ['set_subtitles_no'],
            ],
            'subtitles_review' => [
                'from' => ['Sous-titrage (SubMagic)'],
                'to' => 'Sous-titres à valider',
                'label' => 'Sous-titres à valider (CM)',
                'effects' => ['trigger_subtitles_asana'],
            ],
            'subtitles_validated' => [
                'from' => ['Sous-titres à valider'],
                'to' => 'À valider (CM)',
                'label' => 'Sous-titres validés',
                'effects' => ['complete_asana_subtitles'],
            ],
            'cm_validation' => [
                'from' => ['Prépa CM (sans sous-titres)'],
                'to' => 'À valider (CM)',
                'label' => 'À valider (CM)',
                'effects' => [],
            ],
            'client_validation' => [
                'from' => ['À valider (CM)'],
                'to' => 'À faire valider au client',
                'label' => 'À valider par le client',
                'effects' => [],
            ],
            'ready_to_schedule' => [
                'from' => ['À valider (Client)', 'À faire valider au client'],
                'to' => 'Prête à programmer',
                'label' => 'Prête à programmer',
                'effects' => [],
            ],
            'scheduled' => [
                'from' => ['Prête à programmer'],
                'to' => 'Programmée',
                'label' => 'Contenu programmé',
                'effects' => [],
            ],
            'published' => [
                'from' => ['Programmée'],
                'to' => 'Publiée',
                'label' => 'Vidéo publiée',
                'effects' => [],
            ],
        ];
    }

    public function isWorkflowStatus(Status $status, string $workflow): bool
    {
        $w = $status->getWorkflow();

        return $w === $workflow
            || $w === Status::WORKFLOW_BOTH
            || ($workflow === Status::WORKFLOW_STANDARD && $w === Status::WORKFLOW_VIDEO && $status->getName() === 'Publiée');
    }
}
