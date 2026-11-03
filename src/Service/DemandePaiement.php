<?php

namespace App\Service;

use App\Entity\Commande;
use App\Entity\JourDistrib;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Demande de paiement définitif : une fois les prix définitifs connus, chaque
 * client reçoit par e-mail le montant exact de sa commande, avec un lien de
 * paiement en ligne si HelloAsso est configuré.
 */
class DemandePaiement
{
    private $entityManager;
    private $mailSender;
    private $helloAsso;
    private $translator;

    public function __construct(EntityManagerInterface $entityManager, MailSender $mailSender, HelloAsso $helloAsso, TranslatorInterface $translator)
    {
        $this->entityManager = $entityManager;
        $this->mailSender = $mailSender;
        $this->helloAsso = $helloAsso;
        $this->translator = $translator;
    }

    /**
     * Commandes de la vente à qui la demande peut être envoyée : non payées,
     * prix définitifs connus, demande pas encore envoyée.
     *
     * @return Commande[]
     */
    public function pending(JourDistrib $jourDistrib): array
    {
        return array_values(array_filter($jourDistrib->getCommandes()->toArray(), function (Commande $commande) {
            return !$commande->getPayee() && null === $commande->getPaiementDemandeLe()
                && $commande->hasFinalPrices() && $commande->getTotal() > 0;
        }));
    }

    /**
     * Commandes non payées dont un prix définitif manque encore.
     */
    public function missingFinalPrices(JourDistrib $jourDistrib): int
    {
        return \count(array_filter($jourDistrib->getCommandes()->toArray(), function (Commande $commande) {
            return !$commande->getPayee() && !$commande->getLigneCommandes()->isEmpty() && !$commande->hasFinalPrices();
        }));
    }

    /**
     * Envoie les demandes de paiement de la vente.
     *
     * @return array{sent: int, failed: string[]} nombre d'e-mails envoyés, adresses en échec
     */
    public function send(JourDistrib $jourDistrib): array
    {
        $sent = 0;
        $failed = [];
        foreach ($this->pending($jourDistrib) as $commande) {
            if ($this->mailSender->sendDemandePaiement($commande, $this->translator->trans('email.subject.payment_request'), $this->helloAsso->canPay($commande))) {
                $commande->setPaiementDemandeLe(new \DateTime());
                ++$sent;
            } else {
                $failed[] = (string) $commande->getUser()->getMail();
            }
        }
        $this->entityManager->flush();

        return ['sent' => $sent, 'failed' => $failed];
    }
}
