<?php

namespace App\Service;

use App\Entity\Commande;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Réception du paiement d'une commande : par un administrateur
 * (« Paiement reçu ») ou en ligne par HelloAsso.
 */
class CommandeConfirmation
{
    private $entityManager;
    private $mailSender;
    private $translator;
    private $settings;

    public function __construct(EntityManagerInterface $entityManager, MailSender $mailSender, TranslatorInterface $translator, OptionsSettings $settings)
    {
        $this->entityManager = $entityManager;
        $this->mailSender = $mailSender;
        $this->translator = $translator;
        $this->settings = $settings;
    }

    /**
     * Marque la commande comme payée et envoie l'e-mail de confirmation au
     * client. Retourne false si l'e-mail n'a pas pu être envoyé.
     */
    public function confirm(Commande $commande): bool
    {
        $commande->setConfirmed(true);
        $this->entityManager->flush();

        return $this->mailSender->sendCommande(
            $commande,
            $this->translator->trans('email.subject.confirm_command'),
            $this->settings->get('text_confirm_email')
        );
    }

    /**
     * Paiement en ligne reçu (retour de la page de paiement ou notification
     * HelloAsso, qui peuvent arriver en même temps) : la commande n'est
     * confirmée, et l'e-mail envoyé, qu'une seule fois.
     *
     * @return bool true si la commande vient d'être confirmée
     */
    public function confirmPaidOnline(Commande $commande, int $helloassoOrderId): bool
    {
        $updated = $this->entityManager->getConnection()->executeStatement(
            'UPDATE commande SET confirmed = ?, helloasso_order_id = ? WHERE id = ? AND confirmed = ?',
            [1, $helloassoOrderId, $commande->getId(), 0]
        );
        $this->entityManager->refresh($commande);
        if (1 !== $updated) {
            return false;
        }

        $this->mailSender->sendCommande(
            $commande,
            $this->translator->trans('email.subject.confirm_command'),
            $this->settings->get('text_confirm_email')
        );

        return true;
    }
}
