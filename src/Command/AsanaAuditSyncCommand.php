<?php

namespace App\Command;

use App\Entity\Client;
use App\Entity\Content;
use App\Repository\AsanaLinkedTaskRepository;
use App\Repository\ContentRepository;
use App\Repository\ShootingRequestRepository;
use App\Service\AsanaBidirectionalSyncService;
use App\Service\AsanaService;
use App\Service\ContentFormatHelper;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'app:asana:audit-sync',
    description: 'Contrôle la cohérence Lucy ↔ Asana (assigné, échéance, complétion vs statut).',
)]
final class AsanaAuditSyncCommand extends Command
{
    private const MONTAGE_ACTIVE = ['Montage à faire', 'Montage en cours', 'Retouches (Monteur)'];

    public function __construct(
        private readonly AsanaService $asanaService,
        private readonly ContentFormatHelper $formatHelper,
        private readonly ContentRepository $contentRepository,
        private readonly AsanaLinkedTaskRepository $asanaLinkedTaskRepository,
        private readonly ShootingRequestRepository $shootingRequestRepository,
        private readonly AsanaBidirectionalSyncService $syncService,
        private readonly EntityManagerInterface $entityManager,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('fix', null, InputOption::VALUE_NONE, 'Relance une sync complète après l’audit')
            ->addOption('client', null, InputOption::VALUE_REQUIRED, 'Limiter au client (id)');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        if (!$this->asanaService->isEnabled()) {
            $io->error('Asana non configuré.');

            return Command::FAILURE;
        }

        $clientId = (int) $input->getOption('client');
        $issues = [];

        $contents = $clientId > 0
            ? array_filter(
                $this->contentRepository->findVideosWithAsanaLinksForClient(
                    $this->contentRepository->getEntityManager()->find(\App\Entity\Client::class, $clientId) ?? throw new \InvalidArgumentException('Client introuvable'),
                ),
                fn (Content $c) => $c->getStatus()?->getName() !== 'Publiée',
            )
            : $this->contentRepository->findVideosForAsanaSync();

        foreach ($contents as $content) {
            if (!$this->formatHelper->isVideoContent($content)) {
                continue;
            }
            $issues = array_merge($issues, $this->auditContentMontage($content));
            $issues = array_merge($issues, $this->auditContentSubtitles($content));
        }

        foreach ($this->asanaLinkedTaskRepository->findOpenTasks() as $linked) {
            $gid = trim((string) ($linked->getTaskGid() ?? ''));
            $task = $gid !== '' ? $this->asanaService->fetchTask($gid) : null;
            if ($task === null) {
                $issues[] = sprintf('[dérush] tâche %s introuvable (client #%d)', $gid, $linked->getClient()?->getId() ?? 0);
                continue;
            }
            if (!empty($task['completed'])) {
                $issues[] = sprintf('[dérush] tâche %s cochée dans Asana mais ouverte dans Lucy', $gid);
            }
        }

        foreach ($this->shootingRequestRepository->findWithOpenAsanaTask() as $request) {
            $gid = trim((string) ($request->getAsanaTaskGid() ?? ''));
            $task = $gid !== '' ? $this->asanaService->fetchTask($gid) : null;
            if ($task === null) {
                $issues[] = sprintf('[tournage] demande #%d — GID %s inaccessible', $request->getId(), $gid);
            }
        }

        if ($issues === []) {
            $io->success(sprintf('Aucune incohérence sur %d vidéo(s) contrôlée(s).', count($contents)));
        } else {
            $io->warning(sprintf('%d incohérence(s) détectée(s) :', count($issues)));
            foreach ($issues as $line) {
                $io->writeln('  • '.$line);
            }
        }

        if ((bool) $input->getOption('fix') && $issues !== []) {
            $io->section('Correction via sync');
            $result = $this->syncService->syncAll();
            $io->table(['Mises à jour', 'Ignorées', 'Erreurs'], [[$result['updated'], $result['skipped'], $result['errors']]]);
        }

        return $issues === [] ? Command::SUCCESS : Command::FAILURE;
    }

