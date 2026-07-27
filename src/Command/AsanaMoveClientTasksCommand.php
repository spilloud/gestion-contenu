<?php

namespace App\Command;

use App\Entity\Client;
use App\Entity\Content;
use App\Repository\AsanaLinkedTaskRepository;
use App\Repository\ClientRepository;
use App\Repository\ContentRepository;
use App\Repository\ShootingRequestRepository;
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
    name: 'app:asana:move-client-tasks',
    description: 'Corrige le project GID client et déplace les tâches Asana liées vers le bon projet.',
)]
final class AsanaMoveClientTasksCommand extends Command
{
    public function __construct(
        private readonly ClientRepository $clientRepository,
        private readonly ContentRepository $contentRepository,
        private readonly ShootingRequestRepository $shootingRequestRepository,
        private readonly AsanaLinkedTaskRepository $asanaLinkedTaskRepository,
        private readonly AsanaService $asanaService,
        private readonly EntityManagerInterface $entityManager,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('client', InputArgument::REQUIRED, 'Nom ou ID client (ex. "Pro Suisse" ou 12)')
            ->addArgument('projectGid', InputArgument::REQUIRED, 'GID du projet Asana cible')
            ->addOption('from-project', null, InputOption::VALUE_REQUIRED, 'Vider ce projet source (toutes tâches, y compris terminées)')
            ->addOption('skip-client-update', null, InputOption::VALUE_NONE, 'Ne pas modifier asana_project_gid en base')
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Simule sans modifier Asana ni la base');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        if (!$this->asanaService->isEnabled()) {
            $io->error('Asana non configuré.');

            return Command::FAILURE;
        }

        $clientArg = trim((string) $input->getArgument('client'));
        $projectGid = trim((string) $input->getArgument('projectGid'));
        $fromProjectGid = trim((string) $input->getOption('from-project'));
        $skipClientUpdate = (bool) $input->getOption('skip-client-update');
        $dryRun = (bool) $input->getOption('dry-run');

        $client = is_numeric($clientArg)
            ? $this->clientRepository->find((int) $clientArg)
            : $this->clientRepository->findOneBy(['name' => $clientArg]);

        if (!$client instanceof Client) {
            // Recherche partielle
            foreach ($this->clientRepository->findAllOrderedByClientName() as $c) {
                if (stripos((string) ($c->getName() ?? ''), $clientArg) !== false) {
                    $client = $c;
                    break;
                }
            }
        }

        if (!$client instanceof Client) {
            $io->error('Client introuvable : '.$clientArg);

            return Command::FAILURE;
        }

        $oldProjectGid = $fromProjectGid !== '' ? $fromProjectGid : trim((string) ($client->getAsanaProjectGid() ?? ''));
        $io->title(sprintf('Client : %s (#%d)', $client->getName(), $client->getId()));
        $io->table(['', 'GID'], [
            ['Projet source (à vider)', $fromProjectGid !== '' ? $fromProjectGid : ($oldProjectGid !== '' ? $oldProjectGid : '—')],
            ['Projet cible', $projectGid],
        ]);

        if (!$skipClientUpdate && !$dryRun) {
            $client->setAsanaProjectGid($projectGid);
            $this->entityManager->flush();
            $io->success('Project GID client mis à jour en base.');
        } elseif ($skipClientUpdate) {
            $io->note('Mise à jour client ignorée (--skip-client-update).');
        } else {
            $io->note('Dry-run : base non modifiée.');
        }

        $taskGids = $this->collectTaskGids($client);
        if ($fromProjectGid !== '') {
            foreach ($this->asanaService->iterateAllProjectTasks($fromProjectGid, ['gid', 'name', 'completed']) as $task) {
                $gid = trim((string) ($task['gid'] ?? ''));
                if ($gid !== '') {
                    $taskGids[] = $gid;
                }
            }
            $taskGids = array_values(array_unique($taskGids));
        }

        if ($taskGids === []) {
            $io->warning('Aucune tâche Asana à déplacer.');

            return Command::SUCCESS;
        }

        $io->section(sprintf('%d tâche(s) à traiter', count($taskGids)));
        $moved = 0;
        $removedOnly = 0;
        $skipped = 0;
        $errors = 0;

