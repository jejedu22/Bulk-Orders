<?php

namespace App\Controller;

use App\Entity\Adhesion;
use App\Entity\User;
use App\Form\AdhesionType;
use App\Form\UserAdminType;
use App\Repository\NewsletterDeliveryRepository;
use App\Repository\UserRepository;
use App\Service\AdhesionPeriode;
use App\Service\UserExport;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Form\FormInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\ResponseHeaderBag;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Adhérents et utilisateurs (administration).
 */
#[Route('/user')]
class UserController extends AbstractController
{
    private $entityManager;
    private $periode;

    public function __construct(EntityManagerInterface $entityManager, AdhesionPeriode $periode)
    {
        $this->entityManager = $entityManager;
        $this->periode = $periode;
    }

    #[Route('/', name: 'user_index', methods: ['GET'])]
    public function index(UserRepository $userRepository): Response
    {
        $users = $userRepository->findAllWithAdhesions();
        [$debut, $fin] = $this->periode->saison();

        $stats = [User::ADHESION_A_JOUR => 0, User::ADHESION_A_VENIR => 0, User::ADHESION_EXPIREE => 0, User::ADHESION_AUCUNE => 0];
        $cotisations = ['count' => 0, 'total' => 0.0];
        foreach ($users as $user) {
            ++$stats[$user->getStatutAdhesion()];
            foreach ($user->getAdhesions() as $adhesion) {
                // Cotisations de la saison : adhésions qui y commencent
                if ($adhesion->getDateDebut() >= $debut && $adhesion->getDateDebut() <= $fin) {
                    ++$cotisations['count'];
                    $cotisations['total'] += (float) $adhesion->getMontant();
                }
            }
        }

        return $this->render('user/index.html.twig', [
            'users' => $users,
            'stats' => $stats,
            'cotisations' => $cotisations,
            'saison' => $this->periode->libelle(),
        ]);
    }

    // Routes fixes avant « /{id} »
    #[Route('/export', name: 'user_export', methods: ['GET'])]
    public function export(UserExport $export): StreamedResponse
    {
        $response = new StreamedResponse(function () use ($export) {
            $export->write('php://output');
        });
        $response->headers->set('Content-Type', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
        $response->headers->set('Content-Disposition', $response->headers->makeDisposition(
            ResponseHeaderBag::DISPOSITION_ATTACHMENT,
            sprintf('utilisateurs-%s.xlsx', (new \DateTimeImmutable())->format('Y-m-d'))
        ));
        $response->headers->set('Cache-Control', 'private, no-store');

        return $response;
    }

    /**
     * Création d'un compte par un administrateur (adhérent qui ne s'inscrit pas
     * lui-même). Mot de passe aléatoire : la personne le choisit avec
     * « Mot de passe oublié ».
     */
    #[Route('/new', name: 'user_new', methods: ['GET', 'POST'])]
    public function new(Request $request, UserPasswordHasherInterface $passwordHasher): Response
    {
        $user = new User();
        $user->setRoles(['ROLE_USER']);
        $password = bin2hex(random_bytes(16));
        $user->setPassword($password);
        $form = $this->createForm(UserAdminType::class, $user, ['adhesion' => true]);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $user->setUsername($user->getMail());
            $user->setPassword($passwordHasher->hashPassword($user, $password));
            $this->entityManager->persist($user);
            if ($form->get('adhesion')->getData()) {
                $user->addAdhesion($this->periode->proposition($user));
            }
            $this->entityManager->flush();
            $this->addFlash('success', sprintf('Compte de %s créé. Pour se connecter, la personne choisit son mot de passe avec « Mot de passe oublié ».', htmlspecialchars($user->getPrenom() . ' ' . $user->getNom())));

            return $this->redirectToRoute('user_show', ['id' => $user->getId()]);
        }

        return $this->render('user/new.html.twig', [
            'form' => $form->createView(),
            'saison' => $this->periode->saison(),
        ]);
    }

    #[Route('/{id}', name: 'user_show', methods: ['GET'], requirements: ['id' => '\d+'])]
    public function show(User $user, NewsletterDeliveryRepository $deliveryRepository): Response
    {
        return $this->renderShow($user, $deliveryRepository, $this->adhesionForm($this->periode->proposition($user)));
    }

