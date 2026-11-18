<?php

namespace App\Command;

use App\Entity\ContentActionLog;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Passage de 28 à 12 statuts.
 *
 * 1) déplace les contenus des anciens statuts vers le nouveau parcours (journal écrit pour chaque contenu) ;
 * 2) supprime ensuite les statuts devenus vides — refuse d'en supprimer un qui a encore un contenu.
 *
 * Aucune tâche Asana n'est créée : on écrit directement en base, sans passer par le workflow.
 * Tout se fait dans une transaction : en cas de problème, rien n'est modifié.
 *
 *   php bin/console app:status:simplify --dry-run   (affiche ce qui sera fait)
 *   php bin/console app:status:simplify             (exécute)
 */
#[AsCommand(name: 'app:status:simplify', description: 'Migre les contenus vers les 12 statuts cibles puis supprime les statuts obsolètes.')]
final class SimplifyStatusesCommand extends Command
{
    /**
     * Ordre important : les règles « avec / sans sous-titres » portent sur le même statut source.
     *
     * @var list<array{from: string, to: string, subtitles: ?bool}>
     */
    private const MOVES = [
        ['from' => 'À valider (Prod)', 'to' => 'À valider (CM)', 'subtitles' => false],
        ['from' => 'À valider (Prod)', 'to' => 'Sous-titres à valider', 'subtitles' => true],
        ['from' => 'Prépa CM (sans sous-titres)', 'to' => 'À valider (CM)', 'subtitles' => null],
        ['from' => 'À faire valider au client', 'to' => 'À valider (Client)', 'subtitles' => null],
        ['from' => 'Brouillon (Dérush)', 'to' => 'Montage à faire', 'subtitles' => null],
    ];

    /** Statuts supprimés (doivent être vides au moment de la suppression). */
    private const TO_DELETE = [
        'À déterminer',
        'Design à faire',
        'Légende à faire',
        'Miniature à faire',
        'Metricool',
        'OK',
        'Contenu à faire valider',
        'Rushs / à dispatcher',
        'Montage en cours',
        'Retouches (Monteur)',
        'Sous-titrage (SubMagic)',
        // vidés par les déplacements ci-dessus
        'À valider (Prod)',
        'Prépa CM (sans sous-titres)',
        'À faire valider au client',
        'Brouillon (Dérush)',
    ];

    /** Doublon : on supprime uniquement l'exemplaire sans contenu. */
    private const DEDUPLICATE = 'Tournage à prévoir';

    /** Les 12 statuts attendus à la fin. */
    private const EXPECTED_FINAL = [
        'Tournage à prévoir', 'Montage à faire', 'Sous-titres à valider', 'À valider (CM)', 'À valider (Client)',
        'Prête à programmer', 'Programmée', 'Publiée',
        'Brouillon (idée)', 'En préparation', 'À valider (post)', 'Prêt à publier',
    ];

