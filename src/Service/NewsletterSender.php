<?php

namespace App\Service;

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
    private $attachments;
    // Contenu des pièces jointes, lu une seule fois pour tout l'envoi
    private $attachmentFiles = [];

    public function __construct(MailSender $mailSender, Environment $twig, UrlGeneratorInterface $urlGenerator, UriSigner $uriSigner, Mailjet $mailjet, LoggerInterface $logger, ?NewsletterAttachments $attachments = null)
    {
        $this->attachments = $attachments;
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
     * Destination du bouton « Commander » : la page de commande de la vente
     * ciblée si elle est ouverte, sinon la liste des ventes en cours.
     */
    public function orderUrl(Newsletter $newsletter): string
    {
        $jour = $newsletter->getJourDistrib();
        if (null !== $jour && !$jour->getClosed() && $jour->getDate() > new \DateTime('today')) {
            return $this->urlGenerator->generate('passe_commande_new', ['idJourDistrib' => $jour->getId()], UrlGeneratorInterface::ABSOLUTE_URL);
        }

        return $this->urlGenerator->generate('passe_commande_index', [], UrlGeneratorInterface::ABSOLUTE_URL);
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

        try {
            $files = $this->attachmentFiles($newsletter);
        } catch (\RuntimeException $e) {
            return $e->getMessage();
        }

        $unsubscribeUrl = $this->unsubscribeUrl($user);
        $email
            ->to($user->getMail())
            ->subject($newsletter->getSubject())
            ->html($this->twig->render('newsletter/email.html.twig', [
                'newsletter' => $newsletter,
                'content' => $this->absoluteUrls((string) $newsletter->getContent()),
                'user' => $user,
                'unsubscribe_url' => $unsubscribeUrl,
                'order_url' => $newsletter->hasOrderButton() ? $this->orderUrl($newsletter) : null,
                'brand' => $this->mailSender->brand($email),
            ]));
        foreach ($files as [$name, $content, $mimeType]) {
            $email->attach($content, $name, $mimeType);
        }
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

    /**
     * @return array<array{0: string, 1: string, 2: string}> [nom, contenu, type]
     *
     * @throws \RuntimeException si une pièce jointe est introuvable
     */
    private function attachmentFiles(Newsletter $newsletter): array
    {
        if (null === $this->attachments || $newsletter->getAttachments()->isEmpty()) {
            return [];
        }
        $key = spl_object_id($newsletter);
        if (!isset($this->attachmentFiles[$key])) {
            // Un seul envoi à la fois : on ne garde que la dernière newsletter en mémoire
            $this->attachmentFiles = [$key => $this->attachments->load($newsletter)];
        }

        return $this->attachmentFiles[$key];
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