    #[Route('/{id}/adhesion', name: 'user_adhesion_new', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function newAdhesion(Request $request, User $user, NewsletterDeliveryRepository $deliveryRepository): Response
    {
        $adhesion = new Adhesion($user);
        $form = $this->adhesionForm($adhesion);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $user->addAdhesion($adhesion);
            $this->entityManager->persist($adhesion);
            $this->entityManager->flush();
            $this->addFlash('success', sprintf('Adhésion enregistrée du %s au %s.', $adhesion->getDateDebut()->format('d/m/Y'), $adhesion->getDateFin()->format('d/m/Y')));

            return $this->redirectToRoute('user_show', ['id' => $user->getId()]);
        }

        return $this->renderShow($user, $deliveryRepository, $form, Response::HTTP_UNPROCESSABLE_ENTITY);
    }

    #[Route('/adhesion/{id}/edit', name: 'user_adhesion_edit', methods: ['GET', 'POST'], requirements: ['id' => '\d+'])]
    public function editAdhesion(Request $request, Adhesion $adhesion): Response
    {
        $form = $this->createForm(AdhesionType::class, $adhesion);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $this->entityManager->flush();
            $this->addFlash('success', 'Adhésion modifiée.');

            return $this->redirectToRoute('user_show', ['id' => $adhesion->getUser()->getId()]);
        }

        return $this->render('user/adhesion_edit.html.twig', [
            'adhesion' => $adhesion,
            'user' => $adhesion->getUser(),
            'form' => $form->createView(),
        ]);
    }

    #[Route('/adhesion/{id}', name: 'user_adhesion_delete', methods: ['DELETE'], requirements: ['id' => '\d+'])]
    public function deleteAdhesion(Request $request, Adhesion $adhesion): Response
    {
        $user = $adhesion->getUser();
        if ($this->isCsrfTokenValid('delete_adhesion'.$adhesion->getId(), $request->request->get('_token'))) {
            $user->removeAdhesion($adhesion);
            $this->entityManager->flush();
            $this->addFlash('success', 'Adhésion supprimée.');
        }

        return $this->redirectToRoute('user_show', ['id' => $user->getId()]);
    }

    #[Route('/{id}/edit', name: 'user_edit', methods: ['GET', 'POST'], requirements: ['id' => '\d+'])]
    public function edit(Request $request, User $user): Response
    {
        $form = $this->createForm(UserAdminType::class, $user);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $this->entityManager->flush();

            return $this->redirectToRoute('user_show', ['id' => $user->getId()]);
        }

        return $this->render('user/edit.html.twig', [
            'user' => $user,
            'form' => $form->createView(),
        ]);
    }

    #[Route('/{id}', name: 'user_delete', methods: ['DELETE'], requirements: ['id' => '\d+'])]
    public function delete(Request $request, User $user, TranslatorInterface $translator): Response
    {
        if ($this->isCsrfTokenValid('delete'.$user->getId(), $request->request->get('_token'))) {
            if ($user->getUserIdentifier() != $this->getUser()->getUserIdentifier() && $user->getId() != 1) {
                $this->entityManager->remove($user);
                $this->entityManager->flush();
                $this->addFlash('success', $translator->trans('alert_message.delete_user'));
            } elseif ($user->getId() == 1) {
                $this->addFlash('danger', $translator->trans('alert_message.protected_user'));
            } else {
                $this->addFlash('danger', $translator->trans('alert_message.connected_user'));
            }
        }

        return $this->redirectToRoute('user_index');
    }

    private function adhesionForm(Adhesion $adhesion): FormInterface
    {
        return $this->createForm(AdhesionType::class, $adhesion, [
            'action' => $this->generateUrl('user_adhesion_new', ['id' => $adhesion->getUser()->getId()]),
        ]);
    }

    private function renderShow(User $user, NewsletterDeliveryRepository $deliveryRepository, FormInterface $adhesionForm, int $status = Response::HTTP_OK): Response
    {
        return $this->render('user/show.html.twig', [
            'user' => $user,
            'deliveries' => $deliveryRepository->findForUser($user),
            'adhesion_form' => $adhesionForm->createView(),
            'adhesion_open' => !$adhesionForm->isSubmitted() ? false : true,
        ], new Response(null, $status));
    }
}