    public function __construct(private readonly Connection $connection)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('dry-run', null, InputOption::VALUE_NONE, 'Affiche le plan sans rien modifier.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $dryRun = (bool) $input->getOption('dry-run');

        $statuses = $this->connection->fetchAllAssociative(
            'SELECT s.id, s.name, (SELECT COUNT(*) FROM content c WHERE c.status_id = s.id) AS n FROM status s ORDER BY s.sort_order, s.name',
        );
        $io->section(sprintf('Statuts actuels (%d)', count($statuses)));
        $io->table(['id', 'Statut', 'Contenus'], array_map(static fn (array $s) => [$s['id'], $s['name'], $s['n']], $statuses));

        $idsByName = [];
        foreach ($statuses as $s) {
            $idsByName[$s['name']][] = ['id' => (int) $s['id'], 'n' => (int) $s['n']];
        }

        $single = static function (string $name) use ($idsByName): ?int {
            $rows = $idsByName[$name] ?? [];

            return count($rows) === 1 ? $rows[0]['id'] : null;
        };

        // --- Contrôles préalables ---
        $errors = [];
        foreach (self::MOVES as $move) {
            foreach ([$move['from'], $move['to']] as $name) {
                if (isset($idsByName[$name]) && count($idsByName[$name]) > 1) {
                    $errors[] = sprintf('Statut « %s » présent %d fois.', $name, count($idsByName[$name]));
                }
            }
            if ($single($move['to']) === null && !isset($idsByName[$move['to']])) {
                $errors[] = sprintf('Statut cible « %s » introuvable.', $move['to']);
            }
        }
        $dupRows = $idsByName[self::DEDUPLICATE] ?? [];
        $dupToDelete = [];
        if (count($dupRows) > 1) {
            $withContent = array_values(array_filter($dupRows, static fn (array $r) => $r['n'] > 0));
            $empty = array_values(array_filter($dupRows, static fn (array $r) => $r['n'] === 0));
            if (count($withContent) > 1) {
                $errors[] = sprintf('« %s » : plusieurs exemplaires ont des contenus, fusion manuelle nécessaire.', self::DEDUPLICATE);
            } else {
                // On garde celui qui a des contenus (ou le plus ancien si aucun n'en a).
                $keep = $withContent[0]['id'] ?? min(array_column($dupRows, 'id'));
                foreach ($empty as $r) {
                    if ($r['id'] !== $keep) {
                        $dupToDelete[] = $r['id'];
                    }
                }
            }
        }
        if ($errors !== []) {
            $io->error($errors);

            return Command::FAILURE;
        }

        // --- Plan des déplacements ---
        $plan = [];
        foreach (self::MOVES as $move) {
            $fromId = $single($move['from']);
            if ($fromId === null) {
                $plan[] = $move + ['fromId' => null, 'toId' => $single($move['to']), 'ids' => []];
                continue;
            }
            $sql = 'SELECT id FROM content WHERE status_id = :from';
            if ($move['subtitles'] === true) {
                $sql .= ' AND video_has_subtitles = TRUE';
            } elseif ($move['subtitles'] === false) {
                $sql .= ' AND (video_has_subtitles IS NULL OR video_has_subtitles = FALSE)';
            }
            $ids = array_map('intval', $this->connection->fetchFirstColumn($sql.' ORDER BY id', ['from' => $fromId]));
            $plan[] = $move + ['fromId' => $fromId, 'toId' => $single($move['to']), 'ids' => $ids];
        }

        $io->section('Déplacements');
        $io->table(['De', 'Vers', 'Condition', 'Contenus'], array_map(static fn (array $p) => [
            $p['from'],
            $p['to'],
            $p['subtitles'] === true ? 'avec sous-titres' : ($p['subtitles'] === false ? 'sans sous-titres' : '—'),
            count($p['ids']),
        ], $plan));
        $io->text(sprintf('Total : %d contenu(s).', array_sum(array_map(static fn (array $p) => count($p['ids']), $plan))));

        $io->section('Suppressions');
        $deleteRows = [];
        foreach (self::TO_DELETE as $name) {
            foreach ($idsByName[$name] ?? [] as $r) {
                $deleteRows[] = [$r['id'], $name, $r['n'] > 0 ? $r['n'].' (seront déplacés)' : '0'];
            }
        }
        foreach ($dupToDelete as $id) {
            $deleteRows[] = [$id, self::DEDUPLICATE.' (doublon)', '0'];
        }
        $io->table(['id', 'Statut', 'Contenus avant migration'], $deleteRows);

        if ($dryRun) {
            $io->note('Dry-run : rien n\'a été modifié.');

            return Command::SUCCESS;
        }

        $now = (new \DateTimeImmutable())->format('Y-m-d H:i:s');
        $this->connection->beginTransaction();
        try {
            foreach ($plan as $p) {
                if ($p['ids'] === []) {
                    continue;
                }
                foreach ($p['ids'] as $contentId) {
                    $this->connection->insert('content_action_log', [
                        'content_id' => $contentId,
                        'action_type' => ContentActionLog::TYPE_MANUAL_STATUS,
                        'label' => 'Changement de statut (migration)',
                        'detail' => sprintf("Statut : %s → %s\nSource : migration statuts (28 → 12)", $p['from'], $p['to']),
                        'user_id' => null,
                        'created_at' => $now,
                    ]);
                }
                $this->connection->executeStatement(
                    'UPDATE content SET status_id = :to, updated_at = :now WHERE id IN (:ids)',
                    ['to' => $p['toId'], 'now' => $now, 'ids' => $p['ids']],
                    ['ids' => ArrayParameterType::INTEGER],
                );
            }

            $idsToDelete = $dupToDelete;
            foreach (self::TO_DELETE as $name) {
                foreach ($idsByName[$name] ?? [] as $r) {
                    $idsToDelete[] = $r['id'];
                }
            }
            if ($idsToDelete !== []) {
                $remaining = (int) $this->connection->fetchOne(
                    'SELECT COUNT(*) FROM content WHERE status_id IN (:ids)',
                    ['ids' => $idsToDelete],
                    ['ids' => ArrayParameterType::INTEGER],
                );
                if ($remaining > 0) {
                    throw new \RuntimeException(sprintf('%d contenu(s) encore rattaché(s) à un statut à supprimer — annulation.', $remaining));
                }
                $this->connection->executeStatement(
                    'DELETE FROM status WHERE id IN (:ids)',
                    ['ids' => $idsToDelete],
                    ['ids' => ArrayParameterType::INTEGER],
                );
            }

            $this->connection->commit();
        } catch (\Throwable $e) {
            $this->connection->rollBack();
            $io->error('Migration annulée, rien n\'a été modifié : '.$e->getMessage());

            return Command::FAILURE;
        }

        $final = $this->connection->fetchFirstColumn('SELECT name FROM status ORDER BY sort_order, name');
        $io->success(sprintf('Migration terminée. %d statut(s) restant(s).', count($final)));
        $io->listing($final);

        $missing = array_diff(self::EXPECTED_FINAL, $final);
        $extra = array_diff($final, self::EXPECTED_FINAL);
        if ($missing !== [] || $extra !== []) {
            $io->warning(array_filter([
                $missing !== [] ? 'Manquants : '.implode(', ', $missing) : null,
                $extra !== [] ? 'En trop : '.implode(', ', $extra) : null,
            ]));
        }

        return Command::SUCCESS;
    }
}
