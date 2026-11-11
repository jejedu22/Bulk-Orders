<?php

namespace App\Controller;

use App\Entity\Commande;
use App\Entity\CreneauRetrait;
use App\Entity\InscriptionBenevole;
use App\Entity\JourDistrib;
use App\Entity\Newsletter;
use App\Entity\PosteBenevole;
use App\Entity\User;
use App\Form\CreneauRetraitType;
use App\Form\GenerateurCreneauxType;
use App\Form\PosteBenevoleType;
use App\Repository\JourDistribRepository;
use App\Repository\UserRepository;
use App\Service\Distribution;
use App\Service\RappelsDistribution;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Form\FormInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/**
 * Organisation de la distribution d'une vente (administrateurs) : créneaux de
 * retrait, placement des clients, postes de bénévoles, rappels.
 */
#[Route('/jour/distrib')]
class DistributionController extends AbstractController
{
    private $entityManager;
    private $distribution;

    public function __construct(EntityManagerInterface $entityManager, Distribution $distribution)
    {
        $this->entityManager = $entityManager;
        $this->distribution = $distribution;
    }

    #[Route('/{id}/distribution', name: 'jour_distrib_distribution', methods: ['GET'], requirements: ['id' => '\d+'])]
    public function show(JourDistrib $jour, JourDistribRepository $jourRepository, UserRepository $userRepository, RappelsDistribution $rappels): Response
    {
        $derniere = $jour->getCreneaux()->last();
        $generateur = $this->createForm(GenerateurCreneauxType::class, [
            'de' => $derniere ? $derniere->getFin()->format('H:i') : '09:00',
            'a' => $derniere ? $derniere->getFin()->modify('+1 hour')->format('H:i') : '12:00',
            'duree' => 15,
            'places' => $derniere ? $derniere->getPlaces() : 4,
        ], ['action' => $this->generateUrl('distribution_creneaux_generer', ['id' => $jour->getId()])]);
        $poste = $this->createForm(PosteBenevoleType::class, ['debut' => '09:00', 'fin' => '12:00', 'besoin' => 2], [
            'action' => $this->generateUrl('distribution_poste_new', ['id' => $jour->getId()]),
        ]);

        // Ventes dont le planning peut être repris
        $modeles = array_values(array_filter($jourRepository->findBy([], ['date' => 'DESC'], 30), function (JourDistrib $j) use ($jour) {
            return $j !== $jour && ($j->hasCreneaux() || !$j->getPostes()->isEmpty());
        }));

        $commandes = array_values(array_filter($jour->getCommandes()->toArray(), function (Commande $c) { return $c->getConfirmed(); }));
        usort($commandes, function (Commande $a, Commande $b) {
            return strcasecmp($a->getUser()->getNom() . ' ' . $a->getUser()->getPrenom(), $b->getUser()->getNom() . ' ' . $b->getUser()->getPrenom());
        });

        return $this->render('distribution/show.html.twig', [
            'jour' => $jour,
            'generateur' => $generateur->createView(),
            'poste_form' => $poste->createView(),
            'modeles' => $modeles,
            'commandes' => $commandes,
            'en_attente' => \count(array_filter($jour->getCommandes()->toArray(), function (Commande $c) { return !$c->getConfirmed(); })),
            'sans_creneau' => \count($rappels->sansCreneau($jour)),
            'rappels' => $rappels->aEnvoyer($jour),
            'limite' => $this->distribution->dateLimite($jour),
            'users' => $userRepository->findBy([], ['nom' => 'ASC', 'prenom' => 'ASC']),
        ]);
    }

    #[Route('/{id}/creneaux/generer', name: 'distribution_creneaux_generer', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function generer(Request $request, JourDistrib $jour): Response
    {
        $form = $this->createForm(GenerateurCreneauxType::class);
        $form->handleRequest($request);
        if ($form->isSubmitted() && $form->isValid()) {
            $data = $form->getData();
            $crees = $this->distribution->generer($jour, $data['de'], $data['a'], (int) $data['duree'], (int) $data['places']);
            if ($crees > 0) {
                $this->addFlash('success', sprintf('%d créneau%s créé%s.', $crees, $crees > 1 ? 'x' : '', $crees > 1 ? 's' : ''));
            } else {
                $this->addFlash('warning', 'Aucun créneau créé : la plage est trop courte, ou ces créneaux existent déjà.');
            }
        } else {
            $this->flashErrors($form);
        }

        return $this->redirectToShow($jour, 'creneaux');
    }

