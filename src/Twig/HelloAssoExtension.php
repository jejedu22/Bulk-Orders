<?php

namespace App\Twig;

use App\Entity\Commande;
use App\Entity\JourDistrib;
use App\Service\DemandePaiement;
use App\Service\HelloAsso;
use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

/**
 * Paiement des commandes :
 *  - helloasso_configured() : paiement en ligne disponible ;
 *  - helloasso_can_pay(commande) : le bouton « Payer en ligne » est-il proposé ?
 *  - demande_paiement_pending(jour) : demandes de paiement à envoyer ;
 *  - demande_paiement_missing(jour) : commandes dont un prix définitif manque.
 */
class HelloAssoExtension extends AbstractExtension
{
    private $helloAsso;
    private $demandePaiement;

    public function __construct(HelloAsso $helloAsso, DemandePaiement $demandePaiement)
    {
        $this->helloAsso = $helloAsso;
        $this->demandePaiement = $demandePaiement;
    }

    public function getFunctions(): array
    {
        return [
            new TwigFunction('helloasso_configured', fn (): bool => $this->helloAsso->isConfigured()),
            new TwigFunction('helloasso_can_pay', fn (Commande $commande): bool => $this->helloAsso->canPay($commande)),
            new TwigFunction('demande_paiement_pending', fn (JourDistrib $jour): int => \count($this->demandePaiement->pending($jour))),
            new TwigFunction('demande_paiement_missing', fn (JourDistrib $jour): int => $this->demandePaiement->missingFinalPrices($jour)),
        ];
    }
}
