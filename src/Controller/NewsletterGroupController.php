<?php

namespace App\Controller;

use App\Entity\JourDistrib;
use App\Entity\NewsletterGroup;
use App\Entity\User;
use App\Form\NewsletterGroupType;
use App\Repository\NewsletterGroupRepository;
use App\Repository\NewsletterRepository;
use App\Repository\UserRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Groupes de newsletter. Les membres se gèrent depuis la page du groupe :
 * liste filtrable avec retrait en masse, ajout individuel, ajout en masse
 * (adhérents à jour, abonnés, clients d'une vente, autre groupe) et ajout
 * par une liste d'adresses e-mail collée.
 */
#[Route('/newsletter/groupes')]
class NewsletterGroupController extends AbstractController
{
    private const SALES_LIMIT = 30;

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
            'members' => $groupRepository->countMembers(),
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
            $this->addFlash('success', 'Groupe créé : ajoutez-lui des membres.');

            return $this->redirectToRoute('newsletter_group_show', ['id' => $group->getId()]);
        }

        return $this->render('newsletter_group/new.html.twig', [
            'group' => $group,
            'form' => $form->createView(),
        ]);
    }

    #[Route('/{id}', name: 'newsletter_group_show', methods: ['GET'], requirements: ['id' => '\d+'])]
    public function show(NewsletterGroup $group, UserRepository $userRepository, NewsletterGroupRepository $groupRepository): Response
    {
        $sales = $this->entityManager->getRepository(JourDistrib::class)->findBy([], ['date' => 'DESC'], self::SALES_LIMIT);

        return $this->render('newsletter_group/show.html.twig', [
            'group' => $group,
            'members' => $userRepository->findGroupMembers($group),
            'candidates' => $userRepository->findNotInGroup($group),
            'subscribers' => $userRepository->countNewsletterSubscribers([$group]),
            'other_groups' => array_filter($groupRepository->findAllOrdered(), function (NewsletterGroup $g) use ($group) { return $g !== $group; }),
            'sales' => $sales,
        ]);
    }

    #[Route('/{id}/edit', name: 'newsletter_group_edit', methods: ['GET', 'POST'], requirements: ['id' => '\d+'])]
    public function edit(Request $request, NewsletterGroup $group): Response
    {
        $form = $this->createForm(NewsletterGroupType::class, $group);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $this->entityManager->flush();

            return $this->redirectToRoute('newsletter_group_show', ['id' => $group->getId()]);
        }

        return $this->render('newsletter_group/edit.html.twig', [
            'group' => $group,
            'form' => $form->createView(),
        ]);
    }

    #[Route('/{id}/membres/ajouter', name: 'newsletter_group_add_members', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function addMembers(Request $request, NewsletterGroup $group, UserRepository $userRepository, NewsletterGroupRepository $groupRepository): Response
    {
        if (!$this->isCsrfTokenValid('members'.$group->getId(), $request->request->get('_token'))) {
            return $this->redirectToRoute('newsletter_group_show', ['id' => $group->getId()]);
        }

        $users = [];
        // Sélection individuelle
        $ids = array_filter(array_map('intval', $request->request->all('users')));
        if ($ids) {
            $users = array_merge($users, $userRepository->findBy(['id' => $ids]));
        }

        // Ajout en masse
        $source = (string) $request->request->get('source', '');
        if ('adherents' === $source) {
            $users = array_merge($users, $userRepository->findAdherentsAJour());
        } elseif ('abonnes' === $source) {
            $users = array_merge($users, $userRepository->findNewsletterSubscribers());
        } elseif ('tous' === $source) {
            $users = array_merge($users, $userRepository->findAll());
        } elseif (preg_match('/^vente-(\d+)$/', $source, $m) && ($jour = $this->entityManager->find(JourDistrib::class, (int) $m[1]))) {
            $users = array_merge($users, $userRepository->findCustomers($jour));
        } elseif (preg_match('/^groupe-(\d+)$/', $source, $m) && ($other = $groupRepository->find((int) $m[1]))) {
            $users = array_merge($users, $other->getUsers()->toArray());
        }

        // Adresses e-mail collées (séparées par des espaces, virgules, points-virgules, retours à la ligne…)
        $unknown = [];
        preg_match_all('/[^\s,;<>"\'()\[\]]+@[^\s,;<>"\'()\[\]]+/u', (string) $request->request->get('emails', ''), $matches);
        $mails = array_values(array_unique(array_map('mb_strtolower', $matches[0])));
        if ($mails) {
            $found = $userRepository->findByMails($mails);
            $users = array_merge($users, $found);
            $known = array_map(function (User $user) { return mb_strtolower($user->getMail()); }, $found);
            $unknown = array_values(array_diff($mails, $known));
        }

        // Côté propriétaire seulement : les groupes de chaque utilisateur ne sont pas chargés
        $added = 0;
        foreach ($users as $user) {
            if (!$group->getUsers()->contains($user)) {
                $group->addUser($user);
                ++$added;
            }
        }
        $this->entityManager->flush();

        if ($added > 0) {
            $this->addFlash('success', sprintf('%d membre%s ajouté%s au groupe.', $added, $added > 1 ? 's' : '', $added > 1 ? 's' : ''));
        } elseif (!$unknown) {
            $this->addFlash('info', $users ? 'Ces personnes sont déjà membres du groupe.' : 'Aucun membre à ajouter.');
        }
        if ($unknown) {
            $this->addFlash('warning', sprintf(
                'Aucun compte pour %s : %s.',
                \count($unknown) > 1 ? 'ces adresses' : 'cette adresse',
                htmlspecialchars(implode(', ', $unknown))
            ));
        }

        return $this->redirectToRoute('newsletter_group_show', ['id' => $group->getId()]);
    }

    #[Route('/{id}/membres/retirer', name: 'newsletter_group_remove_members', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function removeMembers(Request $request, NewsletterGroup $group): Response
    {
        if (!$this->isCsrfTokenValid('members'.$group->getId(), $request->request->get('_token'))) {
            return $this->redirectToRoute('newsletter_group_show', ['id' => $group->getId()]);
        }

        $ids = array_flip(array_map('intval', $request->request->all('users')));
        $removed = 0;
        foreach ($group->getUsers()->toArray() as $user) {
            if (isset($ids[$user->getId()])) {
                $group->removeUser($user);
                ++$removed;
            }
        }
        $this->entityManager->flush();

        if ($removed > 0) {
            $this->addFlash('success', sprintf('%d membre%s retiré%s du groupe.', $removed, $removed > 1 ? 's' : '', $removed > 1 ? 's' : ''));
        }

        return $this->redirectToRoute('newsletter_group_show', ['id' => $group->getId()]);
    }

    #[Route('/{id}', name: 'newsletter_group_delete', methods: ['DELETE'], requirements: ['id' => '\d+'])]
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

                return $this->redirectToRoute('newsletter_group_show', ['id' => $group->getId()]);
            }
            // Les newsletters envoyées perdent ce groupe de leur historique (table de jointure)
            $this->entityManager->remove($group);
            $this->entityManager->flush();
        }

        return $this->redirectToRoute('newsletter_group_index');
    }
}
