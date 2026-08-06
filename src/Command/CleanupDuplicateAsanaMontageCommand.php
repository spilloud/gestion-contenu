<?php

namespace App\Command;

use App\Entity\Client;
use App\Entity\Content;
use App\Repository\ClientRepository;
use App\Repository\ContentRepository;
use App\Service\AsanaService;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'app:cleanup-duplicate-asana-montage',
    description: 'Supprime les tâches Asana montage en double pour un client (garde le GID enregistré dans Lucy).',
)]
final class CleanupDuplicateAsanaMontageCommand extends Command
{
    public function __construct(
        private readonly ClientRepository $clientRepository,
        private readonly ContentRepository $contentRepository,
        private readonly AsanaService $asanaService,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('client', null, InputOption::VALUE_REQUIRED, 'ID client')
            ->addOption('ids', null, InputOption::VALUE_REQUIRED, 'IDs contenu (virgules), sinon toutes les vidéos du client avec GID Asana')
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Affiche sans supprimer');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        if (!$this->asanaService->isEnabled()) {
            $io->error('Asana non configuré.');

            return Command::FAILURE;
        }

        $clientId = (int) $input->getOption('client');
        if ($clientId <= 0) {
            $io->error('Option --client requise.');

            return Command::FAILURE;
        }

        $client = $this->clientRepository->find($clientId);
        if (!$client instanceof Client) {
            $io->error('Client introuvable.');

            return Command::FAILURE;
        }

        $projectGid = trim((string) ($client->getAsanaProjectGid() ?? ''));
        if ($projectGid === '') {
            $io->error('Projet Asana client manquant.');

            return Command::FAILURE;
        }

        $dryRun = (bool) $input->getOption('dry-run');
        $contents = $this->loadContents($client, trim((string) $input->getOption('ids')));
        if ($contents === []) {
            $io->warning('Aucune vidéo à traiter.');

            return Command::SUCCESS;
        }

        /** @var array<int, string> $keepByContentId contentId => taskGid */
        $keepByContentId = [];
        foreach ($contents as $content) {
            $id = $content->getId();
            $gid = trim((string) ($content->getAsanaTaskGid() ?? ''));
            if ($id !== null && $gid !== '') {
                $keepByContentId[$id] = $gid;
            }
        }

        /** @var array<int, list<array{gid: string, name: string}>> $tasksByContentId */
        $tasksByContentId = [];

        foreach ($this->asanaService->iterateAllProjectTasks($projectGid, ['gid', 'name', 'notes', 'completed']) as $task) {
            if (!is_array($task)) {
                continue;
            }

            $name = trim((string) ($task['name'] ?? ''));
            $nameLower = mb_strtolower($name);
            if (str_contains($nameLower, 'suivi') && (str_contains($nameLower, 'dérush') || str_contains($nameLower, 'derush'))) {
                continue;
            }

            $notes = (string) ($task['notes'] ?? '');
            if (!str_contains($notes, 'Vidéo créée depuis Gestion des contenus.')) {
                continue;
            }

            if (!preg_match('#/videos/fiche/(\d+)#', $notes, $matches)) {
                continue;
            }

            $contentId = (int) $matches[1];
            if ($contentId <= 0 || !isset($keepByContentId[$contentId])) {
                continue;
            }

            $gid = trim((string) ($task['gid'] ?? ''));
            if ($gid === '') {
                continue;
            }

            $tasksByContentId[$contentId][] = [
                'gid' => $gid,
                'name' => $name,
            ];
        }

        $deleted = 0;
        $duplicates = 0;

        foreach ($keepByContentId as $contentId => $keepGid) {
            $tasks = $tasksByContentId[$contentId] ?? [];
            if (count($tasks) <= 1) {
                continue;
            }

            $content = $this->contentRepository->find($contentId);
            $title = $content instanceof Content ? (string) $content->getTitle() : '#'.$contentId;
            $io->section(sprintf('#%d « %s » — %d tâches montage', $contentId, $title, count($tasks)));

            foreach ($tasks as $task) {
                if ($task['gid'] === $keepGid) {
                    $io->writeln(sprintf('  ✓ conserve %s (%s)', $task['gid'], $task['name']));

                    continue;
                }

                ++$duplicates;
                if ($dryRun) {
                    $io->writeln(sprintf('  → dry-run : supprimerait %s (%s)', $task['gid'], $task['name']));

                    continue;
                }

                $ok = $this->asanaService->deleteTask($task['gid']);
                if ($ok) {
                    $io->writeln(sprintf('  ✗ supprimée %s (%s)', $task['gid'], $task['name']));
                    ++$deleted;
                } else {
                    $io->warning(sprintf('  ! échec suppression %s (%s)', $task['gid'], $task['name']));
                }
            }
        }

        if ($duplicates === 0) {
            $io->success('Aucun doublon montage détecté pour ce client.');

            return Command::SUCCESS;
        }

        $io->success(sprintf(
            '%d doublon(s) détecté(s), %d supprimé(s)%s.',
            $duplicates,
            $deleted,
            $dryRun ? ' (dry-run)' : '',
        ));

        return Command::SUCCESS;
    }

    /**
     * @return list<Content>
     */
    private function loadContents(Client $client, string $idsRaw): array
    {
        if ($idsRaw !== '') {
            $contents = [];
            foreach (explode(',', $idsRaw) as $part) {
                $id = (int) trim($part);
                if ($id <= 0) {
                    continue;
                }
                $content = $this->contentRepository->find($id);
                if ($content instanceof Content && $content->getClient()?->getId() === $client->getId()) {
                    $contents[] = $content;
                }
            }

            return $contents;
        }

        return $this->contentRepository->createQueryBuilder('c')
            ->andWhere('c.client = :client')
            ->andWhere('c.asanaTaskGid IS NOT NULL')
            ->setParameter('client', $client)
            ->orderBy('c.id', 'ASC')
            ->getQuery()
            ->getResult();
    }
}
