<?php

namespace App\Command;

use App\Service\MailSender;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Mailer\Exception\TransportExceptionInterface;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\Email;

/**
 * Diagnostic de l'envoi d'e-mails : envoie un message de test et affiche
 * l'erreur exacte du serveur SMTP en cas d'échec.
 */
#[AsCommand(name: 'app:mail:test', description: 'Envoie un e-mail de test pour vérifier MAILER_DSN')]
class MailTestCommand extends Command
{
    private $mailer;
    private $mailSender;

    public function __construct(MailerInterface $mailer, MailSender $mailSender)
    {
        parent::__construct();
        $this->mailer = $mailer;
        $this->mailSender = $mailSender;
    }

    protected function configure(): void
    {
        $this
            ->addArgument('destinataire', InputArgument::REQUIRED, 'Adresse qui recevra le test');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $dsn = (string) getenv('MAILER_DSN') ?: ($_SERVER['MAILER_DSN'] ?? '');
        // Mot de passe masqué
        $io->text('MAILER_DSN : ' . preg_replace('#(://[^:@/]*:)[^@]*@#', '$1****@', $dsn));
        if ('' === $dsn || 0 === strpos($dsn, 'null://')) {
            $io->error('MAILER_DSN vaut « null://null » (ou est vide) : aucun e-mail n\'est envoyé. Le renseigner dans .env.docker puis recréer le conteneur.');

            return 1;
        }

        try {
            $email = $this->mailSender->withSender(new Email());
        } catch (\LogicException $e) {
            $io->error($e->getMessage());

            return 1;
        }
        $email
            ->to($input->getArgument('destinataire'))
            ->subject('Test d\'envoi')
            ->text('Si vous lisez ce message, l\'envoi d\'e-mails fonctionne.');
        $io->text('Expéditeur : ' . $email->getFrom()[0]->toString());

        try {
            // Envoi direct (sans MailSender::send) pour afficher l'erreur brute
            $this->mailer->send($email);
        } catch (TransportExceptionInterface $e) {
            $io->error('Échec : ' . $e->getMessage());
            if ($e->getDebug()) {
                $io->text('Dialogue SMTP :');
                $io->text(explode("\n", trim($e->getDebug())));
            }

            return 1;
        }

        $io->success('E-mail envoyé à ' . $input->getArgument('destinataire') . ' (vérifier aussi les spams).');

        return 0;
    }
}
