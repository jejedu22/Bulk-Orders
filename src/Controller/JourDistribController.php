<?php

namespace App\Controller;

use App\Entity\JourDistrib;
use App\Form\JourDistribType;
use App\Repository\NewsletterRepository;
use App\Repository\JourDistribRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/jour/distrib')]
class JourDistribController extends AbstractController
{
    private $entityManager;

    public function __construct(EntityManagerInterface $entityManager)
    {
        $this->entityManager = $entityManager;
    }

    #[Route('/', name: 'jour_distrib_index', methods: ['GET'])]
    public function index(JourDistribRepository $jourDistribRepository): Response
    {
        return $this->render('jour_distrib/index.html.twig', [
            'jour_distribs' => $jourDistribRepository->findBy([], ['date' => 'DESC']),
        ]);
    }

    #[Route('/new', name: 'jour_distrib_new', methods: ['GET', 'POST'])]
    public function new(Request $request): Response
    {
        $jourDistrib = new JourDistrib();
        $form = $this->createForm(JourDistribType::class, $jourDistrib);
        $form->handleRequest($request);
        if ($form->isSubmitted() && $form->isValid()) {

            $entityManager = $this->entityManager;
            $entityManager->persist($jourDistrib);
            $entityManager->flush();

            return $this->redirectToRoute('jour_distrib_index');
        }

        return $this->render('jour_distrib/new.html.twig', [
            'jour_distrib' => $jourDistrib,
            'form' => $form->createView(),
        ]);
    }

    #[Route('/{id}/edit', name: 'jour_distrib_edit', methods: ['GET', 'POST'])]
    public function edit(Request $request, JourDistrib $jourDistrib): Response
    {
        $form = $this->createForm(JourDistribType::class, $jourDistrib, [
            'edit' => true, 
            ]);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $this->entityManager->flush();

            return $this->redirectToRoute('jour_distrib_index');
        }

        return $this->render('jour_distrib/edit.html.twig', [
            'jour_distrib' => $jourDistrib,
            'form' => $form->createView(),
        ]);
    }

    #[Route('/{id}', name: 'jour_distrib_delete', methods: ['DELETE'])]
    public function delete(Request $request, JourDistrib $jourDistrib, NewsletterRepository $newsletterRepository): Response
    {
        if ($this->isCsrfTokenValid('delete'.$jourDistrib->getId(), $request->request->get('_token'))) {
            // Un brouillon réservé aux clients de cette vente partirait sinon à tous les abonnés
            $drafts = $newsletterRepository->findDraftsTargetingSale($jourDistrib);
            if ($drafts) {
                $this->addFlash('danger', sprintf(
                    'Des newsletters non envoyées sont réservées aux clients de cette vente (%s) : modifiez-les avant de supprimer la vente.',
                    implode(', ', array_map(function ($newsletter) { return '« ' . htmlspecialchars($newsletter->getSubject()) . ' »'; }, $drafts))
                ));

                return $this->redirectToRoute('jour_distrib_index');
            }

            $entityManager = $this->entityManager;
            $entityManager->remove($jourDistrib);
            $entityManager->flush();
        }

        return $this->redirectToRoute('jour_distrib_index');
    }
}
