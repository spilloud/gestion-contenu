<?php

namespace App\Command;

use App\Repository\ContentRepository;
use App\Service\ContentFormatHelper;
use App\Service\VideoMontageAsanaTrigger;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'app:video:ensure-montage-asana',
    description: 'Crée les tâches Asana montage manquantes pour des vidéos en « Montage à faire ».',
)]
final class EnsureVideoMontageAsanaCommand extends Command
{
    public function __construct(
        private readonly ContentRepository $contentRepository,
        private readonly ContentFormatHelper $formatHelper,
        private readonly VideoMontageAsanaTrigger $montageAsanaTrigger,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('ids', null, InputOption::VALUE_REQUIRED, 'IDs contenu séparés par des virgules (ex. 247,248,249)')
            ->addOption('client', null, InputOption::VALUE_REQUIRED, 'Limiter au client (id numérique)')
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Affiche sans créer de tâche Asana');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $dryRun = (bool) $input->getOption('dry-run');

        $contents = [];
        $idsRaw = trim((string) $input->getOption('ids'));
        if ($idsRaw !== '') {
            foreach (explode(',', $idsRaw) as $part) {
                $id = (int) trim($part);
                if ($id <= 0) {
                    continue;
                }
                $content = $this->contentRepository->find($id);
                if ($content === null) {
                    $io->warning(sprintf('Contenu #%d introuvable.', $id));
                    continue;
                }
                $contents[$id] = $content;
            }
        } else {
            $clientId = (int) $input->getOption('client');
            $qb = $this->contentRepository->createQueryBuilder('c')
                ->leftJoin('c.status', 's')->addSelect('s')
                ->leftJoin('c.client', 'cl')->addSelect('cl')
                ->leftJoin('c.videoEditor', 'e')->addSelect('e')
                ->andWhere('s.name = :status')
                ->setParameter('status', 'Montage à faire');
            if ($clientId > 0) {
                $qb->andWhere('c.client = :client')->setParameter('client', $clientId);
            }
            foreach ($qb->getQuery()->getResult() as $content) {
                $contents[$content->getId()] = $content;
            }
        }

        if ($contents === []) {
            $io->warning('Aucune vidéo à traiter.');

            return Command::SUCCESS;
        }

        $created = 0;
        $linked = 0;
        $skipped = 0;
        foreach ($contents as $content) {
            if (!$this->formatHelper->isVideoContent($content)) {
                $io->writeln(sprintf('#%d : ignoré (pas une vidéo)', $content->getId()));
                ++$skipped;
                continue;
            }

            $status = $content->getStatus()?->getName() ?? '—';
            $editor = $content->getVideoEditor()?->getName()
                ?? $content->getClient()?->getEditor()?->getName()
                ?? '—';
            $asana = $content->getAsanaTaskGid() ?? '—';

            $io->writeln(sprintf(
                '#%d « %s » | statut: %s | monteur: %s | asana: %s',
                $content->getId(),
                $content->getTitle(),
                $status,
                $editor,
                $asana,
            ));

            if ($status !== 'Montage à faire') {
                $io->writeln('  → ignoré (statut différent de « Montage à faire »)');
                ++$skipped;
                continue;
            }
            $beforeGid = $content->getAsanaTaskGid();
            if ($dryRun) {
                $resolved = $this->montageAsanaTrigger->resolveMontageTaskLink($content, false);
                if ($resolved !== null && $resolved !== $beforeGid) {
                    $io->writeln('  → dry-run : lierait la tâche Asana '.$resolved);
                } elseif ($resolved !== null) {
                    $io->writeln('  → déjà liée à Asana : '.$resolved);
                } else {
                    $io->writeln('  → dry-run : tâche Asana à créer');
                }
                continue;
            }

            $resolved = $this->montageAsanaTrigger->resolveMontageTaskLink($content, true);
            if ($resolved !== null && $resolved !== $beforeGid) {
                $io->writeln('  → tâche Asana liée : '.$resolved);
                ++$linked;
                continue;
            }
            if ($resolved !== null) {
                $io->writeln('  → déjà une tâche Asana valide : '.$resolved);
                ++$skipped;
                continue;
            }

            if ($this->montageAsanaTrigger->ensureWhenMontageQueued($content)) {
                $io->writeln('  → tâche Asana créée : '.$content->getAsanaTaskGid());
                ++$created;
            } else {
                $io->writeln('  → échec création Asana (projet client, token, API…)');
                ++$skipped;
            }
        }

        $io->success(sprintf('%d créée(s), %d liée(s), %d ignorée(s)/échouée(s).', $created, $linked, $skipped));

        return $created > 0 || $skipped > 0 ? Command::SUCCESS : Command::FAILURE;
    }
}
