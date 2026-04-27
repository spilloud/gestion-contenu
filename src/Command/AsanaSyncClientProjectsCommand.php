<?php

namespace App\Command;

use App\Entity\Client;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Contracts\HttpClient\HttpClientInterface;

#[AsCommand(
    name: 'app:asana:sync-client-projects',
    description: 'Associe automatiquement les clients aux projets Asana (workspace) en fonction du nom.',
)]
class AsanaSyncClientProjectsCommand extends Command
{
    public function __construct(
        private readonly HttpClientInterface $httpClient,
        private readonly EntityManagerInterface $entityManager,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'N’écrit rien en base, affiche seulement les correspondances.')
            ->addOption('force', null, InputOption::VALUE_NONE, 'Écrase les asana_project_gid déjà renseignés.')
        ;
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $token = getenv('ASANA_ACCESS_TOKEN');
        $workspaceGid = getenv('ASANA_WORKSPACE_GID');

        if ($token === false || trim((string) $token) === '') {
            $io->error('ASANA_ACCESS_TOKEN manquant (vide).');
            return Command::FAILURE;
        }
        if ($workspaceGid === false || trim((string) $workspaceGid) === '') {
            $io->error('ASANA_WORKSPACE_GID manquant (vide).');
            return Command::FAILURE;
        }

        $dryRun = (bool) $input->getOption('dry-run');
        $force = (bool) $input->getOption('force');
        $token = trim((string) $token);
        $workspaceGid = trim((string) $workspaceGid);

        $projects = $this->fetchAllWorkspaceProjects($token, $workspaceGid);
        if ($projects === []) {
            $io->error('Aucun projet récupéré depuis Asana (droits ? workspace ?).');
            return Command::FAILURE;
        }

        // Build lookup by normalized name.
        $byNorm = [];
        foreach ($projects as $p) {
            $name = (string) ($p['name'] ?? '');
            $gid = (string) ($p['gid'] ?? '');
            if ($name === '' || $gid === '') {
                continue;
            }
            $norm = self::normalize($name);
            if ($norm === '') {
                continue;
            }
            $byNorm[$norm] ??= [];
            $byNorm[$norm][] = ['name' => $name, 'gid' => $gid];
        }

        /** @var Client[] $clients */
        $clients = $this->entityManager->getRepository(Client::class)->findBy([], ['name' => 'ASC']);

        $matched = [];
        $ambiguous = [];
        $unmatched = [];
        $skippedAlreadySet = [];

        foreach ($clients as $client) {
            $clientName = $client->getName() ?? '';
            $clientNorm = self::normalize($clientName);
            $current = $client->getAsanaProjectGid();

            if (!$force && $current !== null && trim($current) !== '') {
                $skippedAlreadySet[] = [$clientName, $current];
                continue;
            }

            $candidates = $byNorm[$clientNorm] ?? [];
            if (count($candidates) === 1) {
                $gid = $candidates[0]['gid'];
                $matched[] = [$clientName, $candidates[0]['name'], $gid];
                if (!$dryRun) {
                    $client->setAsanaProjectGid($gid);
                }
                continue;
            }

            // Heuristic: also try removing common suffixes/prefixes.
            $altNorms = array_values(array_unique(array_filter([
                $clientNorm,
                self::normalize(preg_replace('/\\b(marketing|agence)\\b/i', '', $clientName) ?? ''),
                self::normalize(str_replace(['!', '.', ',', '-', '_', '/'], ' ', $clientName)),
            ])));

            $found = [];
            foreach ($altNorms as $n) {
                foreach (($byNorm[$n] ?? []) as $cand) {
                    $found[$cand['gid']] = $cand;
                }
            }
            $found = array_values($found);
            if (count($found) === 1) {
                $gid = $found[0]['gid'];
                $matched[] = [$clientName, $found[0]['name'], $gid];
                if (!$dryRun) {
                    $client->setAsanaProjectGid($gid);
                }
                continue;
            }

            if (count($found) > 1) {
                $ambiguous[] = [$clientName, implode(' | ', array_map(static fn ($c) => $c['name'].' ('.$c['gid'].')', $found))];
            } else {
                $unmatched[] = $clientName;
            }
        }

        if (!$dryRun) {
            $this->entityManager->flush();
        }

        $io->title('Asana → mapping projets clients');
        $io->writeln('Mode: '.($dryRun ? 'DRY-RUN' : 'WRITE'));
        $io->writeln('Force: '.($force ? 'OUI (écrase)' : 'NON (ne touche pas ceux déjà mappés)'));

        $io->section('Correspondances appliquées');
        if ($matched === []) {
            $io->writeln('—');
        } else {
            $io->table(['Client', 'Projet Asana', 'Project GID'], array_slice($matched, 0, 50));
            if (count($matched) > 50) {
                $io->writeln('… ('.count($matched).' au total)');
            }
        }

        if ($skippedAlreadySet !== []) {
            $io->section('Ignorés (déjà renseignés)');
            $io->table(['Client', 'asana_project_gid'], array_slice($skippedAlreadySet, 0, 30));
            if (count($skippedAlreadySet) > 30) {
                $io->writeln('… ('.count($skippedAlreadySet).' au total)');
            }
        }

        if ($ambiguous !== []) {
            $io->section('Ambigus (à trancher)');
            $io->table(['Client', 'Candidats'], $ambiguous);
        }

        if ($unmatched !== []) {
            $io->section('Sans match');
            $io->writeln(implode(', ', array_slice($unmatched, 0, 80)));
            if (count($unmatched) > 80) {
                $io->writeln('… ('.count($unmatched).' au total)');
            }
        }

        return Command::SUCCESS;
    }

    /**
     * @return array<int, array{name?:string,gid?:string}>
     */
    private function fetchAllWorkspaceProjects(string $token, string $workspaceGid): array
    {
        $projects = [];
        $offset = null;

        while (true) {
            $query = [
                'archived' => 'false',
                'limit' => 100,
                'opt_fields' => 'name,gid',
            ];
            if ($offset !== null) {
                $query['offset'] = $offset;
            }

            $resp = $this->httpClient->request('GET', 'https://app.asana.com/api/1.0/workspaces/'.$workspaceGid.'/projects', [
                'headers' => ['Authorization' => 'Bearer '.$token],
                'query' => $query,
            ]);
            $data = $resp->toArray(false);

            foreach (($data['data'] ?? []) as $p) {
                $projects[] = $p;
            }

            $offset = $data['next_page']['offset'] ?? null;
            if (!is_string($offset) || $offset === '') {
                break;
            }
        }

        return $projects;
    }

    private static function normalize(string $value): string
    {
        $value = trim(mb_strtolower($value));
        if ($value === '') {
            return '';
        }
        $value = iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $value) ?: $value;
        $value = preg_replace('/[^a-z0-9]+/i', ' ', $value) ?? $value;
        $value = trim(preg_replace('/\\s+/', ' ', $value) ?? $value);

        return $value;
    }
}

