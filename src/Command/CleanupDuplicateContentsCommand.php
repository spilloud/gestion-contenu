<?php

namespace App\Command;

use App\Entity\AsanaLinkedTask;
use App\Entity\Content;
use App\Repository\AsanaLinkedTaskRepository;
use App\Repository\ContentRepository;
use App\Service\AsanaService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'app:cleanup-duplicate-contents',
    description: 'Supprime des contenus en double et leurs tâches Asana associées.',
)]
final class CleanupDuplicateContentsCommand extends Command
{
    public function __construct(
        private readonly ContentRepository $contentRepository,
        private readonly AsanaLinkedTaskRepository $asanaLinkedTaskRepository,
        private readonly AsanaService $asanaService,
        private readonly EntityManagerInterface $entityManager,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('content-ids', InputArgument::REQUIRED, 'IDs contenu séparés par des virgules')
            ->addOption('linked-task-ids', null, InputOption::VALUE_REQUIRED, 'IDs asana_linked_task à supprimer (virgules)');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $contentIds = $this->parseIds($input->getArgument('content-ids'));
        $linkedTaskIds = $this->parseIds((string) $input->getOption('linked-task-ids'));

        if ($contentIds === [] && $linkedTaskIds === []) {
            $io->error('Aucun ID à traiter.');

            return Command::FAILURE;
        }

        foreach ($linkedTaskIds as $linkedTaskId) {
            $linkedTask = $this->asanaLinkedTaskRepository->find($linkedTaskId);
            if (!$linkedTask instanceof AsanaLinkedTask) {
                $io->warning(sprintf('Tâche liée #%d introuvable.', $linkedTaskId));
                continue;
            }

            $gid = trim((string) ($linkedTask->getTaskGid() ?? ''));
            if ($gid !== '') {
                $deleted = $this->asanaService->deleteTask($gid);
                $io->writeln(sprintf(
                    'Asana suivi #%d (%s) : %s',
                    $linkedTaskId,
                    $gid,
                    $deleted ? 'supprimée' : 'échec suppression',
                ));
            }

            $this->entityManager->remove($linkedTask);
        }

        foreach ($contentIds as $contentId) {
            $content = $this->contentRepository->find($contentId);
            if (!$content instanceof Content) {
                $io->warning(sprintf('Contenu #%d introuvable.', $contentId));
                continue;
            }

            $gid = trim((string) ($content->getAsanaTaskGid() ?? ''));
            if ($gid !== '') {
                $deleted = $this->asanaService->deleteTask($gid);
                $io->writeln(sprintf(
                    'Contenu #%d « %s » — Asana montage %s : %s',
                    $contentId,
                    $content->getTitle(),
                    $gid,
                    $deleted ? 'supprimée' : 'échec suppression',
                ));
            } else {
                $io->writeln(sprintf('Contenu #%d « %s » — pas de tâche Asana montage.', $contentId, $content->getTitle()));
            }

            $this->entityManager->remove($content);
        }

        $this->entityManager->flush();

        $io->success(sprintf(
            '%d contenu(s) et %d tâche(s) liée(s) supprimés en base.',
            count($contentIds),
            count($linkedTaskIds),
        ));

        return Command::SUCCESS;
    }

    /**
     * @return list<int>
     */
    private function parseIds(string $raw): array
    {
        if (trim($raw) === '') {
            return [];
        }

        return array_values(array_unique(array_filter(array_map('intval', explode(',', $raw)), static fn (int $id): bool => $id > 0)));
    }
}
