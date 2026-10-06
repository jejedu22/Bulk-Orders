<?php

namespace App\Controller;

use App\Entity\LigneCommande;
use App\Form\LigneCommandeType;
use App\Repository\LigneCommandeRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;

/**
 * @Route("/ligne/commande")
 */
class LigneCommandeController extends AbstractController
{
    private $entityManager;

    public function __construct(EntityManagerInterface $entityManager)
    {
        $this->entityManager = $entityManager;
    }

    /**
     * @Route("/", name="ligne_commande_index", methods={"GET"})
     */
    public function index(LigneCommandeRepository $ligneCommandeRepository): Response
    {
        return $this->render('ligne_commande/index.html.twig', [
            'ligne_commandes' => $ligneCommandeRepository->findAll(),
        ]);
    }

    /**
     * @Route("/new", name="ligne_commande_new", methods={"GET","POST"})
     */
    public function new(Request $request): Response
    {
        $ligneCommande = new LigneCommande();
        $form = $this->createForm(LigneCommandeType::class, $ligneCommande);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $entityManager = $this->entityManager;
            $entityManager->persist($ligneCommande);
            $entityManager->flush();

            return $this->redirectToRoute('ligne_commande_index');
        }

        return $this->render('ligne_commande/new.html.twig', [
            'ligne_commande' => $ligneCommande,
            'form' => $form->createView(),
        ]);
    }

    /**
     * @Route("/{id}/edit", name="ligne_commande_edit", methods={"GET","POST"})
     */
    public function edit(Request $request, LigneCommande $ligneCommande): Response
    {
        $form = $this->createForm(LigneCommandeType::class, $ligneCommande);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $this->entityManager->flush();

            return $this->redirectToRoute('ligne_commande_index');
        }

        return $this->render('ligne_commande/edit.html.twig', [
            'ligne_commande' => $ligneCommande,
            'form' => $form->createView(),
        ]);
    }

    /**
     * @Route("/{id}", name="ligne_commande_delete", methods={"DELETE"})
     */
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
