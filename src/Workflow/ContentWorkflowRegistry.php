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
     * Phases affichées dans la barre de progression (fiche vidéo).
     *
     * @var list<array{label: string, statuses: list<string>}>
     */
    public const VIDEO_PHASES = [
        ['label' => 'Planif.', 'statuses' => ['Tournage à prévoir']],
        ['label' => 'Montage', 'statuses' => ['Montage à faire']],
        ['label' => 'Qualité', 'statuses' => ['Sous-titres à valider', 'À valider (CM)']],
        ['label' => 'Client', 'statuses' => ['À valider (Client)']],
        ['label' => 'Diffusion', 'statuses' => ['Prête à programmer', 'Programmée', 'Publiée']],
    ];

    /**
     * Parcours vidéo (8 statuts). « Sous-titres à valider » seulement si la vidéo a des sous-titres.
     *
     * @var list<string>
     */
    public const VIDEO_ORDER = [
        'Tournage à prévoir',
        'Montage à faire',
        'Sous-titres à valider',
        'À valider (CM)',
        'À valider (Client)',
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

    /** Contenu déjà produit hors Lucy — pas de parcours intermédiaire ni Asana à la création. */
    public const STANDARD_DIRECT_ENTRY_STATUS_NAMES = ['Prêt à publier', 'Publiée'];

    /** @var list<string> */
    public const VIDEO_DIRECT_ENTRY_STATUS_NAMES = ['Prête à programmer', 'Programmée', 'Publiée'];

    public static function isDirectEntryStatusName(string $statusName, bool $isVideo): bool
    {
        if ($isVideo) {
            return $statusName === 'Prêt à publier'
                || in_array($statusName, self::VIDEO_DIRECT_ENTRY_STATUS_NAMES, true);
        }

        return in_array($statusName, self::STANDARD_DIRECT_ENTRY_STATUS_NAMES, true);
    }

    public static function normalizeDirectEntryStatusName(string $statusName, bool $isVideo): string
    {
        if ($isVideo && $statusName === 'Prêt à publier') {
            return 'Prête à programmer';
        }

        return $statusName;
    }

    /**
     * Statuts proposés dans les menus déroulants (filtres, correction manuelle).
     * Exclut les étapes legacy vidéo et les anciens libellés hors parcours actuel.
     *
     * @return list<string>
     */
    public static function selectableStatusNames(string $workflow): array
    {
        if ($workflow === Status::WORKFLOW_VIDEO) {
            return self::VIDEO_ORDER;
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

        $order = $this->orderedStatusNamesFor($content);
        $isVideo = $this->formatHelper->isVideoContent($content);

        // Le journal peut citer d'anciens statuts supprimés : on ne le suit que s'il pointe vers le parcours actuel.
        $fromJournal = $this->actionLogRepository->resolvePreviousStatusName($content);
        if ($fromJournal !== null && in_array($fromJournal, $order, true)) {
            return $fromJournal;
        }

        $index = array_search($current, $order, true);
        if ($index === false || $index <= 0) {
            return null;
        }

        $previous = $order[$index - 1];
        // Sans sous-titres, on saute l'étape « Sous-titres à valider ».
        if ($isVideo && $previous === 'Sous-titres à valider' && $content->getVideoHasSubtitles() !== true) {
            return $order[$index - 2] ?? null;
        }

        return $previous;
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
            $transition = $this->videoTransitions()[$actionId] ?? null;
            if ($transition !== null && $actionId === 'montage_done') {
                $transition['to'] = self::statusAfterMontage($content);
            }

            return $transition;
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
            'Montage à faire' => [
                [
                    'id' => 'montage_done',
                    'label' => 'Montage terminé',
                    'subtitle' => $content->getVideoHasSubtitles() === true
                        ? 'Clôture la tâche Asana montage. Passe en « Sous-titres à valider » (tâche Asana CM).'
                        : 'Clôture la tâche Asana montage. Passe en « À valider (CM) ».',
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
            'À valider (Client)' => [
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
     * Étape qui suit le montage : sous-titres à valider si la vidéo en a, sinon validation CM.
     */
    public static function statusAfterMontage(Content $content): string
    {
        return $content->getVideoHasSubtitles() === true ? 'Sous-titres à valider' : 'À valider (CM)';
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
                'from' => ['Tournage à prévoir'],
                'to' => 'Montage à faire',
                'label' => 'Demande de montage (créer la tâche)',
                'effects' => [],
            ],
            'montage_done' => [
                'from' => ['Montage à faire'],
                'to' => 'À valider (CM)', // remplacé par statusAfterMontage() dans getTransition()
                'label' => 'Montage terminé',
                'effects' => ['complete_asana_montage'],
            ],
            'subtitles_validated' => [
                'from' => ['Sous-titres à valider'],
                'to' => 'À valider (CM)',
                'label' => 'Sous-titres validés',
                'effects' => ['complete_asana_subtitles'],
            ],
            'client_validation' => [
                'from' => ['À valider (CM)'],
                'to' => 'À valider (Client)',
                'label' => 'À valider par le client',
                'effects' => [],
            ],
            'ready_to_schedule' => [
                'from' => ['À valider (Client)'],
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
