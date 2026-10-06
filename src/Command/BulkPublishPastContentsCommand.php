<?php

namespace App\Command;

use App\Entity\Content;
use App\Entity\Status;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;
use Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken;

/**
 * Rattrapage de masse : passe en « Publiée » les contenus d'un client dont la
 * date de publication est antérieure à une date pivot.
 *
 * Simulation par défaut ; --apply est nécessaire pour écrire.
 *
 * Le changement passe par l'entité, donc ContentAuditSubscriber journalise
 * chaque contenu exactement comme un passage manuel depuis la fiche. La
 * commande s'authentifie sous le compte --actor pour que le journal porte son
 * nom plutôt qu'aucun auteur ; l'horodatage est celui de l'exécution. Un statut
 * seul ne déclenche aucune synchronisation Asana.
 */
#[AsCommand(
    name: 'app:content:bulk-publish',
    description: 'Passe en Publiée les contenus passés d un client (simulation par défaut).',
)]
final class BulkPublishPastContentsCommand extends Command
{
    private const PUBLISHED_STATUS = 'Publiée';
    private const FIREWALL = 'main';
    private const BATCH_SIZE = 50;

    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly TokenStorageInterface $tokenStorage,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('client', null, InputOption::VALUE_REQUIRED, 'Id du client concerné')
            ->addOption('before', null, InputOption::VALUE_REQUIRED, 'Date pivot au format Y-m-d, exclue')
            ->addOption('actor', null, InputOption::VALUE_REQUIRED, 'Email du compte auteur des entrées de journal')
            ->addOption('exclude-status', null, InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY, 'Nom exact d un statut à ne pas toucher', [])
            ->addOption('apply', null, InputOption::VALUE_NONE, 'Écrit réellement les changements');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $clientId = (int) $input->getOption('client');
        if ($clientId <= 0) {
            $io->error('--client est obligatoire et doit être un id positif.');

            return Command::INVALID;
        }

        $before = \DateTimeImmutable::createFromFormat('!Y-m-d', (string) $input->getOption('before'));
        if ($before === false) {
            $io->error('--before est obligatoire et doit être au format Y-m-d (ex. 2026-09-30).');

            return Command::INVALID;
        }

        $actorEmail = trim((string) $input->getOption('actor'));
        if ($actorEmail === '') {
            $io->error('--actor est obligatoire : le journal doit porter un auteur.');

            return Command::INVALID;
        }
        $actor = $this->entityManager->getRepository(User::class)->findOneBy(['email' => $actorEmail]);
        if (!$actor instanceof User) {
            $io->error(sprintf('Aucun utilisateur avec l email %s.', $actorEmail));

            return Command::INVALID;
        }

        $published = $this->entityManager->getRepository(Status::class)->findOneBy(['name' => self::PUBLISHED_STATUS]);
        if ($published === null) {
            $io->error(sprintf('Statut « %s » introuvable en base.', self::PUBLISHED_STATUS));

            return Command::FAILURE;
        }

        /** @var string[] $excluded */
        $excluded = $input->getOption('exclude-status');

        $qb = $this->entityManager->createQueryBuilder()
            ->select('c')
            ->from(Content::class, 'c')
            ->innerJoin('c.client', 'cl')
            ->innerJoin('c.status', 's')
            ->andWhere('cl.id = :clientId')
            ->andWhere('c.scheduledDate < :before')
            ->andWhere('s.id <> :publishedId')
            ->setParameter('clientId', $clientId)
            ->setParameter('before', $before)
            ->setParameter('publishedId', $published->getId())
            ->orderBy('c.scheduledDate', 'ASC');

        if ($excluded !== []) {
            $qb->andWhere('s.name NOT IN (:excluded)')->setParameter('excluded', $excluded);
        }

        /** @var Content[] $contents */
        $contents = $qb->getQuery()->getResult();

        if ($contents === []) {
            $io->success('Aucun contenu à modifier.');

            return Command::SUCCESS;
        }

        $perStatus = [];
        foreach ($contents as $content) {
            $name = $content->getStatus()?->getName() ?? '—';
            $perStatus[$name] = ($perStatus[$name] ?? 0) + 1;
        }
        arsort($perStatus);

        $rows = [];
        foreach ($perStatus as $name => $count) {
            $rows[] = [$name, $count];
        }
        $io->table(['Statut actuel', 'Contenus'], $rows);
        $io->writeln(sprintf(
            'Total : <info>%d</info> contenus — client %d, date < %s, journal au nom de %s.',
            count($contents),
            $clientId,
            $before->format('Y-m-d'),
            $actor->getName() ?? $actorEmail,
        ));
        if ($excluded !== []) {
            $io->writeln('Statuts exclus : '.implode(', ', $excluded));
        }

        if (!$input->getOption('apply')) {
            $io->warning('Simulation : rien n a été écrit. Relancez avec --apply pour appliquer.');

            return Command::SUCCESS;
        }

        // Le journal lit l utilisateur courant via Security : on ouvre une
        // session CLI sous le compte --actor pour qu il en soit l auteur.
        $this->tokenStorage->setToken(new UsernamePasswordToken($actor, self::FIREWALL, $actor->getRoles()));

        $done = 0;
        foreach ($contents as $content) {
            $content->setStatus($published);
            $content->setUpdatedAt(new \DateTimeImmutable());
            ++$done;
            if ($done % self::BATCH_SIZE === 0) {
                $this->entityManager->flush();
            }
        }
        $this->entityManager->flush();
        // ContentAuditSubscriber persiste ses journaux pendant preUpdate : un
        // dernier flush les écrit en base.
        $this->entityManager->flush();

        $io->success(sprintf('%d contenus passés en « %s », journalisés au nom de %s.', $done, self::PUBLISHED_STATUS, $actor->getName() ?? $actorEmail));

        return Command::SUCCESS;
    }
}
