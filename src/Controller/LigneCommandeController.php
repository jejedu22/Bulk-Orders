<?php

namespace App\Controller;

use App\Entity\LigneCommande;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/ligne/commande')]
class LigneCommandeController extends AbstractController
{
    private $entityManager;

    public function __construct(EntityManagerInterface $entityManager)
    {
        $this->entityManager = $entityManager;
    }

    #[Route('/{id}', name: 'ligne_commande_delete', methods: ['DELETE'])]
    public function delete(Request $request, LigneCommande $ligneCommande): Response
    {
        // Seuls le client et les administrateurs peuvent retirer une ligne
        if (!$this->isGranted('ROLE_ADMIN') && $ligneCommande->getCommande()->getUser() !== $this->getUser()) {
            throw $this->createAccessDeniedException();
        }

        if ($this->isCsrfTokenValid('delete'.$ligneCommande->getId(), $request->request->get('_token'))) {


            $poidLigneCommande = 0;
            $poidLigneCommande += $ligneCommande->getProduct()->getConditionnement() * $ligneCommande->getQuantite();
            
            $poidRestant = $ligneCommande->getCommande()->getJourDistrib()->getPoidRestant();
            $poidRestant -= $poidLigneCommande;

            $poidRestant = $ligneCommande->getCommande()->getJourDistrib()->setPoidRestant($poidRestant);

            $entityManager = $this->entityManager;
            $entityManager->remove($ligneCommande);
            $entityManager->flush();
        }

        return $this->redirectToRoute('commande_index');
    }
}
