<?php

namespace App\Controller;

use App\Entity\Newsletter;
use App\Entity\User;
use App\Form\NewsletterType;
use App\Repository\NewsletterRepository;
use App\Repository\UserRepository;
use App\Service\Mailjet;
use App\Service\NewsletterSender;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\UriSigner;
use Symfony\Component\Routing\Attribute\Route;

class NewsletterController extends AbstractController
{
    private $entityManager;

    public function __construct(EntityManagerInterface $entityManager)
    {
        $this->entityManager = $entityManager;
    }

    #[Route('/newsletter/', name: 'newsletter_index', methods: ['GET'])]
    public function index(NewsletterRepository $newsletterRepository, UserRepository $userRepository, Mailjet $mailjet): Response
    {
        return $this->render('newsletter/index.html.twig', [
            'newsletters' => $newsletterRepository->findAllForIndex(),
            'subscribers' => $userRepository->countNewsletterSubscribers(),
            'mailjet_configured' => $mailjet->isConfigured(),
        ]);
    }

    #[Route('/newsletter/new', name: 'newsletter_new', methods: ['GET', 'POST'])]
    public function new(Request $request): Response
    {
        $newsletter = new Newsletter();
        $form = $this->createForm(NewsletterType::class, $newsletter);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $this->entityManager->persist($newsletter);
            $this->entityManager->flush();

            return $this->redirectToRoute('newsletter_show', ['id' => $newsletter->getId()]);
        }

        return $this->render('newsletter/new.html.twig', [
            'newsletter' => $newsletter,
            'form' => $form->createView(),
        ]);
    }

    #[Route('/newsletter/{id}', name: 'newsletter_show', methods: ['GET'], requirements: ['id' => '\d+'])]
    public function show(Newsletter $newsletter, UserRepository $userRepository): Response
    {
        return $this->render('newsletter/show.html.twig', [
            'newsletter' => $newsletter,
            'subscribers' => $userRepository->countNewsletterSubscribers(),
        ]);
    }

    #[Route('/newsletter/{id}/edit', name: 'newsletter_edit', methods: ['GET', 'POST'], requirements: ['id' => '\d+'])]
    public function edit(Request $request, Newsletter $newsletter): Response
    {
        if ($newsletter->isSent()) {
            $this->addFlash('warning', 'Cette newsletter a déjà été envoyée, elle ne peut plus être modifiée.');

            return $this->redirectToRoute('newsletter_show', ['id' => $newsletter->getId()]);
        }

        $form = $this->createForm(NewsletterType::class, $newsletter);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $this->entityManager->flush();

            return $this->redirectToRoute('newsletter_show', ['id' => $newsletter->getId()]);
        }

        return $this->render('newsletter/edit.html.twig', [
            'newsletter' => $newsletter,
            'form' => $form->createView(),
        ]);
    }

    #[Route('/newsletter/{id}/test', name: 'newsletter_test', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function test(Request $request, Newsletter $newsletter, NewsletterSender $sender): Response
    {
        if ($this->isCsrfTokenValid('test'.$newsletter->getId(), $request->request->get('_token'))) {
            /** @var User $user */
            $user = $this->getUser();
            if ($sender->sendTo($newsletter, $user)) {
                $this->addFlash('success', 'E-mail de test envoyé à ' . htmlspecialchars($user->getMail()) . '.');
            } else {
                $this->addFlash('danger', 'L\'e-mail de test n\'a pas pu être envoyé. Vérifiez la configuration Mailjet (Paramètres → Mailjet).');
            }
        }

        return $this->redirectToRoute('newsletter_show', ['id' => $newsletter->getId()]);
    }

    #[Route('/newsletter/{id}/send', name: 'newsletter_send', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function send(Request $request, Newsletter $newsletter, NewsletterSender $sender, UserRepository $userRepository): Response
    {
        if (!$this->isCsrfTokenValid('send'.$newsletter->getId(), $request->request->get('_token'))) {
            return $this->redirectToRoute('newsletter_show', ['id' => $newsletter->getId()]);
        }
        if ($newsletter->isSent()) {
            $this->addFlash('warning', 'Cette newsletter a déjà été envoyée.');

            return $this->redirectToRoute('newsletter_show', ['id' => $newsletter->getId()]);
        }

        $recipients = $userRepository->findNewsletterSubscribers();
        if (!$recipients) {
            $this->addFlash('warning', 'Aucun utilisateur n\'est abonné à la newsletter.');

            return $this->redirectToRoute('newsletter_show', ['id' => $newsletter->getId()]);
        }

        // Un e-mail par abonné : la durée dépend du nombre d'abonnés
        set_time_limit(0);
        $failed = $sender->send($newsletter, $recipients);
        $newsletter->markSent(\count($recipients), $failed);
        $this->entityManager->flush();

        $sent = \count($recipients) - $failed;
        if ($failed > 0) {
            $this->addFlash('warning', sprintf('Newsletter envoyée à %d abonné(s) ; %d envoi(s) en échec (voir les journaux).', $sent, $failed));
        } else {
            $this->addFlash('success', sprintf('Newsletter envoyée à %d abonné(s).', $sent));
        }

        return $this->redirectToRoute('newsletter_show', ['id' => $newsletter->getId()]);
    }

    #[Route('/newsletter/{id}', name: 'newsletter_delete', methods: ['DELETE'], requirements: ['id' => '\d+'])]
    public function delete(Request $request, Newsletter $newsletter): Response
    {
        if ($this->isCsrfTokenValid('delete'.$newsletter->getId(), $request->request->get('_token'))) {
            $this->entityManager->remove($newsletter);
            $this->entityManager->flush();
        }

        return $this->redirectToRoute('newsletter_index');
    }

    /**
     * Désinscription par le lien signé des e-mails (sans connexion).
     * GET affiche une confirmation : les messageries qui pré-chargent les liens
     * ne désinscrivent donc personne. POST désinscrit, y compris le
     * « List-Unsubscribe=One-Click » envoyé par les messageries (RFC 8058).
     */
    #[Route('/desinscription/{id}', name: 'newsletter_unsubscribe', methods: ['GET', 'POST'], requirements: ['id' => '\d+'])]
    public function unsubscribe(Request $request, User $user, UriSigner $uriSigner): Response
    {
        if (!$uriSigner->checkRequest($request)) {
            throw $this->createNotFoundException('Lien de désinscription invalide.');
        }

        $done = null;
        if ($request->isMethod('POST')) {
            $subscribe = 'subscribe' === $request->request->get('action');
            $user->setNewsletter($subscribe);
            $this->entityManager->flush();
            $done = $subscribe ? 'subscribed' : 'unsubscribed';
        }

        return $this->render('newsletter/unsubscribe.html.twig', [
            'user' => $user,
            'done' => $done,
            'action_url' => $request->getUri(),
        ]);
    }
}
