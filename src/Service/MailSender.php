<?php

namespace App\Service;

use App\Controller\PwaController;
use App\Entity\Commande;
use Psr\Log\LoggerInterface;
use Symfony\Component\Mailer\Exception\TransportExceptionInterface;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\Address;
use Symfony\Component\Mime\Email;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Twig\Environment;

/**
 * Envoi des e-mails de l'application.
 *
 * Un échec d'envoi (SMTP injoignable, identifiants refusés…) est journalisé
 * au lieu de faire échouer la requête : la commande est déjà enregistrée à
 * ce moment-là, une page d'erreur pousserait l'utilisateur à recommencer.
 */
class MailSender
{
    private $mailer;
    private $settings;
    private $logger;
    private $mailerFrom;
    private $twig;
    private $urlGenerator;
    private $logoDirectory;

    public function __construct(MailerInterface $mailer, OptionsSettings $settings, LoggerInterface $logger, string $mailerFrom = '', ?Environment $twig = null, ?UrlGeneratorInterface $urlGenerator = null, string $logoDirectory = '')
    {
        $this->mailer = $mailer;
        $this->settings = $settings;
        $this->logger = $logger;
        $this->mailerFrom = trim($mailerFrom);
        $this->twig = $twig;
        $this->urlGenerator = $urlGenerator;
        $this->logoDirectory = $logoDirectory;
    }

    /**
     * Habillage commun des e-mails (templates/emails/layout.html.twig) : joint
     * le logo de la configuration à l'e-mail et retourne les variables du
     * bandeau (couleur du thème, nom, logo) et du pied de page.
     */
    public function brand(Email $email): array
    {
        $logoCid = null;
        $logo = basename($this->settings->get('logo'));
        $path = $this->logoDirectory . '/' . $logo;
        if ('' !== $logo && '' !== $this->logoDirectory && is_file($path) && false !== @getimagesize($path)) {
            // Joint à l'e-mail : affiché même si la messagerie bloque les images distantes
            $email->embedFromPath($path, 'logo');
            $logoCid = 'cid:logo';
        }

        return [
            'name' => $this->settings->get('name'),
            'accent' => PwaController::COLORS[$this->settings->get('color')] ?? PwaController::COLORS['green'],
            'logo_cid' => $logoCid,
            'contact_email' => trim($this->settings->get('contact_email')),
            'site_url' => $this->url('passe_commande_index'),
        ];
    }

    private function url(string $route): ?string
    {
        return null !== $this->urlGenerator ? $this->urlGenerator->generate($route, [], UrlGeneratorInterface::ABSOLUTE_URL) : null;
    }

    /**
     * Expéditeur : MAILER_FROM s'il est défini (adresse du compte SMTP, certains
     * fournisseurs refusent tout autre expéditeur), sinon l'e-mail de contact.
     * L'e-mail de contact reste l'adresse de réponse. $from remplace les deux
     * (expéditeur validé dans Mailjet pour les newsletters).
     */
    public function withSender(Email $email, ?string $from = null): Email
    {
        $contact = trim($this->settings->get('contact_email'));
        $from = trim((string) $from);
        if ('' === $from) {
            $from = '' !== $this->mailerFrom ? $this->mailerFrom : $contact;
        }
        if ('' === $from) {
            $message = 'E-mail non envoyé : aucun expéditeur, renseigner MAILER_FROM ou l\'e-mail de contact dans la configuration.';
            $this->logger->error($message);
            throw new \LogicException($message);
        }

        $email->from(new Address($from, $this->settings->get('name')));
        if ('' !== $contact && $contact !== $from) {
            $email->replyTo($contact);
        }

        return $email;
    }

    /**
     * Envoie l'e-mail ; retourne false (et journalise l'erreur) en cas d'échec.
     */
    public function send(Email $email): bool
    {
        try {
            $this->mailer->send($email);

            return true;
        } catch (TransportExceptionInterface | \LogicException $e) {
            $this->logger->error('Échec de l\'envoi de l\'e-mail « {subject} » à {to} : {error}', [
                'subject' => $email->getSubject(),
                'to' => implode(', ', array_map(function (Address $a) { return $a->getAddress(); }, $email->getTo())),
                'error' => $e->getMessage(),
                'exception' => $e,
            ]);

            return false;
        }
    }

    /**
     * E-mail récapitulatif d'une commande (enregistrement ou confirmation).
     */
    public function sendCommande(Commande $commande, string $subject, string $message): bool
    {
        try {
            $email = $this->withSender(new Email());
        } catch (\LogicException $e) {
            return false;
        }

        $email
            ->to($commande->getUser()->getMail())
            ->subject($subject)
            ->html($this->twig->render('emails/commande.html.twig', [
                'brand' => $this->brand($email),
                'subject' => $subject,
                'message' => $message,
                'commande' => $commande,
                'commandes_url' => $this->url('commande_index'),
            ]));

        return $this->send($email);
    }
}
