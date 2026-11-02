<?php

namespace App\Twig;

use App\Entity\Commande;
use App\Service\HelloAsso;
use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

/**
 * helloasso_can_pay(commande) : le bouton « Payer en ligne » est-il proposé ?
 */
class HelloAssoExtension extends AbstractExtension
{
    private $helloAsso;

    public function __construct(HelloAsso $helloAsso)
    {
        $this->helloAsso = $helloAsso;
    }

    public function getFunctions(): array
    {
        return [
            new TwigFunction('helloasso_can_pay', fn (Commande $commande): bool => $this->helloAsso->canPay($commande)),
        ];
    }
}
