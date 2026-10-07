<?php

namespace App\Service;

use App\Entity\Commande;
use Psr\Log\LoggerInterface;
use Symfony\Component\Mailer\Exception\TransportExceptionInterface;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\Address;
use Symfony\Component\Mime\Email;

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

    public function __construct(MailerInterface $mailer, OptionsSettings $settings, LoggerInterface $logger, string $mailerFrom = '')
    {
        $this->mailer = $mailer;
        $this->settings = $settings;
        $this->logger = $logger;
        $this->mailerFrom = trim($mailerFrom);
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

        $cell = 'style="border: 1px solid black; padding: 4px 8px"';
        $html = $message . '<br><br><table style="border-collapse: collapse; border: 1px solid black">'
            . '<tr><th ' . $cell . '>Produit</th><th ' . $cell . '>Conditionnement</th>'
            . '<th ' . $cell . '>Prix unitaire initial</th><th ' . $cell . '>Quantité</th></tr>';
        foreach ($commande->getLigneCommandes() as $ligne) {
            $product = $ligne->getProduct();
            $html .= '<tr>'
                . '<td ' . $cell . '>' . htmlspecialchars($product->getNom()) . '</td>'
                . '<td ' . $cell . '>' . htmlspecialchars($product->getConditionnement() . $product->getUnit()) . '</td>'
                . '<td ' . $cell . '>' . number_format($product->getPrixInit(), 2, ',', ' ') . ' €</td>'
                . '<td ' . $cell . '>' . $ligne->getQuantite() . '</td>'
                . '</tr>';
        }
        $html .= '</table>';

        $email
            ->to($commande->getUser()->getMail())
            ->subject($subject)
            ->html($html);

        return $this->send($email);
    }
}
