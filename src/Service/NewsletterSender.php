<?php

namespace App\Service;

use App\Controller\PwaController;
use App\Entity\Newsletter;
use App\Entity\NewsletterDelivery;
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
    private $settings;
    private $logoDirectory;

    public function __construct(MailSender $mailSender, Environment $twig, UrlGeneratorInterface $urlGenerator, UriSigner $uriSigner, Mailjet $mailjet, LoggerInterface $logger, OptionsSettings $settings, string $logoDirectory)
    {
        $this->settings = $settings;
        $this->logoDirectory = $logoDirectory;
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
     * Envoie la newsletter à chacun des destinataires. Retourne un envoi par
     * destinataire (historique), à enregistrer par l'appelant.
     *
     * @param User[] $recipients
     *
     * @return NewsletterDelivery[]
     */
    public function send(Newsletter $newsletter, array $recipients): array
    {
        $transport = $this->transport();
        $deliveries = [];
        foreach ($recipients as $user) {
            $deliveries[] = $this->sendTo($newsletter, $user, $transport);
        }

        return $deliveries;
    }

    public function sendTo(Newsletter $newsletter, User $user, ?TransportInterface $transport = null, bool $test = false): NewsletterDelivery
    {
        return new NewsletterDelivery($newsletter, $user, $this->deliver($newsletter, $user, $transport), $test);
    }

    /**
     * Retourne null si l'e-mail est parti, sinon le message d'erreur.
     */
    private function deliver(Newsletter $newsletter, User $user, ?TransportInterface $transport): ?string
    {
        try {
            $email = $this->mailSender->withSender(new Email(), $this->mailjet->sender());
        } catch (\LogicException $e) {
            return $e->getMessage();
        }

        $unsubscribeUrl = $this->unsubscribeUrl($user);
        // Logo des paramètres joint à l'e-mail (affiché même si la messagerie bloque les images distantes)
        $logoCid = null;
        $logo = $this->logoPath();
        if (null !== $logo) {
            $email->embedFromPath($logo, 'logo');
            $logoCid = 'cid:logo';
        }
        $email
            ->to($user->getMail())
            ->subject($newsletter->getSubject())
            ->html($this->twig->render('newsletter/email.html.twig', [
                'newsletter' => $newsletter,
                'content' => $this->absoluteUrls((string) $newsletter->getContent()),
                'user' => $user,
                'unsubscribe_url' => $unsubscribeUrl,
                'logo_cid' => $logoCid,
                'accent' => PwaController::COLORS[$this->settings->get('color')] ?? PwaController::COLORS['green'],
            ]));
        $email->getHeaders()
            ->addTextHeader('List-Unsubscribe', '<' . $unsubscribeUrl . '>')
            ->addTextHeader('List-Unsubscribe-Post', 'List-Unsubscribe=One-Click');

        $transport = $transport ?? $this->transport();
        if (null === $transport) {
            $email->getHeaders()->addTextHeader('X-Transport', 'newsletter');

            return $this->mailSender->send($email) ? null : 'Échec de l\'envoi (détail dans les journaux de l\'application).';
        }

        try {
            $transport->send($email);

            return null;
        } catch (TransportExceptionInterface $e) {
            $this->logger->error('Échec de l\'envoi de la newsletter « {subject} » à {to} par Mailjet : {error}', [
                'subject' => $newsletter->getSubject(),
                'to' => $user->getMail(),
                'error' => $e->getMessage(),
                'exception' => $e,
            ]);

            return $e->getMessage();
        }
    }

    private function logoPath(): ?string
    {
        $logo = basename($this->settings->get('logo'));
        $path = $this->logoDirectory . '/' . $logo;

        return '' !== $logo && is_file($path) && false !== @getimagesize($path) ? $path : null;
    }

    /**
     * Les images et liens de l'éditeur sont relatifs au site (« /uploads/… ») :
     * dans un e-mail, ils doivent être absolus.
     */
    public function absoluteUrls(string $html): string
    {
        $parts = parse_url($this->urlGenerator->generate('passe_commande_index', [], UrlGeneratorInterface::ABSOLUTE_URL));
        $origin = $parts['scheme'] . '://' . $parts['host'] . (isset($parts['port']) ? ':' . $parts['port'] : '');

        return preg_replace('#\b(src|href)=(["\'])/(?!/)#i', '$1=$2' . $origin . '/', $html);
    }

    /**
     * Transport Mailjet de l'administration, ou null pour le transport « newsletter » du mailer.
     */
    private function transport(): ?TransportInterface
    {
        return $this->mailjet->isConfigured() ? $this->mailjet->transport() : null;
    }
}
