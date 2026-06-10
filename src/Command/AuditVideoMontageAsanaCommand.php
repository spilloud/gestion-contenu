<?php

namespace App\Command;

use App\Repository\ContentRepository;
use App\Service\AsanaService;
use App\Service\ContentFormatHelper;
use App\Service\VideoMontageAsanaTrigger;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'app:video:audit-montage-asana',
    description: 'Contrôle les liens Asana montage des vidéos (GID invalide, tâche manuelle non liée).',
)]
final class AuditVideoMontageAsanaCommand extends Command
{
    public function __construct(
        private readonly ContentRepository $contentRepository,
        private readonly ContentFormatHelper $formatHelper,
        private readonly AsanaService $asanaService,
        private readonly VideoMontageAsanaTrigger $montageAsanaTrigger,
        private readonly EntityManagerInterface $entityManager,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('ids', null, InputOption::VALUE_REQUIRED, 'IDs contenu séparés par des virgules')
            ->addOption('fix', null, InputOption::VALUE_NONE, 'Corrige les liens (recherche tâche existante, efface GID invalide)')
            ->addOption('status', null, InputOption::VALUE_REQUIRED, 'Filtrer par nom de statut (ex. « Montage à faire »)');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        if (!$this->asanaService->isEnabled()) {
            $io->error('Asana non configuré (ASANA_ACCESS_TOKEN).');

            return Command::FAILURE;
        }

        $fix = (bool) $input->getOption('fix');
        $statusFilter = trim((string) $input->getOption('status'));
        $contents = $this->loadContents(trim((string) $input->getOption('ids')), $statusFilter);

        if ($contents === []) {
            $io->warning('Aucune vidéo à auditer.');

            return Command::SUCCESS;
        }

        $ok = 0;
        $fixed = 0;
        $issues = 0;

        foreach ($contents as $content) {
            if (!$this->formatHelper->isVideoContent($content)) {
                continue;
            }

            $id = $content->getId();
            $title = $content->getTitle();
            $status = $content->getStatus()?->getName() ?? '—';
            $stored = $content->getAsanaTaskGid();

            $io->writeln(sprintf('#%d « %s » | statut: %s | gid: %s', $id, $title, $status, $stored ?? '—'));

            if ($stored !== null && $this->asanaService->isTaskAccessible($stored)) {
                $task = $this->asanaService->fetchTask($stored);
                $assignee = is_array($task['assignee'] ?? null) ? ($task['assignee']['name'] ?? '—') : '—';
                $completed = !empty($task['completed']) ? 'oui' : 'non';
                $io->writeln(sprintf('  → OK | assigné: %s | terminée: %s', $assignee, $completed));
                ++$ok;
                continue;
            }

            if ($stored !== null) {
                $io->writeln('  → GID invalide ou inaccessible');
            } else {
                $io->writeln('  → aucun GID en base');
            }
            ++$issues;

            if (!$fix) {
                continue;
            }

            $before = $stored;
            $resolved = $this->montageAsanaTrigger->resolveMontageTaskLink($content, false);
            if ($resolved !== null && $resolved !== $before) {
                $this->entityManager->flush();
                $io->writeln('  → corrigé : lié à '.$resolved);
                ++$fixed;
                continue;
            }
            if ($resolved !== null) {
                $io->writeln('  → corrigé : GID valide '.$resolved);
                ++$fixed;
                continue;
            }

            if ($status === 'Montage à faire' && $this->montageAsanaTrigger->ensureWhenMontageQueued($content)) {
                $io->writeln('  → corrigé : tâche créée '.$content->getAsanaTaskGid());
                ++$fixed;
            } else {
                $io->writeln('  → non corrigé automatiquement');
            }
        }

        $io->success(sprintf('%d OK, %d problème(s), %d corrigé(s).', $ok, $issues, $fixed));

        return $issues > 0 && !$fix ? Command::FAILURE : Command::SUCCESS;
    }

    /**
     * @return list<\App\Entity\Content>
     */
    private function loadContents(string $idsRaw, string $statusFilter): array
    {
        if ($idsRaw !== '') {
            $contents = [];
            foreach (explode(',', $idsRaw) as $part) {
                $id = (int) trim($part);
                if ($id <= 0) {
                    continue;
                }
                $content = $this->contentRepository->find($id);
                if ($content !== null) {
                    $contents[] = $content;
                }
            }

            return $contents;
        }

        $qb = $this->contentRepository->createQueryBuilder('c')
            ->leftJoin('c.status', 's')->addSelect('s')
            ->leftJoin('c.client', 'cl')->addSelect('cl')
            ->leftJoin('c.format', 'f')->addSelect('f')
            ->andWhere('c.asanaTaskGid IS NOT NULL');

        if ($statusFilter !== '') {
            $qb->andWhere('s.name = :status')->setParameter('status', $statusFilter);
        }

        return $qb->orderBy('c.id', 'DESC')->getQuery()->getResult();
    }
}
