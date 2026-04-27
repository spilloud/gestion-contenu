<?php

namespace App\Command;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Contracts\HttpClient\HttpClientInterface;

#[AsCommand(
    name: 'app:asana:ping',
    description: 'Teste la connexion Asana et liste quelques projets du workspace.',
)]
class AsanaPingCommand extends Command
{
    public function __construct(
        private readonly HttpClientInterface $httpClient,
    ) {
        parent::__construct();
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

        $token = trim((string) $token);
        $workspaceGid = trim((string) $workspaceGid);

        $io->section('Asana: users/me');
        try {
            $resp = $this->httpClient->request('GET', 'https://app.asana.com/api/1.0/users/me', [
                'headers' => ['Authorization' => 'Bearer '.$token],
            ]);
            $status = $resp->getStatusCode();
            $data = $resp->toArray(false);
        } catch (\Throwable $e) {
            $io->error('Erreur réseau/HTTP: '.$e->getMessage());
            return Command::FAILURE;
        }

        if ($status < 200 || $status >= 300) {
            $io->error('Asana refuse le token (HTTP '.$status.').');
            $io->writeln(json_encode($data, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
            return Command::FAILURE;
        }

        $meGid = $data['data']['gid'] ?? null;
        $meName = $data['data']['name'] ?? null;
        $meEmail = $data['data']['email'] ?? null;
        $io->success('OK: connecté.');
        $io->writeln('Utilisateur: '.trim((string) $meName).' <'.trim((string) $meEmail).'> (gid: '.trim((string) $meGid).')');

        $io->section('Asana: projets du workspace (aperçu)');
        try {
            $resp = $this->httpClient->request('GET', 'https://app.asana.com/api/1.0/workspaces/'.$workspaceGid.'/projects', [
                'headers' => ['Authorization' => 'Bearer '.$token],
                'query' => [
                    'archived' => 'false',
                    'limit' => 50,
                    'opt_fields' => 'name,gid',
                ],
            ]);
            $status = $resp->getStatusCode();
            $projects = $resp->toArray(false);
        } catch (\Throwable $e) {
            $io->error('Erreur lors du listing projets: '.$e->getMessage());
            return Command::FAILURE;
        }

        if ($status < 200 || $status >= 300) {
            $io->error('Impossible de lister les projets (HTTP '.$status.').');
            $io->writeln(json_encode($projects, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
            return Command::FAILURE;
        }

        $rows = [];
        foreach (($projects['data'] ?? []) as $p) {
            $rows[] = [
                (string) ($p['name'] ?? ''),
                (string) ($p['gid'] ?? ''),
            ];
        }

        if ($rows === []) {
            $io->warning('Aucun projet retourné (ou droits insuffisants).');
        } else {
            $io->table(['Projet', 'GID'], array_slice($rows, 0, 15));
        }

        $io->success('Ping Asana OK.');
        return Command::SUCCESS;
    }
}