        foreach ($taskGids as $taskGid) {
            $task = $this->asanaService->fetchTask($taskGid);
            if ($task === null) {
                $io->writeln("  ✗ $taskGid — introuvable");
                ++$errors;
                continue;
            }

            $name = trim((string) ($task['name'] ?? $taskGid));
            $completed = !empty($task['completed']) ? ' [terminée]' : '';
            $projectGids = $this->extractProjectGids($task);
            $sourceGid = $fromProjectGid !== '' ? $fromProjectGid : null;
            $inSource = $sourceGid !== null && in_array($sourceGid, $projectGids, true);
            $inTarget = in_array($projectGid, $projectGids, true);

            if ($sourceGid !== null && !$inSource && $inTarget) {
                $io->writeln("  — $name$completed (déjà hors projet source)");
                ++$skipped;
                continue;
            }

            if ($sourceGid === null && $inTarget) {
                $io->writeln("  — $name$completed (déjà dans le bon projet)");
                ++$skipped;
                continue;
            }

            if ($dryRun) {
                $io->writeln(sprintf(
                    '  → %s%s (projets : %s)',
                    $name,
                    $completed,
                    implode(', ', $projectGids) ?: '—',
                ));
                ++$moved;
                continue;
            }

            $ok = true;
            if (!$inTarget && !$this->asanaService->addTaskToProject($taskGid, $projectGid)) {
                $ok = false;
            }

            if ($sourceGid !== null && $inSource && !$this->asanaService->removeTaskFromProject($taskGid, $sourceGid)) {
                $ok = false;
            } elseif ($sourceGid === null) {
                foreach ($projectGids as $fromGid) {
                    if ($fromGid === $projectGid) {
                        continue;
                    }
                    if (!$this->asanaService->removeTaskFromProject($taskGid, $fromGid)) {
                        $ok = false;
                    }
                }
            }

            if ($ok) {
                if ($inTarget && $sourceGid !== null && $inSource) {
                    $io->writeln("  ✓ $name$completed (retirée du projet source)");
                    ++$removedOnly;
                } else {
                    $io->writeln("  ✓ $name$completed");
                    ++$moved;
                }
            } else {
                $io->writeln("  ✗ $name$completed — erreur déplacement");
                ++$errors;
            }
        }

        $io->success(sprintf(
            'Terminé : %d déplacée(s), %d retirée(s) du source, %d ignorée(s), %d erreur(s).',
            $moved,
            $removedOnly,
            $skipped,
            $errors,
        ));

        return $errors > 0 ? Command::FAILURE : Command::SUCCESS;
    }

    /**
     * @return list<string>
     */
    private function collectTaskGids(Client $client): array
    {
        $gids = [];
        $clientId = $client->getId();
        if ($clientId === null) {
            return [];
        }

        $qb = $this->contentRepository->createQueryBuilder('c')
            ->andWhere('c.client = :client')
            ->andWhere('c.asanaTaskGid IS NOT NULL OR c.asanaSubtitlesTaskGid IS NOT NULL')
            ->setParameter('client', $client);

        /** @var Content[] $contents */
        $contents = $qb->getQuery()->getResult();
        foreach ($contents as $content) {
            foreach ([$content->getAsanaTaskGid(), $content->getAsanaSubtitlesTaskGid()] as $gid) {
                $gid = trim((string) ($gid ?? ''));
                if ($gid !== '') {
                    $gids[$gid] = true;
                }
            }
        }

        foreach ($this->asanaLinkedTaskRepository->findOpenTasks() as $linked) {
            if ($linked->getClient()?->getId() !== $clientId) {
                continue;
            }
            $gid = trim((string) ($linked->getTaskGid() ?? ''));
            if ($gid !== '') {
                $gids[$gid] = true;
            }
        }

        foreach ($this->shootingRequestRepository->findAllForList() as $request) {
            if ($request->getClient()?->getId() !== $clientId) {
                continue;
            }
            $gid = trim((string) ($request->getAsanaTaskGid() ?? ''));
            if ($gid !== '') {
                $gids[$gid] = true;
            }
        }

        return array_keys($gids);
    }

    /**
     * @param array<string, mixed> $task
     *
     * @return list<string>
     */
    private function extractProjectGids(array $task): array
    {
        $projects = $task['projects'] ?? [];
        if (!is_array($projects)) {
            return [];
        }

        $gids = [];
        foreach ($projects as $p) {
            if (is_array($p) && isset($p['gid'])) {
                $gids[] = trim((string) $p['gid']);
            }
        }

        return array_values(array_unique(array_filter($gids)));
    }
}
