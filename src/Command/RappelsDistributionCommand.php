<?php

namespace App\Command;

use App\Service\RappelsDistribution;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Rappels automatiques de la distribution, la veille (et le jour même si la
 * veille a été manquée). Lancée toutes les heures par le service « scheduler »
 * de docker-compose.yml ; chaque personne ne reçoit le rappel qu'une fois.
 */
#[AsCommand(name: 'app:distribution:rappels', description: 'Envoie les rappels des distributions de demain (clients et bénévoles)')]
class RappelsDistributionCommand extends Command
{
    // Pas d'e-mail la nuit
    public const HEURE_MIN = 8;

    private $rappels;

    public function __construct(RappelsDistribution $rappels)
    {
        parent::__construct();
        $this->rappels = $rappels;
    }

    protected function configure(): void
    {
        $this->addOption('force', null, InputOption::VALUE_NONE, sprintf('Envoyer même avant %d h', self::HEURE_MIN));
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $maintenant = new \DateTimeImmutable();
        if (!$input->getOption('force') && (int) $maintenant->format('G') < self::HEURE_MIN) {
            $io->writeln(sprintf('Avant %d h : aucun rappel envoyé.', self::HEURE_MIN));

            return Command::SUCCESS;
        }

        $echecs = 0;
        foreach ($this->rappels->ventesARappeler($maintenant) as $jour) {
            $resultat = $this->rappels->envoyer($jour);
            $io->writeln(sprintf('Distribution du %s : %d rappel(s) envoyé(s)%s.',
                $jour->getDateDistribution()->format('d/m/Y'),
                $resultat['sent'],
                $resultat['failed'] ? ', échec pour ' . implode(', ', $resultat['failed']) : ''));
            $echecs += \count($resultat['failed']);
        }

        return $echecs > 0 ? Command::FAILURE : Command::SUCCESS;
    }
}
