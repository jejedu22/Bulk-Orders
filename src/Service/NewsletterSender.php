<?php

namespace App\Service;

use App\Entity\Newsletter;
use App\Entity\User;
use Symfony\Component\HttpFoundation\UriSigner;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Twig\Environment;
use Symfony\Component\Mime\Email;

/**
 * Envoi des newsletters, un e-mail par abonné, par le transport « newsletter »
 * (Mailjet, voir NEWSLETTER_MAILER_DSN). Chaque e-mail porte un lien de
 * désinscription signé, aussi exposé dans l'en-tête List-Unsubscribe pour le
 * bouton « Se désabonner » des messageries.
 */
class NewsletterSender
{
    private $mailSender;
    private $twig;
    private $urlGenerator;
    private $uriSigner;

    public function __construct(MailSender $mailSender, Environment $twig, UrlGeneratorInterface $urlGenerator, UriSigner $uriSigner)
    {
        $this->mailSender = $mailSender;
        $this->twig = $twig;
        $this->urlGenerator = $urlGenerator;
        $this->uriSigner = $uriSigner;
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
        $failed = 0;
        foreach ($recipients as $user) {
            if (!$this->sendTo($newsletter, $user)) {
                ++$failed;
            }
        }

        return $failed;
    }

    public function sendTo(Newsletter $newsletter, User $user): bool
    {
        try {
            $email = $this->mailSender->withSender(new Email());
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
            ->addTextHeader('X-Transport', 'newsletter')
            ->addTextHeader('List-Unsubscribe', '<' . $unsubscribeUrl . '>')
            ->addTextHeader('List-Unsubscribe-Post', 'List-Unsubscribe=One-Click');

        return $this->mailSender->send($email);
    }
}
