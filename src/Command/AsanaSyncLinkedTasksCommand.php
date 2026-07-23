<?php

namespace App\Command;

use App\Service\AsanaBidirectionalSyncService;
use App\Service\AsanaService;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'app:asana:sync-linked-tasks',
    description: 'Synchronise Asana → Lucy (assigné, échéance, complétion) pour toutes les tâches liées.',
)]
final class AsanaSyncLinkedTasksCommand extends Command
{
    public function __construct(
        private readonly AsanaService $asanaService,
        private readonly AsanaBidirectionalSyncService $syncService,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        if (!$this->asanaService->isEnabled()) {
            $io->warning('Asana non configuré (ASANA_ACCESS_TOKEN manquant).');

            return Command::SUCCESS;
        }

        $io->title('Sync Asana ↔ Lucy');

        $result = $this->syncService->syncAll();

        $io->table(
            ['Mises à jour', 'Ignorées', 'Erreurs'],
            [[(string) $result['updated'], (string) $result['skipped'], (string) $result['errors']]],
        );

        if ($result['errors'] > 0) {
            $io->warning(sprintf('%d erreur(s) — voir les logs applicatifs.', $result['errors']));

            return Command::FAILURE;
        }

        $io->success('Synchronisation terminée.');

        return Command::SUCCESS;
    }
}
