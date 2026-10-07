<?php

namespace App\Controller;

use App\Entity\NewsletterGroup;
use App\Form\NewsletterGroupType;
use App\Repository\NewsletterGroupRepository;
use App\Repository\NewsletterRepository;
use App\Repository\UserRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/newsletter/groupes')]
class NewsletterGroupController extends AbstractController
{
    private $entityManager;

    public function __construct(EntityManagerInterface $entityManager)
    {
        $this->entityManager = $entityManager;
    }

    #[Route('/', name: 'newsletter_group_index', methods: ['GET'])]
    public function index(NewsletterGroupRepository $groupRepository, UserRepository $userRepository): Response
    {
        $groups = $groupRepository->findAllOrdered();
        $subscribers = [];
        foreach ($groups as $group) {
            $subscribers[$group->getId()] = $userRepository->countNewsletterSubscribers([$group]);
        }

        return $this->render('newsletter_group/index.html.twig', [
            'groups' => $groups,
            'subscribers' => $subscribers,
        ]);
    }

    #[Route('/new', name: 'newsletter_group_new', methods: ['GET', 'POST'])]
    public function new(Request $request): Response
    {
        $group = new NewsletterGroup();
        $form = $this->createForm(NewsletterGroupType::class, $group);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $this->entityManager->persist($group);
            $this->entityManager->flush();

            return $this->redirectToRoute('newsletter_group_index');
        }

        return $this->render('newsletter_group/new.html.twig', [
            'group' => $group,
            'form' => $form->createView(),
        ]);
    }

    #[Route('/{id}/edit', name: 'newsletter_group_edit', methods: ['GET', 'POST'])]
    public function edit(Request $request, NewsletterGroup $group): Response
    {
        $form = $this->createForm(NewsletterGroupType::class, $group);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $this->entityManager->flush();

            return $this->redirectToRoute('newsletter_group_index');
        }

        return $this->render('newsletter_group/edit.html.twig', [
            'group' => $group,
            'form' => $form->createView(),
        ]);
    }

    #[Route('/{id}', name: 'newsletter_group_delete', methods: ['DELETE'])]
    public function delete(Request $request, NewsletterGroup $group, NewsletterRepository $newsletterRepository): Response
    {
        if ($this->isCsrfTokenValid('delete'.$group->getId(), $request->request->get('_token'))) {
            // Un brouillon qui ne ciblerait plus aucun groupe partirait à tous les abonnés
            $drafts = $newsletterRepository->findDraftsTargeting($group);
            if ($drafts) {
                $this->addFlash('danger', sprintf(
                    'Ce groupe est destinataire de newsletters non envoyées (%s) : retirez-le de ces newsletters avant de le supprimer.',
                    implode(', ', array_map(function ($newsletter) { return '« ' . htmlspecialchars($newsletter->getSubject()) . ' »'; }, $drafts))
                ));

                return $this->redirectToRoute('newsletter_group_edit', ['id' => $group->getId()]);
            }
            // Les newsletters envoyées perdent ce groupe de leur historique (table de jointure)
            $this->entityManager->remove($group);
            $this->entityManager->flush();
        }

        return $this->redirectToRoute('newsletter_group_index');
    }
}
