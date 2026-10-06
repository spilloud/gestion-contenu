<?php

namespace App\Command;

use App\Entity\Content;
use App\Entity\Status;
use App\Service\AsanaService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Lecture seule : état réel dans Asana des tâches rattachées à des contenus
 * déjà marqués « Publiée ». Répond à la question « reste-t-il des tâches
 * ouvertes derrière des contenus publiés ? » sans rien modifier.
 */
#[AsCommand(
    name: 'app:asana:audit-orphan-tasks',
    description: 'Compte les tâches Asana ouvertes, terminées ou absentes derrière les contenus publiés (lecture seule).',
)]
final class AsanaAuditOrphanTasksCommand extends Command
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly AsanaService $asanaService,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('client', null, InputOption::VALUE_REQUIRED, 'Limiter à un client (id)')
            ->addOption('updated-on', null, InputOption::VALUE_REQUIRED, 'Limiter aux contenus modifiés ce jour-là (Y-m-d)')
            ->addOption('limit', null, InputOption::VALUE_REQUIRED, 'Nombre maximum de tâches à interroger', '40');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        if (!$this->asanaService->isEnabled()) {
            $io->error('Asana non configuré.');

            return Command::FAILURE;
        }

        $published = $this->entityManager->getRepository(Status::class)->findOneBy(['name' => 'Publiée']);
        if ($published === null) {
            $io->error('Statut Publiée introuvable.');

            return Command::FAILURE;
        }

        $qb = $this->entityManager->createQueryBuilder()
            ->select('c')
            ->from(Content::class, 'c')
            ->innerJoin('c.status', 's')
            ->andWhere('s.id = :publishedId')
            ->andWhere('c.asanaTaskGid IS NOT NULL OR c.asanaSubtitlesTaskGid IS NOT NULL')
            ->setParameter('publishedId', $published->getId())
            ->orderBy('c.id', 'ASC');

        $clientOption = trim((string) $input->getOption('client'));
        if ($clientOption !== '') {
            $qb->innerJoin('c.client', 'cl')->andWhere('cl.id = :clientId')->setParameter('clientId', (int) $clientOption);
        }

        $updatedOn = trim((string) $input->getOption('updated-on'));
        if ($updatedOn !== '') {
            $day = \DateTimeImmutable::createFromFormat('!Y-m-d', $updatedOn);
            if ($day === false) {
                $io->error('--updated-on doit être au format Y-m-d.');

                return Command::INVALID;
            }
            $qb->andWhere('c.updatedAt >= :dayStart')->andWhere('c.updatedAt < :dayEnd')
                ->setParameter('dayStart', $day)
                ->setParameter('dayEnd', $day->modify('+1 day'));
        }

        /** @var Content[] $contents */
        $contents = $qb->getQuery()->getResult();

        $pairs = [];
        foreach ($contents as $content) {
            foreach ([['montage', $content->getAsanaTaskGid()], ['sous-titres', $content->getAsanaSubtitlesTaskGid()]] as [$kind, $gid]) {
                $gid = trim((string) ($gid ?? ''));
                if ($gid !== '') {
                    $pairs[] = [$content, $kind, $gid];
                }
            }
        }

        $limit = max(1, (int) $input->getOption('limit'));
        $io->writeln(sprintf(
            'Contenus publiés avec au moins un GID Asana : <info>%d</info> — soit <info>%d</info> tâches référencées. Interrogation d un échantillon de %d.',
            count($contents),
            count($pairs),
            min($limit, count($pairs)),
        ));

        $counts = ['ouverte' => 0, 'terminée' => 0, 'absente' => 0];
        $openExamples = [];
        $asked = 0;
        foreach ($pairs as [$content, $kind, $gid]) {
            if ($asked >= $limit) {
                break;
            }
            ++$asked;

            $task = $this->asanaService->fetchTask($gid);
            if ($task === null) {
                ++$counts['absente'];
                continue;
            }
            if (!empty($task['completed'])) {
                ++$counts['terminée'];
                continue;
            }

            ++$counts['ouverte'];
            if (count($openExamples) < 10) {
                $openExamples[] = sprintf('contenu %d (%s) — %s', $content->getId(), $kind, trim((string) ($task['name'] ?? $gid)));
            }
        }

        $io->table(
            ['État dans Asana', 'Tâches'],
            [
                ['Encore ouverte', $counts['ouverte']],
                ['Déjà terminée', $counts['terminée']],
                ['Absente / supprimée', $counts['absente']],
            ],
        );

        if ($openExamples !== []) {
            $io->section('Exemples de tâches encore ouvertes');
            $io->listing($openExamples);
        }

        $io->note(sprintf('Échantillon de %d tâche(s) sur %d référencées. Aucune écriture.', $asked, count($pairs)));

        return Command::SUCCESS;
    }
}