    /**
     * @return list<string>
     */
    private function auditContentMontage(Content $content): array
    {
        $gid = trim((string) ($content->getAsanaTaskGid() ?? ''));
        if ($gid === '') {
            return [];
        }

        $task = $this->asanaService->fetchTask($gid);
        if ($task === null) {
            return [sprintf('#%d « %s » — GID montage %s inaccessible', $content->getId(), $content->getTitle(), $gid)];
        }

        $issues = [];
        $status = $content->getStatus()?->getName() ?? '—';
        $completed = !empty($task['completed']);

        if ($completed && in_array($status, self::MONTAGE_ACTIVE, true)) {
            $issues[] = sprintf('#%d « %s » — Asana cochée mais statut Lucy « %s »', $content->getId(), $content->getTitle(), $status);
        }
        if (!$completed && in_array($status, self::MONTAGE_ACTIVE, true) === false && $status !== 'Publiée' && !str_starts_with($status, 'Sous-titr')) {
            // Montage non coché alors que la vidéo est après montage — informatif seulement si statut prod/CM
            if (in_array($status, ['À valider (Prod)', 'Sous-titrage (SubMagic)', 'Prépa CM (sans sous-titres)', 'Sous-titres à valider', 'À valider (CM)'], true)) {
                // attendu si cochée — si pas cochée c'est une incohérence légère
            }
        }

        $editorGid = trim((string) ($content->getVideoEditor()?->getAsanaUserGid() ?? ''));
        $taskAssigneeGid = is_array($task['assignee'] ?? null)
            ? trim((string) ($task['assignee']['gid'] ?? ''))
            : '';
        if ($editorGid !== '' && $taskAssigneeGid !== '' && $editorGid !== $taskAssigneeGid && in_array($status, self::MONTAGE_ACTIVE, true)) {
            $asanaName = is_array($task['assignee']) ? ($task['assignee']['name'] ?? $taskAssigneeGid) : $taskAssigneeGid;
            $issues[] = sprintf(
                '#%d « %s » — monteur Lucy ≠ Asana (%s vs %s)',
                $content->getId(),
                $content->getTitle(),
                $content->getVideoEditor()?->getName() ?? '—',
                $asanaName,
            );
        }

        $dueOn = trim((string) ($task['due_on'] ?? ''));
        $lucyDue = $content->getAsanaMontageDueOn()?->format('Y-m-d') ?? '';
        if ($dueOn !== '' && $lucyDue !== '' && $dueOn !== $lucyDue && in_array($status, self::MONTAGE_ACTIVE, true)) {
            $issues[] = sprintf(
                '#%d « %s » — échéance Lucy %s ≠ Asana %s',
                $content->getId(),
                $content->getTitle(),
                $content->getAsanaMontageDueOn()?->format('d/m/Y'),
                (new \DateTimeImmutable($dueOn))->format('d/m/Y'),
            );
        }

        return $issues;
    }

    /**
     * @return list<string>
     */
    private function auditContentSubtitles(Content $content): array
    {
        $gid = trim((string) ($content->getAsanaSubtitlesTaskGid() ?? ''));
        if ($gid === '') {
            return [];
        }

        $task = $this->asanaService->fetchTask($gid);
        if ($task === null) {
            return [sprintf('#%d « %s » — GID sous-titres %s inaccessible', $content->getId(), $content->getTitle(), $gid)];
        }

        $issues = [];
        $status = $content->getStatus()?->getName() ?? '—';
        $completed = !empty($task['completed']);

        if ($completed && $status === 'Sous-titres à valider') {
            $issues[] = sprintf('#%d « %s » — relecture Asana cochée mais statut « Sous-titres à valider »', $content->getId(), $content->getTitle());
        }

        return $issues;
    }
}
