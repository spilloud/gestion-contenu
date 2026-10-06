<?php

namespace App\Command;

use App\Entity\Content;
use App\Entity\ContentActionLog;
use App\Entity\Status;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Écrit les entrées de journal manquantes pour des changements de statut déjà
 * appliqués en base, à partir d'un CSV « content_id,old_status_id ».
 *
 * Nécessaire parce qu'un log persisté depuis ContentAuditSubscriber::preUpdate
 * n'est jamais inséré : Doctrine vide ses files d'écriture à la fin du commit
 * qui a déclenché l'événement. En contexte CLI, rien ne rattrape l'oubli.
 */
#[AsCommand(
    name: 'app:content:backfill-status-journal',
    description: 'Crée les entrées de journal manquantes pour des changements de statut déjà appliqués.',
)]
final class BackfillStatusJournalCommand extends Command
{
    private const LABEL = 'Changement de statut (manuel)';
    private const BATCH_SIZE = 50;

    public function __construct(private readonly EntityManagerInterface $entityManager)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('file', null, InputOption::VALUE_REQUIRED, 'CSV avec en-tête content_id,old_status_id')
            ->addOption('to-status', null, InputOption::VALUE_REQUIRED, 'Nom du statut d arrivée', 'Publiée')
            ->addOption('actor', null, InputOption::VALUE_REQUIRED, 'Email du compte auteur des entrées')
            ->addOption('apply', null, InputOption::VALUE_NONE, 'Écrit réellement les entrées');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $path = (string) $input->getOption('file');
        if ($path === '' || !is_readable($path)) {
            $io->error(sprintf('--file est obligatoire et doit être lisible (reçu : %s).', $path !== '' ? $path : 'vide'));

            return Command::INVALID;
        }

        $actorEmail = trim((string) $input->getOption('actor'));
        $actor = $actorEmail === ''
            ? null
            : $this->entityManager->getRepository(User::class)->findOneBy(['email' => $actorEmail]);
        if (!$actor instanceof User) {
            $io->error(sprintf('--actor est obligatoire et doit correspondre à un compte (reçu : %s).', $actorEmail !== '' ? $actorEmail : 'vide'));

            return Command::INVALID;
        }

        $toName = (string) $input->getOption('to-status');
        $toStatus = $this->entityManager->getRepository(Status::class)->findOneBy(['name' => $toName]);
        if ($toStatus === null) {
            $io->error(sprintf('Statut « %s » introuvable en base.', $toName));

            return Command::FAILURE;
        }

        $statusNames = [];
        foreach ($this->entityManager->getRepository(Status::class)->findAll() as $status) {
            $statusNames[(int) $status->getId()] = (string) $status->getName();
        }

        $handle = fopen($path, 'r');
        if ($handle === false) {
            $io->error('Lecture du fichier impossible.');

            return Command::FAILURE;
        }

        $planned = [];
        $problems = [];
        $lineNumber = 0;
        while (($row = fgetcsv($handle, 0, ',')) !== false) {
            ++$lineNumber;
            if ($row === [null] || $row === false) {
                continue;
            }
            if ($lineNumber === 1 && isset($row[0]) && !ctype_digit(trim((string) $row[0]))) {
                continue; // en-tête
            }
            if (!isset($row[0], $row[1])) {
                $problems[] = sprintf('ligne %d : deux colonnes attendues', $lineNumber);
                continue;
            }

            $contentId = (int) trim((string) $row[0]);
            $oldStatusId = (int) trim((string) $row[1]);
            $content = $this->entityManager->getRepository(Content::class)->find($contentId);
            if ($content === null) {
                $problems[] = sprintf('ligne %d : contenu %d introuvable', $lineNumber, $contentId);
                continue;
            }
            if (!isset($statusNames[$oldStatusId])) {
                $problems[] = sprintf('ligne %d : statut %d introuvable', $lineNumber, $oldStatusId);
                continue;
            }

            $planned[] = [$content, $statusNames[$oldStatusId]];
        }
        fclose($handle);

        if ($problems !== []) {
            $io->warning(sprintf('%d ligne(s) ignorée(s) :', count($problems)));
            $io->listing(array_slice($problems, 0, 20));
        }

        if ($planned === []) {
            $io->error('Aucune entrée à créer.');

            return Command::FAILURE;
        }

        $sample = $planned[0];
        $io->writeln(sprintf('Entrées à créer : <info>%d</info>, au nom de %s.', count($planned), $actor->getName() ?? $actorEmail));
        $io->writeln('Exemple pour le contenu '.$sample[0]->getId().' :');
        $io->writeln('  label  : '.self::LABEL);
        $io->writeln('  detail : '.str_replace("\n", ' | ', $this->buildDetail($sample[1], $toName, $actor)));

        if (!$input->getOption('apply')) {
            $io->warning('Simulation : rien n a été écrit. Relancez avec --apply pour appliquer.');

            return Command::SUCCESS;
        }

        $done = 0;
        foreach ($planned as [$content, $oldName]) {
            $log = new ContentActionLog();
            $log->setContent($content);
            $log->setActionType(ContentActionLog::TYPE_MANUAL_STATUS);
            $log->setLabel(self::LABEL);
            $log->setDetail($this->buildDetail($oldName, $toName, $actor));
            $log->setUser($actor);
            $this->entityManager->persist($log);

            ++$done;
            if ($done % self::BATCH_SIZE === 0) {
                $this->entityManager->flush();
            }
        }
        $this->entityManager->flush();

        $io->success(sprintf('%d entrées de journal créées au nom de %s.', $done, $actor->getName() ?? $actorEmail));

        return Command::SUCCESS;
    }

    private function buildDetail(string $fromName, string $toName, User $actor): string
    {
        return implode("\n", [
            sprintf('%s → %s', $fromName, $toName),
            'Par : '.($actor->getName() ?? (string) $actor->getEmail()),
        ]);
    }
}
