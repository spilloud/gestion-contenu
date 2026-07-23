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

        $linkedGids = [];
        foreach ($this->asanaLinkedTaskRepository->findAll() as $existing) {
            $g = trim((string) ($existing->getTaskGid() ?? ''));
            if ($g !== '') {
                $linkedGids[$g] = true;
            }
        }

        // 1) Recherche workspace (rattrapage historique)
        foreach ($this->asanaService->searchTasksInWorkspace('Suivi dérush') as $task) {
            $result = $this->linkDerushTask($task, $clients, $linkedGids, $io, $skipped, $completedMarked);
            $created += $result;
        }

        // 2) Par projet client
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
                $created += $this->linkDerushTask($task, [$client], $linkedGids, $io, $skipped, $completedMarked);
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
     * @param list<Client|null> $clientsHint
     * @param array<string, true> $linkedGids
     */
    private function linkDerushTask(
        array $task,
        array $clientsHint,
        array &$linkedGids,
        SymfonyStyle $io,
        int &$skipped,
        int &$completedMarked,
    ): int {
        $gid = trim((string) ($task['gid'] ?? ''));
        $name = trim((string) ($task['name'] ?? ''));
        if ($gid === '') {
            return 0;
        }

        if (isset($linkedGids[$gid])) {
            ++$skipped;
            return 0;
        }

        $client = $this->resolveClientForTask($task, $clientsHint);
        if (!$client instanceof Client) {
            $io->writeln('  ⚠ client introuvable : '.$name);
            ++$skipped;

            return 0;
        }

        $contentIds = $this->extractContentIdsFromTaskNotes((string) ($task['notes'] ?? ''), $client);
        if ($contentIds === []) {
            $io->writeln('  ⚠ aucune vidéo dans les notes : '.$name);
            ++$skipped;

            return 0;
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
        $linkedGids[$gid] = true;
        $io->writeln(sprintf('  ✓ [%s] %d vidéo(s) : %s', $client->getName(), count($contentIds), $name));

        return 1;
    }

    /**
     * @param list<Client|null> $clientsHint
     */
    private function resolveClientForTask(array $task, array $clientsHint): ?Client
    {
        if (count($clientsHint) === 1) {
            $only = $clientsHint[0];
            if ($only instanceof Client) {
                return $only;
            }
        }

        $notes = (string) ($task['notes'] ?? '');
        if (preg_match('/Client\s*:\s*(.+)/m', $notes, $m)) {
            $clientName = trim($m[1]);
            foreach ($this->clientRepository->findAllOrderedByClientName() as $client) {
                if (trim((string) ($client->getName() ?? '')) === $clientName) {
                    return $client;
                }
            }
        }

        return null;
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