    #[Route('/{id}/copier', name: 'distribution_copier', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function copier(Request $request, JourDistrib $jour, JourDistribRepository $jourRepository): Response
    {
        if ($this->isTokenValid($request, $jour) && ($source = $jourRepository->find((int) $request->request->get('source'))) && $source !== $jour) {
            $copie = $this->distribution->copier($source, $jour);
            $this->addFlash('success', sprintf('Planning de la vente du %s repris : %d créneau(x), %d poste(s) de bénévole.', $source->getDate()->format('d/m/Y'), $copie['creneaux'], $copie['postes']));
        }

        return $this->redirectToShow($jour);
    }

    #[Route('/creneau/{id}/edit', name: 'distribution_creneau_edit', methods: ['GET', 'POST'], requirements: ['id' => '\d+'])]
    public function editCreneau(Request $request, CreneauRetrait $creneau): Response
    {
        $form = $this->createForm(CreneauRetraitType::class, [
            'debut' => $creneau->getDebut()->format('H:i'),
            'fin' => $creneau->getFin()->format('H:i'),
            'places' => $creneau->getPlaces(),
        ]);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $data = $form->getData();
            $jour = $creneau->getJourDistrib();
            $creneau->setDebut($this->distribution->heure($jour, $data['debut']))
                ->setFin($this->distribution->heure($jour, $data['fin']))
                ->setPlaces((int) $data['places']);
            $this->entityManager->flush();
            if ($creneau->getReservees() > $creneau->getPlaces()) {
                $this->addFlash('warning', sprintf('Le créneau %s compte %d réservations pour %d places : les clients déjà inscrits sont gardés.', $creneau->getLibelle(), $creneau->getReservees(), $creneau->getPlaces()));
            } else {
                $this->addFlash('success', sprintf('Créneau %s modifié.', $creneau->getLibelle()));
            }

            return $this->redirectToShow($jour, 'creneaux');
        }

