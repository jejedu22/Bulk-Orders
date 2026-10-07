<?php

namespace App\Service;

use App\Entity\Newsletter;
use App\Entity\User;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpFoundation\UriSigner;
use Symfony\Component\Mailer\Exception\TransportExceptionInterface;
use Symfony\Component\Mailer\Transport\TransportInterface;
use Symfony\Component\Mime\Email;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Twig\Environment;

/**
 * Envoi des newsletters, un e-mail par abonné, par l'API Mailjet configurée
 * dans l'administration (Paramètres → Mailjet) ; à défaut, par le transport
 * « newsletter » (NEWSLETTER_MAILER_DSN). Chaque e-mail porte un lien de
 * désinscription signé, aussi exposé dans l'en-tête List-Unsubscribe pour le
 * bouton « Se désabonner » des messageries.
 */
class NewsletterSender
{
    private $mailSender;
    private $twig;
    private $urlGenerator;
    private $uriSigner;
    private $mailjet;
    private $logger;

    public function __construct(MailSender $mailSender, Environment $twig, UrlGeneratorInterface $urlGenerator, UriSigner $uriSigner, Mailjet $mailjet, LoggerInterface $logger)
    {
        $this->mailSender = $mailSender;
        $this->twig = $twig;
        $this->urlGenerator = $urlGenerator;
        $this->uriSigner = $uriSigner;
        $this->mailjet = $mailjet;
        $this->logger = $logger;
    }

    /**
     * Lien de désinscription signé : il ne fonctionne que pour cet utilisateur.
     */
    public function unsubscribeUrl(User $user): string
    {
        return $this->uriSigner->sign(
            $this->urlGenerator->generate('newsletter_unsubscribe', ['id' => $user->getId()], UrlGeneratorInterface::ABSOLUTE_URL)
        );
    }

    /**
     * Envoie la newsletter à chacun des destinataires ; retourne le nombre d'échecs.
     *
     * @param User[] $recipients
     */
    public function send(Newsletter $newsletter, array $recipients): int
    {
        $transport = $this->transport();
        $failed = 0;
        foreach ($recipients as $user) {
            if (!$this->sendTo($newsletter, $user, $transport)) {
                ++$failed;
            }
        }

        return $failed;
    }

    public function sendTo(Newsletter $newsletter, User $user, ?TransportInterface $transport = null): bool
    {
        try {
            $email = $this->mailSender->withSender(new Email(), $this->mailjet->sender());
        } catch (\LogicException $e) {
            return false;
        }

        $unsubscribeUrl = $this->unsubscribeUrl($user);
        $email
            ->to($user->getMail())
            ->subject($newsletter->getSubject())
            ->html($this->twig->render('newsletter/email.html.twig', [
                'newsletter' => $newsletter,
                'user' => $user,
                'unsubscribe_url' => $unsubscribeUrl,
            ]));
        $email->getHeaders()
            ->addTextHeader('List-Unsubscribe', '<' . $unsubscribeUrl . '>')
            ->addTextHeader('List-Unsubscribe-Post', 'List-Unsubscribe=One-Click');

        $transport = $transport ?? $this->transport();
        if (null === $transport) {
            $email->getHeaders()->addTextHeader('X-Transport', 'newsletter');

            return $this->mailSender->send($email);
        }

        try {
            $transport->send($email);

            return true;
        } catch (TransportExceptionInterface $e) {
            $this->logger->error('Échec de l\'envoi de la newsletter « {subject} » à {to} par Mailjet : {error}', [
                'subject' => $newsletter->getSubject(),
                'to' => $user->getMail(),
                'error' => $e->getMessage(),
                'exception' => $e,
            ]);

            return false;
        }
    }

    /**
     * Transport Mailjet de l'administration, ou null pour le transport « newsletter » du mailer.
     */
    private function transport(): ?TransportInterface
    {
        return $this->mailjet->isConfigured() ? $this->mailjet->transport() : null;
    }
}
