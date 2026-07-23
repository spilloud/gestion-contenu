<?php

namespace App\Command;

use App\Entity\AsanaLinkedTask;
use App\Entity\Client;
use App\Entity\Content;
use App\Repository\AsanaLinkedTaskRepository;
use App\Repository\ClientRepository;
use App\Repository\ContentRepository;
use App\Service\AsanaBidirectionalSyncService;
use App\Service\AsanaService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'app:asana:backfill-derush-tasks',
    description: 'Rattache les tâches Asana « Suivi dérush » historiques à Lucy (asana_linked_task).',
)]
final class AsanaBackfillDerushTasksCommand extends Command
{
    public function __construct(
        private readonly AsanaService $asanaService,
        private readonly ClientRepository $clientRepository,
        private readonly ContentRepository $contentRepository,
        private readonly AsanaLinkedTaskRepository $asanaLinkedTaskRepository,
        private readonly AsanaBidirectionalSyncService $syncService,
        private readonly EntityManagerInterface $entityManager,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('client', null, InputOption::VALUE_REQUIRED, 'Limiter au client (id)')
            ->addOption('sync', null, InputOption::VALUE_NONE, 'Lancer une sync après le rattachement');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        if (!$this->asanaService->isEnabled()) {
            $io->error('Asana non configuré.');

            return Command::FAILURE;
        }

        $clientFilter = (int) $input->getOption('client');
        $clients = $clientFilter > 0
            ? array_filter([$this->clientRepository->find($clientFilter)])
            : $this->clientRepository->findAllOrderedByClientName();

        $created = 0;
        $skipped = 0;
        $completedMarked = 0;

        foreach ($clients as $client) {
            if (!$client instanceof Client) {
                continue;
            }

            $projectGid = trim((string) ($client->getAsanaProjectGid() ?? ''));
            if ($projectGid === '') {
                continue;
            }

            $io->section($client->getName() ?? 'Client #'.$client->getId());

            foreach ($this->asanaService->findDerushFollowUpTasksInProject($projectGid) as $task) {
                $gid = trim((string) ($task['gid'] ?? ''));
                $name = trim((string) ($task['name'] ?? ''));
                if ($gid === '') {
                    continue;
                }

                if ($this->asanaLinkedTaskRepository->findOneBy(['taskGid' => $gid]) instanceof AsanaLinkedTask) {
                    ++$skipped;
                    $io->writeln('  — déjà liée : '.$name);

                    continue;
                }

                $contentIds = $this->extractContentIdsFromTaskNotes((string) ($task['notes'] ?? ''), $client);
                if ($contentIds === []) {
                    $io->writeln('  ⚠ aucune vidéo trouvée dans les notes : '.$name);
                    ++$skipped;

                    continue;
                }

                $linked = new AsanaLinkedTask();
                $linked->setTaskGid($gid);
                $linked->setKind(AsanaLinkedTask::KIND_DERUSH_FOLLOWUP);
                $linked->setClient($client);
                $linked->setContentIds($contentIds);

                if (!empty($task['completed'])) {
                    $linked->setCompletedAtLucy(new \DateTimeImmutable());
                    ++$completedMarked;
                }

                $this->entityManager->persist($linked);
                ++$created;
                $io->writeln(sprintf('  ✓ liée (%d vidéo(s)) : %s', count($contentIds), $name));
            }
        }

        $this->entityManager->flush();

        $io->success(sprintf(
            '%d tâche(s) rattachée(s), %d déjà présente(s), %d déjà cochée(s) dans Asana.',
            $created,
            $skipped,
            $completedMarked,
        ));

        if ((bool) $input->getOption('sync') && $created > 0) {
            $result = $this->syncService->syncAll();
            $io->table(['Sync mises à jour', 'Ignorées', 'Erreurs'], [[$result['updated'], $result['skipped'], $result['errors']]]);
        }

        return Command::SUCCESS;
    }

    /**
     * @return list<int>
     */
    private function extractContentIdsFromTaskNotes(string $notes, Client $client): array
    {
        $ids = [];
        if (preg_match_all('#/videos/fiche/(\d+)#', $notes, $matches)) {
            foreach ($matches[1] as $rawId) {
                $id = (int) $rawId;
                if ($id <= 0) {
                    continue;
                }
                $content = $this->contentRepository->find($id);
                if ($content instanceof Content && $content->getClient()?->getId() === $client->getId()) {
                    $ids[] = $id;
                }
            }
        }

        // Anciennes tâches : liens absolus contenu.osmose-marketing.ch
        if ($ids === [] && preg_match_all('#contenu\.osmose-marketing\.ch/videos/fiche/(\d+)#', $notes, $matches)) {
            foreach ($matches[1] as $rawId) {
                $id = (int) $rawId;
                if ($id <= 0) {
                    continue;
                }
                $content = $this->contentRepository->find($id);
                if ($content instanceof Content && $content->getClient()?->getId() === $client->getId()) {
                    $ids[] = $id;
                }
            }
        }

        return array_values(array_unique($ids));
    }
}
