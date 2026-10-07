<?php

namespace App\Controller;

use App\Entity\Commande;
use App\Repository\CommandeRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/commande')]
class CommandeController extends AbstractController
{
    private $entityManager;

    public function __construct(EntityManagerInterface $entityManager)
    {
        $this->entityManager = $entityManager;
    }

    #[Route('/', name: 'commande_index', methods: ['GET'])]
    public function index(CommandeRepository $commandeRepository): Response
    {
        $commande = $commandeRepository->findOneBy(['user' => $this->getUser()],['id' => 'desc']); 
  
        if (isset($commande)){

            $response = $this->render('commande/index.html.twig', [
                'commande' => $commande,
                ]);
            return $response;
        }
        else {
            return $this->redirectToRoute('passe_commande_index');
        }
    }

    #[Route('/{id}', name: 'commande_delete', methods: ['DELETE'])]
    public function delete(Request $request, Commande $commande): Response
    {
        // Seuls le client et les administrateurs peuvent annuler une commande
        if (!$this->isGranted('ROLE_ADMIN') && $commande->getUser() !== $this->getUser()) {
            throw $this->createAccessDeniedException();
        }

        if ($this->isCsrfTokenValid('delete'.$commande->getId(), $request->request->get('_token'))) {
            $poidCommande = 0;
            foreach ($commande->getLigneCommandes() as $ligneCommande ){
                $poidCommande += $ligneCommande->getProduct()->getConditionnement() * $ligneCommande->getQuantite();
            }
            
            $poidRestant = $commande->getJourDistrib()->getPoidRestant();
            $poidRestant -= $poidCommande;

            $poidRestant = $commande->getJourDistrib()->setPoidRestant($poidRestant);

            $entityManager = $this->entityManager;
            $entityManager->remove($commande);
            $entityManager->flush();

        }
        $response = $this->redirectToRoute('passe_commande_index');
        $response->headers->clearCookie('commande');
        return $response;
    }
}
