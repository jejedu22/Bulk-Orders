<?php

namespace App\Service;

use App\Entity\Commande;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Étapes du règlement d'une commande :
 *  1. validation (chèque d'engagement reçu, non encaissé) par un administrateur ;
 *  2. paiement définitif, une fois les prix définitifs connus : chèque
 *     enregistré par un administrateur ou paiement en ligne HelloAsso.
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
     * Valide la commande (chèque d'engagement reçu) et envoie l'e-mail de
     * confirmation au client. Retourne false si l'e-mail n'a pas pu être envoyé.
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
     * Paiement définitif reçu par un administrateur (chèque, espèces…). Une
     * commande payée est aussi validée.
     */
    public function markPaid(Commande $commande): void
    {
        $commande->setPayee(true);
        $commande->setConfirmed(true);
        $this->entityManager->flush();
    }

    /**
     * Paiement en ligne reçu (retour de la page de paiement ou notification
     * HelloAsso, qui peuvent arriver en même temps) : la commande n'est
     * marquée payée, et l'e-mail envoyé, qu'une seule fois.
     *
     * @return bool true si la commande vient d'être marquée payée
     */
    public function confirmPaidOnline(Commande $commande, int $helloassoOrderId): bool
    {
        $updated = $this->entityManager->getConnection()->executeStatement(
            'UPDATE commande SET payee = ?, confirmed = ?, helloasso_order_id = ? WHERE id = ? AND payee = ?',
            [1, 1, $helloassoOrderId, $commande->getId(), 0]
        );
        $this->entityManager->refresh($commande);
        if (1 !== $updated) {
            return false;
        }

        $this->mailSender->sendCommande(
            $commande,
            $this->translator->trans('email.subject.payment_received'),
            sprintf('<p>Nous avons bien reçu votre paiement en ligne de %s. Merci !</p>', number_format($commande->getTotal(), 2, ',', ' ') . ' €'),
            true
        );

        return true;
    }
}