        return $this->render('distribution/creneau_edit.html.twig', [
            'creneau' => $creneau,
            'jour' => $creneau->getJourDistrib(),
            'form' => $form->createView(),
        ]);
    }

    #[Route('/creneau/{id}/supprimer', name: 'distribution_creneau_delete', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function deleteCreneau(Request $request, CreneauRetrait $creneau): Response
    {
        $jour = $creneau->getJourDistrib();
        if ($this->isTokenValid($request, $jour)) {
            $clients = $creneau->getReservees();
            foreach ($creneau->getCommandes()->toArray() as $commande) {
                $commande->setCreneau(null);
            }
            $jour->removeCreneau($creneau);
            $this->entityManager->flush();
            $this->addFlash('success', sprintf('Créneau %s supprimé.', $creneau->getLibelle())
                . ($clients > 0 ? sprintf(' %d client(s) doivent choisir un autre créneau.', $clients) : ''));
        }

        return $this->redirectToShow($jour, 'creneaux');
    }

    /**
     * Placement d'un client par un administrateur, y compris sur un créneau
     * complet ou après la date limite.
     */
    #[Route('/commande/{id}/placer', name: 'distribution_commande_placer', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function placer(Request $request, Commande $commande): Response
    {
        $jour = $commande->getJourDistrib();
        if ($this->isTokenValid($request, $jour)) {
            $id = (int) $request->request->get('creneau');
            if (0 === $id) {
                $this->distribution->liberer($commande);
            } else {
                $creneau = $this->entityManager->find(CreneauRetrait::class, $id);
                if (null === $creneau || $creneau->getJourDistrib() !== $jour) {
                    throw $this->createNotFoundException('Créneau introuvable.');
                }
                $this->distribution->choisir($commande, $creneau, true);
            }
            $this->addFlash('success', sprintf('%s %s : %s.', htmlspecialchars($commande->getUser()->getPrenom()), htmlspecialchars($commande->getUser()->getNom()),
                $commande->getCreneau() ? 'créneau ' . $commande->getCreneau()->getLibelle() : 'sans créneau'));
        }

        return $this->redirectToShow($jour, 'commandes');
    }

    #[Route('/{id}/postes', name: 'distribution_poste_new', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function newPoste(Request $request, JourDistrib $jour): Response
    {
        $form = $this->createForm(PosteBenevoleType::class);
        $form->handleRequest($request);
        if ($form->isSubmitted() && $form->isValid()) {
            $poste = new PosteBenevole($jour);
            $this->applyPoste($poste, $form->getData());
            $this->entityManager->persist($poste);
            $this->entityManager->flush();
            $this->addFlash('success', sprintf('Poste « %s » ajouté.', htmlspecialchars($poste->getIntitule())));
        } else {
            $this->flashErrors($form);
        }

        return $this->redirectToShow($jour, 'benevoles');
    }

    #[Route('/poste/{id}/edit', name: 'distribution_poste_edit', methods: ['GET', 'POST'], requirements: ['id' => '\d+'])]
    public function editPoste(Request $request, PosteBenevole $poste): Response
    {
        $form = $this->createForm(PosteBenevoleType::class, [
            'intitule' => $poste->getIntitule(),
            'description' => $poste->getDescription(),
            'debut' => $poste->getDebut()->format('H:i'),
            'fin' => $poste->getFin()->format('H:i'),
            'besoin' => $poste->getBesoin(),
        ]);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $this->applyPoste($poste, $form->getData());
            $this->entityManager->flush();
            $this->addFlash('success', sprintf('Poste « %s » modifié.', htmlspecialchars($poste->getIntitule())));

            return $this->redirectToShow($poste->getJourDistrib(), 'benevoles');
        }

        return $this->render('distribution/poste_edit.html.twig', [
            'poste' => $poste,
            'jour' => $poste->getJourDistrib(),
            'form' => $form->createView(),
        ]);
    }

    #[Route('/poste/{id}/supprimer', name: 'distribution_poste_delete', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function deletePoste(Request $request, PosteBenevole $poste): Response
    {
        $jour = $poste->getJourDistrib();
        if ($this->isTokenValid($request, $jour)) {
            $jour->removePoste($poste);
            $this->entityManager->flush();
            $this->addFlash('success', sprintf('Poste « %s » supprimé.', htmlspecialchars($poste->getIntitule())));
        }

        return $this->redirectToShow($jour, 'benevoles');
    }

    /** Ajout d'un bénévole par un administrateur (inscrit par téléphone…). */
    #[Route('/poste/{id}/ajouter', name: 'distribution_poste_ajouter', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function ajouterBenevole(Request $request, PosteBenevole $poste): Response
    {
        $jour = $poste->getJourDistrib();
        if ($this->isTokenValid($request, $jour) && ($user = $this->entityManager->find(User::class, (int) $request->request->get('user')))) {
            $this->distribution->inscrire($poste, $user, true);
            $this->addFlash('success', sprintf('%s %s inscrit·e sur « %s ».', htmlspecialchars($user->getPrenom()), htmlspecialchars($user->getNom()), htmlspecialchars($poste->getIntitule())));
        }

        return $this->redirectToShow($jour, 'benevoles');
    }

    #[Route('/inscription/{id}/retirer', name: 'distribution_inscription_delete', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function retirerBenevole(Request $request, InscriptionBenevole $inscription): Response
    {
        $jour = $inscription->getPoste()->getJourDistrib();
        if ($this->isTokenValid($request, $jour)) {
            $this->distribution->desinscrire($inscription);
            $this->addFlash('success', 'Bénévole retiré·e du poste.');
        }

        return $this->redirectToShow($jour, 'benevoles');
    }

    #[Route('/{id}/rappels', name: 'distribution_rappels', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function rappels(Request $request, JourDistrib $jour, RappelsDistribution $rappels): Response
    {
        if ($this->isTokenValid($request, $jour)) {
            $this->flashEnvoi($rappels->envoyer($jour), 'Rappel envoyé à %d personne(s).', 'Aucun rappel à envoyer : tout le monde l\'a déjà reçu.');
        }

        return $this->redirectToShow($jour);
    }

    #[Route('/{id}/relance', name: 'distribution_relance', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function relance(Request $request, JourDistrib $jour, RappelsDistribution $rappels): Response
    {
        if ($this->isTokenValid($request, $jour)) {
            $this->flashEnvoi($rappels->relancerSansCreneau($jour), 'Relance envoyée à %d client(s) sans créneau.', 'Tous les clients ont choisi leur créneau.');
        }

        return $this->redirectToShow($jour, 'commandes');
    }

    /**
     * Prépare un brouillon de newsletter listant les postes à pourvoir, à
     * relire et à envoyer depuis Newsletters.
     */
    #[Route('/{id}/appel-benevoles', name: 'distribution_appel', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function appel(Request $request, JourDistrib $jour): Response
    {
        if (!$this->isTokenValid($request, $jour)) {
            return $this->redirectToShow($jour);
        }

        $date = (new \IntlDateFormatter('fr_FR', \IntlDateFormatter::FULL, \IntlDateFormatter::NONE, null, null, 'EEEE d MMMM'))->format($jour->getDateDistribution());
        $postes = '';
        foreach ($jour->getPostes() as $poste) {
            if ($poste->getManquants() > 0) {
                $postes .= sprintf('<li><strong>%s</strong> de %s à %s : %d personne%s%s</li>',
                    htmlspecialchars($poste->getIntitule()),
                    $poste->getDebut()->format('H:i'),
                    $poste->getFin()->format('H:i'),
                    $poste->getManquants(),
                    $poste->getManquants() > 1 ? 's' : '',
                    $poste->getDescription() ? ' (' . htmlspecialchars($poste->getDescription()) . ')' : ''
                );
            }
        }
        if ('' === $postes) {
            $this->addFlash('info', 'Tous les postes sont pourvus : pas besoin d\'appel à bénévoles.');

            return $this->redirectToShow($jour, 'benevoles');
        }

        $url = $this->generateUrl('benevolat_index', [], UrlGeneratorInterface::ABSOLUTE_URL);
        $newsletter = new Newsletter();
        $newsletter->setSubject(sprintf('Appel à bénévoles : distribution du %s', $date))
            ->setContent(sprintf(
                '<p>Bonjour,</p><p>Nous cherchons des bénévoles pour la distribution du %s%s :</p><ul>%s</ul><p>Pour vous inscrire, rendez-vous sur la page <a href="%s">Bénévolat</a> du site. Merci !</p>',
                $date,
                $jour->getLieu() ? ', ' . htmlspecialchars($jour->getLieu()) : '',
                $postes,
                htmlspecialchars($url)
            ))
            ->setOrderButton(false);
        $this->entityManager->persist($newsletter);
        $this->entityManager->flush();
        $this->addFlash('success', 'Brouillon de newsletter préparé : choisissez les destinataires (par exemple le groupe Bénévoles), relisez, puis envoyez-le.');

        return $this->redirectToRoute('newsletter_edit', ['id' => $newsletter->getId()]);
    }

    private function applyPoste(PosteBenevole $poste, array $data): void
    {
        $jour = $poste->getJourDistrib();
        $poste->setIntitule(trim((string) $data['intitule']))
            ->setDescription('' !== trim((string) $data['description']) ? trim((string) $data['description']) : null)
            ->setDebut($this->distribution->heure($jour, $data['debut']))
            ->setFin($this->distribution->heure($jour, $data['fin']))
            ->setBesoin((int) $data['besoin']);
    }

    private function isTokenValid(Request $request, JourDistrib $jour): bool
    {
        return $this->isCsrfTokenValid('distribution' . $jour->getId(), $request->request->get('_token'));
    }

    private function redirectToShow(JourDistrib $jour, ?string $section = null): Response
    {
        return $this->redirect($this->generateUrl('jour_distrib_distribution', ['id' => $jour->getId()]) . ($section ? '#' . $section : ''));
    }

    private function flashErrors(FormInterface $form): void
    {
        $messages = [];
        foreach ($form->getErrors(true) as $error) {
            $messages[] = htmlspecialchars($error->getMessage());
        }
        $this->addFlash('danger', $messages ? implode(' ', array_unique($messages)) : 'Formulaire invalide.');
    }

    /**
     * @param array{sent: int, failed: string[]} $result
     */
    private function flashEnvoi(array $result, string $succes, string $rien): void
    {
        if ($result['sent'] > 0) {
            $this->addFlash('success', sprintf($succes, $result['sent']));
        } elseif (!$result['failed']) {
            $this->addFlash('info', $rien);
        }
        if ($result['failed']) {
            $this->addFlash('warning', sprintf('E-mail non envoyé à : %s. Vérifiez la configuration des e-mails.', htmlspecialchars(implode(', ', $result['failed']))));
        }
    }
}
