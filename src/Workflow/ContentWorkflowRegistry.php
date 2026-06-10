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

    /**
     * Phases affichées dans la barre de progression (fiche vidéo).
     *
     * @var list<array{label: string, statuses: list<string>}>
     */
    public const VIDEO_PHASES = [
        ['label' => 'Planif.', 'statuses' => ['Tournage à prévoir']],
        ['label' => 'Dérush', 'statuses' => ['Brouillon (Dérush)', 'Rushs / à dispatcher']],
        ['label' => 'Montage', 'statuses' => ['Montage à faire', 'Montage en cours', 'Retouches (Monteur)']],
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
        if ($fromJournal !== null) {
            return $fromJournal;
        }

        $order = $this->orderedStatusNamesFor($content);
        $index = array_search($current, $order, true);
        if ($index === false || $index <= 0) {
            return null;
        }

        $previous = $order[$index - 1];

        // Si l'étape précédente est un libellé équivalent, on saute au vrai précédent.
        if ($this->formatHelper->isVideoContent($content)) {
            foreach (self::VIDEO_EQUIVALENT_STATUS_GROUPS as $group) {
                if (in_array($previous, $group, true) && $index - 2 >= 0) {
                    return $order[$index - 2];
                }
            }
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
                    'id' => 'derush_launch_montage',
                    'label' => 'Demander le montage',
                    'subtitle' => 'À cliquer dès que les rushs sont sur le KDrive. Crée la tâche Asana pour le monteur assigné.',
                    'variant' => 'primary',
                    'group' => null,
                ],
            ],
            'Brouillon (Dérush)' => [
                [
                    'id' => 'rushes_ready',
                    'label' => 'Rushs prêtes — à dispatcher',
                    'subtitle' => 'Les rushs sont classées. Étape suivante : demander le montage.',
                    'variant' => 'primary',
                    'group' => null,
                ],
            ],
            'Rushs / à dispatcher' => [
                [
                    'id' => 'montage_queued',
                    'label' => 'Demander le montage',
                    'subtitle' => 'Crée la tâche Asana pour le monteur. Ne cliquez pas si le montage n\'est pas encore à lancer.',
                    'variant' => 'primary',
                    'group' => null,
                ],
            ],
            'Montage à faire' => [
                [
                    'id' => 'montage_started',
                    'label' => 'Le monteur a démarré',
                    'subtitle' => 'Le monteur (ou la prod pour lui) confirme que le montage est en cours.',
                    'variant' => 'primary',
                    'group' => null,
                ],
                [
                    'id' => 'montage_done',
                    'label' => 'Montage terminé — valider en prod',
                    'subtitle' => 'Clôture la tâche Asana montage. À cliquer quand le fichier est prêt pour la prod.',
                    'variant' => 'primary',
                    'group' => null,
                ],
            ],
            'Montage en cours' => [
                [
                    'id' => 'retouches',
                    'label' => 'Retouches monteur',
                    'subtitle' => 'Le monteur doit corriger avant validation prod.',
                    'variant' => 'secondary',
                    'group' => null,
                ],
                [
                    'id' => 'montage_done',
                    'label' => 'Montage terminé — valider en prod',
                    'subtitle' => 'Clôture la tâche Asana montage.',
                    'variant' => 'primary',
                    'group' => null,
                ],
            ],
            'Retouches (Monteur)' => [
                [
                    'id' => 'montage_done',
                    'label' => 'Montage terminé — valider en prod',
                    'subtitle' => 'Clôture la tâche Asana montage après retouches.',
                    'variant' => 'primary',
                    'group' => null,
                ],
            ],
            'À valider (Prod)' => [
                [
                    'id' => 'subtitles_yes',
                    'label' => 'Passer au sous-titrage (SubMagic)',
                    'subtitle' => 'La tâche Asana CM sera créée à l\'étape « Demander la relecture CM ».',
                    'variant' => 'primary',
                    'group' => 'subtitles',
                ],
                [
                    'id' => 'subtitles_no',
                    'label' => 'Sans sous-titres — prépa CM',
                    'subtitle' => 'Pas de SubMagic. Passe directement en préparation CM.',
                    'variant' => 'secondary',
                    'group' => 'subtitles',
                ],
            ],
            'Sous-titrage (SubMagic)' => [
                [
                    'id' => 'subtitles_review',
                    'label' => 'Demander la relecture CM',
                    'subtitle' => 'Crée la tâche Asana pour la CM. Cliquer quand SubMagic est terminé.',
                    'variant' => 'primary',
                    'group' => null,
                ],
            ],
            'Prépa CM (sans sous-titres)' => [
                [
                    'id' => 'cm_validation',
                    'label' => 'Envoyer en validation CM',
                    'subtitle' => 'La CM peut relire le contenu avant le client.',
                    'variant' => 'primary',
                    'group' => null,
                ],
            ],
            'Sous-titres à valider' => [
                [
                    'id' => 'subtitles_validated',
                    'label' => 'Valider les sous-titres',
                    'subtitle' => 'La CM confirme que les sous-titres sont OK.',
                    'variant' => 'primary',
                    'group' => null,
                ],
            ],
            'À valider (CM)' => [
                [
                    'id' => 'client_validation',
                    'label' => 'Demander la validation client',
                    'subtitle' => 'Envoie la vidéo au client pour validation.',
                    'variant' => 'primary',
                    'group' => null,
                ],
            ],
            'À valider (Client)', 'À faire valider au client' => [
                [
                    'id' => 'ready_to_schedule',
                    'label' => 'Prête à programmer',
                    'subtitle' => 'Le client a validé — prête pour le calendrier.',
                    'variant' => 'primary',
                    'group' => null,
                ],
            ],
            'Prête à programmer' => [
                [
                    'id' => 'scheduled',
                    'label' => 'Programmer la publication',
                    'subtitle' => 'Date de publication confirmée dans le calendrier.',
                    'variant' => 'primary',
                    'group' => null,
                ],
            ],
            'Programmée' => [
                [
                    'id' => 'published',
                    'label' => 'Marquer comme publiée',
                    'subtitle' => 'La vidéo est en ligne.',
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
            'derush_launch_montage' => [
                'from' => ['Tournage à prévoir'],
                'to' => 'Montage à faire',
                'label' => 'Demander le montage',
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
                'label' => 'Demander le montage',
                'effects' => [],
            ],
            'montage_started' => [
                'from' => ['Montage à faire'],
                'to' => 'Montage en cours',
                'label' => 'Le monteur a démarré',
                'effects' => [],
            ],
            'retouches' => [
                'from' => ['Montage en cours'],
                'to' => 'Retouches (Monteur)',
                'label' => 'Retouches monteur',
                'effects' => [],
            ],
            'montage_done' => [
                'from' => ['Montage à faire', 'Montage en cours', 'Retouches (Monteur)'],
                'to' => 'À valider (Prod)',
                'label' => 'Montage terminé — valider en prod',
                'effects' => ['complete_asana_montage'],
            ],
            'subtitles_yes' => [
                'from' => ['À valider (Prod)'],
                'to' => 'Sous-titrage (SubMagic)',
                'label' => 'Passer au sous-titrage (SubMagic)',
                'effects' => ['set_subtitles_yes'],
            ],
            'subtitles_no' => [
                'from' => ['À valider (Prod)'],
                'to' => 'Prépa CM (sans sous-titres)',
                'label' => 'Sans sous-titres — prépa CM',
                'effects' => ['set_subtitles_no'],
            ],
            'subtitles_review' => [
                'from' => ['Sous-titrage (SubMagic)'],
                'to' => 'Sous-titres à valider',
                'label' => 'Demander la relecture CM',
                'effects' => ['trigger_subtitles_asana'],
            ],
            'subtitles_validated' => [
                'from' => ['Sous-titres à valider'],
                'to' => 'À valider (CM)',
                'label' => 'Valider les sous-titres',
                'effects' => [],
            ],
            'cm_validation' => [
                'from' => ['Prépa CM (sans sous-titres)'],
                'to' => 'À valider (CM)',
                'label' => 'Envoyer en validation CM',
                'effects' => [],
            ],
            'client_validation' => [
                'from' => ['À valider (CM)'],
                'to' => 'À faire valider au client',
                'label' => 'Demander la validation client',
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
