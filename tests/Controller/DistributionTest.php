<?php

namespace App\Tests\Controller;

use App\Entity\Commande;
use App\Entity\CreneauRetrait;
use App\Entity\InscriptionBenevole;
use App\Entity\JourDistrib;
use App\Entity\Newsletter;
use App\Entity\PosteBenevole;
use App\Entity\Product;
use App\Entity\User;
use App\Tests\WebTestCase;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\Mime\Email;

/**
 * Organisation des distributions : créneaux de retrait, bénévolat, feuille
 * du jour J et rappels.
 */
class DistributionTest extends WebTestCase
{
    /** @var User */
    private $admin;

    /** @var Product */
    private $product;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = $this->createAdmin();
        $this->product = $this->createProduct();
    }

    /**
     * Vente dont la distribution a lieu dans $jours jours (date de livraison
     * = date de la vente + 3 jours, voir createJourDistrib).
     */
    private function vente(int $jours = 10): JourDistrib
    {
        $jour = $this->createJourDistrib([$this->product], 0, false, false, sprintf('%+d days', $jours - 3));
        $jour->setLieu('Salle des fêtes');
        $this->em()->flush();

        return $jour;
    }

    private function creneau(JourDistrib $jour, string $debut, string $fin, int $places = 4): CreneauRetrait
    {
        $date = $jour->getDateDistribution();
        [$h, $m] = explode(':', $debut);
        [$h2, $m2] = explode(':', $fin);
        $creneau = new CreneauRetrait($jour, $date->setTime((int) $h, (int) $m), $date->setTime((int) $h2, (int) $m2), $places);
        $this->persist($creneau);

        return $creneau;
    }

    private function poste(JourDistrib $jour, string $intitule = 'Distribution', int $besoin = 2, string $debut = '09:00', string $fin = '11:00'): PosteBenevole
    {
        $date = $jour->getDateDistribution();
        [$h, $m] = explode(':', $debut);
        [$h2, $m2] = explode(':', $fin);
        $poste = new PosteBenevole($jour);
        $poste->setIntitule($intitule)->setBesoin($besoin)
            ->setDebut($date->setTime((int) $h, (int) $m))
            ->setFin($date->setTime((int) $h2, (int) $m2));
        $this->persist($poste);

        return $poste;
    }

    private function commande(User $user, JourDistrib $jour, bool $confirmed = true): Commande
    {
        return $this->createCommande($user, $jour, [[$this->product, 1]], $confirmed);
    }

    private function inscrire(PosteBenevole $poste, User $user): InscriptionBenevole
    {
        $inscription = new InscriptionBenevole($poste, $user);
        $this->persist($inscription);

        return $inscription;
    }

    /** @return string[] */
    private function sentTo(): array
    {
        $to = array_map(function (Email $email) { return $email->getTo()[0]->getAddress(); }, $this->getMailerMessages());
        sort($to);

        return $to;
    }

    private function setConfirmed(Commande $commande, bool $confirmed): void
    {
        $this->reload(Commande::class, $commande->getId())->setConfirmed($confirmed);
        $this->em()->flush();
    }

    private function choisir(Commande $commande, CreneauRetrait $creneau): void
    {
        $crawler = $this->client->request('GET', '/commande/'.$commande->getId().'/creneau');
        $this->assertResponseIsSuccessful();
        $token = $crawler->filter('input[name="_token"]')->attr('value');
        $this->client->request('POST', '/commande/'.$commande->getId().'/creneau', ['creneau' => $creneau->getId(), '_token' => $token]);
        $this->assertRedirectsTo('/commande/'.$commande->getId().'/creneau');
    }

    private function adminPost(JourDistrib $jour, string $url, array $data = []): void
    {
        $crawler = $this->client->request('GET', '/jour/distrib/'.$jour->getId().'/distribution');
        $this->assertResponseIsSuccessful();
        $token = $crawler->filter('form[action$="/rappels"] input[name="_token"]')->attr('value');
        $this->client->request('POST', $url, $data + ['_token' => $token]);
    }

    // ---- Créneaux de retrait ----------------------------------------------

    public function testAdminGeneratesPickupSlots(): void
    {
        $jour = $this->vente();

        $this->login($this->admin);
        $crawler = $this->client->request('GET', '/jour/distrib/'.$jour->getId().'/distribution');
        $this->assertResponseIsSuccessful();
        $this->client->submit($crawler->filter('form[name="generateur_creneaux"]')->form([
            'generateur_creneaux[de]' => '09:00',
            'generateur_creneaux[a]' => '12:00',
            'generateur_creneaux[duree]' => '15',
            'generateur_creneaux[places]' => '4',
        ]));

        $this->assertRedirectsTo('/jour/distrib/'.$jour->getId().'/distribution#creneaux');
        $jour = $this->reload(JourDistrib::class, $jour->getId());
        $this->assertCount(12, $jour->getCreneaux());
        $premier = $jour->getCreneaux()->first();
        $this->assertSame($jour->getDateDistribution()->format('Y-m-d').' 09:00', $premier->getDebut()->format('Y-m-d H:i'));
        $this->assertSame('11:45–12:00', $jour->getCreneaux()->last()->getLibelle());
        $this->assertSame(4, $premier->getPlaces());

        // Les créneaux existants ne sont pas recréés
        $crawler = $this->client->request('GET', '/jour/distrib/'.$jour->getId().'/distribution');
        $this->client->submit($crawler->filter('form[name="generateur_creneaux"]')->form([
            'generateur_creneaux[de]' => '11:00',
            'generateur_creneaux[a]' => '13:00',
            'generateur_creneaux[duree]' => '30',
            'generateur_creneaux[places]' => '2',
        ]));
        $this->assertCount(14, $this->reload(JourDistrib::class, $jour->getId())->getCreneaux());
    }

    public function testInvalidGeneratorShowsAnError(): void
    {
        $jour = $this->vente();

        $this->login($this->admin);
        $crawler = $this->client->request('GET', '/jour/distrib/'.$jour->getId().'/distribution');
        $this->client->submit($crawler->filter('form[name="generateur_creneaux"]')->form([
            'generateur_creneaux[de]' => '12:00',
            'generateur_creneaux[a]' => '09:00',
        ]));
        $this->client->followRedirect();

        $this->assertSelectorTextContains('.alert-danger', 'L\'heure de fin doit être après l\'heure de début.');
        $this->assertCount(0, $this->reload(JourDistrib::class, $jour->getId())->getCreneaux());
    }

    public function testCustomerChoosesASlotOnlyOnceTheOrderIsValidated(): void
    {
        $jour = $this->vente();
        $creneau = $this->creneau($jour, '10:00', '10:15');
        $alice = $this->createUser('alice@example.com');
        $commande = $this->commande($alice, $jour, false);

        $this->login($alice);
        $this->client->request('GET', '/commande/'.$commande->getId().'/creneau');
        $this->assertSelectorTextContains('.panel', 'dès que votre commande sera validée');
        $this->assertSelectorNotExists('input[name="creneau"]');

        // Jeton lu pendant que la commande est validée, puis validation retirée
        $this->setConfirmed($commande, true);
        $token = $this->client->request('GET', '/commande/'.$commande->getId().'/creneau')->filter('input[name="_token"]')->attr('value');
        $this->setConfirmed($commande, false);
        $this->client->request('POST', '/commande/'.$commande->getId().'/creneau', ['creneau' => $creneau->getId(), '_token' => $token]);
        $this->assertNull($this->reload(Commande::class, $commande->getId())->getCreneau());

        $this->setConfirmed($commande, true);
        $this->choisir($commande, $creneau);

        $commande = $this->reload(Commande::class, $commande->getId());
        $this->assertSame($creneau->getId(), $commande->getCreneau()->getId());
        $this->client->followRedirect();
        $this->assertSelectorTextContains('.alert-success', 'retrait le');
        $this->client->request('GET', '/commande/');
        $this->assertSelectorTextContains('.retrait-box', '10:00–10:15');
    }

    public function testFullSlotIsRefused(): void
    {
        $jour = $this->vente();
        $plein = $this->creneau($jour, '10:00', '10:15', 1);
        $this->creneau($jour, '10:15', '10:30', 1);
        $bob = $this->createUser('bob@example.com');
        $commandeBob = $this->commande($bob, $jour);
        $commandeBob->setCreneau($plein);
        $this->em()->flush();
        $alice = $this->createUser('alice@example.com');
        $commande = $this->commande($alice, $jour);

        $this->login($alice);
        $crawler = $this->client->request('GET', '/commande/'.$commande->getId().'/creneau');
        $this->assertCount(1, $crawler->filter('input[name="creneau"][disabled]'));
        $this->choisir($commande, $plein);

        $this->assertNull($this->reload(Commande::class, $commande->getId())->getCreneau());
        $this->client->followRedirect();
        $this->assertSelectorTextContains('.alert-warning', 'complet');
    }

    public function testSlotCannotBeChangedAfterTheDeadline(): void
    {
        // Distribution demain : la date limite (veille 18 h) est aujourd'hui à 18 h
        $jour = $this->vente(0);
        $creneau = $this->creneau($jour, '10:00', '10:15');
        $alice = $this->createUser('alice@example.com');
        $commande = $this->commande($alice, $jour);

        $this->login($alice);
        $this->client->request('GET', '/commande/'.$commande->getId().'/creneau');
        $this->assertSelectorTextContains('.panel', 'ne peuvent plus être modifiés');
        $this->assertSelectorNotExists('input[name="creneau"]');
    }

    public function testOtherCustomersCannotChooseForSomeoneElse(): void
    {
        $jour = $this->vente();
        $this->creneau($jour, '10:00', '10:15');
        $commande = $this->commande($this->createUser('alice@example.com'), $jour);

        $this->login($this->createUser('bob@example.com'));
        $this->client->request('GET', '/commande/'.$commande->getId().'/creneau');

        $this->assertResponseStatusCodeSame(403);
    }

    public function testAdminPlacesACustomerEvenOnAFullSlot(): void
    {
        $jour = $this->vente();
        $plein = $this->creneau($jour, '10:00', '10:15', 1);
        $commandeBob = $this->commande($this->createUser('bob@example.com'), $jour);
        $commandeBob->setCreneau($plein);
        $this->em()->flush();
        $commande = $this->commande($this->createUser('alice@example.com'), $jour);

        $this->login($this->admin);
        $this->adminPost($jour, '/jour/distrib/commande/'.$commande->getId().'/placer', ['creneau' => $plein->getId()]);

        $this->assertRedirectsTo('/jour/distrib/'.$jour->getId().'/distribution#commandes');
        $this->assertSame(2, $this->reload(CreneauRetrait::class, $plein->getId())->getReservees());
    }

    public function testDeletingASlotFreesItsCustomers(): void
    {
        $jour = $this->vente();
        $creneau = $this->creneau($jour, '10:00', '10:15');
        $this->creneau($jour, '10:15', '10:30');
        $commande = $this->commande($this->createUser('alice@example.com'), $jour);
        $commande->setCreneau($creneau);
        $this->em()->flush();

        $this->login($this->admin);
        $this->adminPost($jour, '/jour/distrib/creneau/'.$creneau->getId().'/supprimer');

        $this->assertCount(1, $this->reload(JourDistrib::class, $jour->getId())->getCreneaux());
        $commande = $this->reload(Commande::class, $commande->getId());
        $this->assertNull($commande->getCreneau());
        $this->assertTrue($commande->isCreneauAChoisir());
    }

    public function testValidationEmailAsksToChooseASlot(): void
    {
        $jour = $this->vente();
        $this->creneau($jour, '10:00', '10:15');
        $commande = $this->commande($this->createUser('alice@example.com'), $jour, false);

        $this->login($this->admin);
        $this->client->request('GET', '/confirme/'.$commande->getId());

        $this->assertEmailCount(1);
        $html = $this->getMailerMessage()->getHtmlBody();
        $this->assertStringContainsString('Choisissez votre créneau de retrait', $html);
        $this->assertStringContainsString('/commande/'.$commande->getId().'/creneau', $html);
        $this->assertStringContainsString('Salle des fêtes', $html);
    }

    public function testHomePageRemindsToChooseASlot(): void
    {
        $jour = $this->vente();
        $this->creneau($jour, '10:00', '10:15');
        $alice = $this->createUser('alice@example.com');
        $commande = $this->commande($alice, $jour);

        $this->login($alice);
        $crawler = $this->client->request('GET', '/');

        $this->assertSelectorTextContains('.alert-warning', 'choisissez votre créneau de retrait');
        $this->assertCount(1, $crawler->filter('a[href="/commande/'.$commande->getId().'/creneau"]'));
    }

    public function testPlanningCanBeCopiedFromAPreviousSale(): void
    {
        $ancienne = $this->vente(-20);
        $this->creneau($ancienne, '09:00', '09:30', 5);
        $this->creneau($ancienne, '09:30', '10:00', 5);
        $this->poste($ancienne, 'Rangement', 2, '12:00', '12:30');
        $jour = $this->vente();

        $this->login($this->admin);
        $this->adminPost($jour, '/jour/distrib/'.$jour->getId().'/copier', ['source' => $ancienne->getId()]);

        $jour = $this->reload(JourDistrib::class, $jour->getId());
        $this->assertCount(2, $jour->getCreneaux());
        $this->assertSame($jour->getDateDistribution()->format('Y-m-d').' 09:30', $jour->getCreneaux()->last()->getDebut()->format('Y-m-d H:i'));
        $this->assertSame('Rangement', $jour->getPostes()->first()->getIntitule());
        $this->assertSame('12:00–12:30', $jour->getPostes()->first()->getLibelle());
    }

    public function testDistributionPagesAreReservedToAdmins(): void
    {
        $jour = $this->vente();
        $this->login($this->createUser());
        $this->client->request('GET', '/jour/distrib/'.$jour->getId().'/distribution');

        $this->assertResponseStatusCodeSame(403);
    }

    // ---- Bénévolat ----------------------------------------------------------

    public function testVolunteerSignsUpAndCancels(): void
    {
        $jour = $this->vente();
        $poste = $this->poste($jour, 'Installation', 1, '08:30', '09:00');
        $alice = $this->createUser('alice@example.com', [], 'Martin', 'Alice');

        $this->login($alice);
        $crawler = $this->client->request('GET', '/benevolat/');
        $this->assertSelectorTextContains('#jour-'.$jour->getId(), 'Installation');
        $this->client->submit($crawler->filter('form[action="/benevolat/poste/'.$poste->getId().'/inscription"]')->form());

        $this->assertRedirectsTo('/benevolat/#jour-'.$jour->getId());
        $this->assertTrue($this->reload(PosteBenevole::class, $poste->getId())->isInscrit($this->reload(User::class, $alice->getId())));
        $this->client->followRedirect();
        $this->assertSelectorTextContains('.alert-success', 'Merci');
        $this->assertSelectorTextContains('.panel', 'Mes prochains bénévolats');

        $this->submitFormWithAction('/benevolat/', '/benevolat/poste/'.$poste->getId().'/desinscription');
        $this->assertCount(0, $this->reload(PosteBenevole::class, $poste->getId())->getInscriptions());
    }

    public function testFullPositionIsRefused(): void
    {
        $jour = $this->vente();
        $poste = $this->poste($jour, 'Installation', 1);
        $alice = $this->createUser('alice@example.com');

        $this->login($alice);
        $token = $this->client->request('GET', '/benevolat/')->filter('form[action="/benevolat/poste/'.$poste->getId().'/inscription"] input[name="_token"]')->attr('value');
        // Bob prend la dernière place entre-temps
        $this->inscrire($this->reload(PosteBenevole::class, $poste->getId()), $this->createUser('bob@example.com'));
        $crawler = $this->client->request('GET', '/benevolat/');
        $this->assertCount(0, $crawler->filter('form[action="/benevolat/poste/'.$poste->getId().'/inscription"]'));
        $this->client->request('POST', '/benevolat/poste/'.$poste->getId().'/inscription', ['_token' => $token]);

        $this->assertCount(1, $this->reload(PosteBenevole::class, $poste->getId())->getInscriptions());
        $this->client->followRedirect();
        $this->assertSelectorTextContains('.alert-warning', 'complet');
    }

    public function testVolunteerCannotCancelOnlineAfterTheDeadline(): void
    {
        $jour = $this->vente(0);
        $poste = $this->poste($jour, 'Rangement', 2, '23:58', '23:59');
        $alice = $this->createUser('alice@example.com');

        $this->login($alice);
        $token = $this->client->request('GET', '/benevolat/')->filter('form[action="/benevolat/poste/'.$poste->getId().'/inscription"] input[name="_token"]')->attr('value');
        $this->em()->clear();
        $this->inscrire($this->em()->find(PosteBenevole::class, $poste->getId()), $this->em()->find(User::class, $alice->getId()));
        $crawler = $this->client->request('GET', '/benevolat/');
        $this->assertCount(0, $crawler->filter('form[action$="/desinscription"]'));
        $this->client->request('POST', '/benevolat/poste/'.$poste->getId().'/desinscription', ['_token' => $token]);

        $this->assertCount(1, $this->reload(PosteBenevole::class, $poste->getId())->getInscriptions());
    }

    public function testAdminAddsAndRemovesVolunteers(): void
    {
        $jour = $this->vente();
        $poste = $this->poste($jour, 'Installation', 1);
        $alice = $this->createUser('alice@example.com');
        $bob = $this->createUser('bob@example.com');
        $this->inscrire($poste, $bob);

        $this->login($this->admin);
        // Même au-delà du besoin (personne inscrite par téléphone…)
        $this->adminPost($jour, '/jour/distrib/poste/'.$poste->getId().'/ajouter', ['user' => $alice->getId()]);
        $poste = $this->reload(PosteBenevole::class, $poste->getId());
        $this->assertCount(2, $poste->getInscriptions());

        $inscription = $this->em()->getRepository(InscriptionBenevole::class)->findOneBy(['poste' => $poste, 'user' => $bob->getId()]);
        $this->adminPost($jour, '/jour/distrib/inscription/'.$inscription->getId().'/retirer');
        $this->assertCount(1, $this->reload(PosteBenevole::class, $poste->getId())->getInscriptions());

        $this->client->request('GET', '/user/'.$alice->getId());
        $this->assertSelectorTextContains('#benevolat', 'Installation');
    }

    public function testCallForVolunteersPreparesANewsletterDraft(): void
    {
        $jour = $this->vente();
        $this->poste($jour, 'Rangement', 3, '12:00', '12:30');
        $this->inscrire($this->poste($jour, 'Installation', 1, '08:30', '09:00'), $this->createUser('bob@example.com'));

        $this->login($this->admin);
        $this->adminPost($jour, '/jour/distrib/'.$jour->getId().'/appel-benevoles');

        $newsletter = $this->em()->getRepository(Newsletter::class)->findOneBy([]);
        $this->assertNotNull($newsletter);
        $this->assertRedirectsTo('/newsletter/'.$newsletter->getId().'/edit');
        $this->assertFalse($newsletter->isSent());
        $this->assertStringContainsString('Appel à bénévoles', $newsletter->getSubject());
        $this->assertStringContainsString('<strong>Rangement</strong> de 12:00 à 12:30 : 3 personnes', $newsletter->getContent());
        $this->assertStringNotContainsString('Installation', $newsletter->getContent());
        $this->assertStringContainsString('/benevolat/', $newsletter->getContent());
    }

    // ---- Jour J ---------------------------------------------------------------

    public function testDistributionSheetAccess(): void
    {
        $aujourdhui = $this->vente(0);
        $poste = $this->poste($aujourdhui);
        $benevole = $this->createUser('benevole@example.com');
        $this->inscrire($poste, $benevole);
        $plusTard = $this->vente();
        $this->inscrire($this->poste($plusTard), $benevole);

        $this->login($benevole);
        $this->client->request('GET', '/distribution/'.$aujourdhui->getId());
        $this->assertResponseIsSuccessful();
        // Pas avant le jour de la distribution
        $this->client->request('GET', '/distribution/'.$plusTard->getId());
        $this->assertResponseStatusCodeSame(403);

        $this->login($this->createUser('client@example.com'));
        $this->client->request('GET', '/distribution/'.$aujourdhui->getId());
        $this->assertResponseStatusCodeSame(403);

        $this->login($this->admin);
        $this->client->request('GET', '/distribution/'.$plusTard->getId());
        $this->assertResponseIsSuccessful();
    }

    public function testVolunteerHandsOverOrdersAndAdminChecksPresence(): void
    {
        $jour = $this->vente(0);
        $creneau = $this->creneau($jour, '00:00', '23:59');
        $poste = $this->poste($jour);
        $benevole = $this->createUser('benevole@example.com');
        $inscription = $this->inscrire($poste, $benevole);
        $commande = $this->commande($this->createUser('alice@example.com', [], 'Martin', 'Alice'), $jour);
        $commande->setCreneau($creneau);
        $this->em()->flush();
        $this->commande($this->createUser('bob@example.com', [], 'Durand', 'Bob'), $jour);

        $this->login($benevole);
        $crawler = $this->client->request('GET', '/distribution/'.$jour->getId());
        $this->assertSelectorTextContains('.sheet-row', 'Alice Martin');
        $this->assertSelectorTextNotContains('.panel', 'Bob Durand');
        $this->client->submit($crawler->filter('form[action="/distribution/commande/'.$commande->getId().'/remise"]')->form());

        $this->assertRedirectsTo('/distribution/'.$jour->getId().'?creneau='.$creneau->getId());
        $commande = $this->reload(Commande::class, $commande->getId());
        $this->assertTrue($commande->getLivree());
        $this->assertTrue($commande->getLigneCommandes()->first()->getLivree());

        $crawler = $this->client->request('GET', '/distribution/'.$jour->getId().'?creneau=0');
        $this->assertSelectorTextContains('.sheet-row', 'Bob Durand');
        $this->client->submit($crawler->filter('form[action="/distribution/inscription/'.$inscription->getId().'/presence"]')->form());
        $this->assertTrue($this->reload(InscriptionBenevole::class, $inscription->getId())->isPresent());
    }

    // ---- Rappels ----------------------------------------------------------------

    public function testAutomaticRemindersAreSentOnceTheDayBefore(): void
    {
        $demain = $this->vente(1);
        $creneau = $this->creneau($demain, '10:00', '10:15');
        $alice = $this->createUser('alice@example.com');
        $commande = $this->commande($alice, $demain);
        $commande->setCreneau($creneau);
        $this->em()->flush();
        $this->commande($this->createUser('bob@example.com'), $demain);
        $this->commande($this->createUser('attente@example.com'), $demain, false);
        $this->inscrire($this->poste($demain, 'Installation', 2, '08:30', '09:00'), $this->createUser('benevole@example.com'));
        // Distribution dans 10 jours : rien à envoyer
        $plusTard = $this->vente();
        $this->commande($this->createUser('plustard@example.com'), $plusTard);
        $this->creneau($plusTard, '10:00', '10:15');

        // Nouveau processus en production : rien en mémoire
        $this->em()->clear();
        $tester = new CommandTester((new Application(static::$kernel))->find('app:distribution:rappels'));
        $this->assertSame(0, $tester->execute(['--force' => true]));

        $this->assertSame(['alice@example.com', 'benevole@example.com', 'bob@example.com'], $this->sentTo());
        $messages = [];
        foreach ($this->getMailerMessages() as $email) {
            $messages[$email->getTo()[0]->getAddress()] = $email->getHtmlBody();
        }
        $this->assertStringContainsString('de 10:00 à 10:15', $messages['alice@example.com']);
        $this->assertStringContainsString('Choisissez votre créneau', $messages['bob@example.com']);
        $this->assertStringContainsString('08:30–09:00', $messages['benevole@example.com']);
        $this->assertStringContainsString('Installation', $messages['benevole@example.com']);

        // Deuxième passage : chacun l'a déjà reçu
        $tester->execute(['--force' => true]);
        $this->assertStringContainsString('0 rappel(s) envoyé(s)', $tester->getDisplay());
        $this->assertNotNull($this->reload(Commande::class, $commande->getId())->getRappelEnvoyeLe());
    }

    public function testAdminSendsRemindersAndNudgesCustomersWithoutSlot(): void
    {
        $jour = $this->vente();
        $creneau = $this->creneau($jour, '10:00', '10:15');
        $avec = $this->commande($this->createUser('avec@example.com'), $jour);
        $avec->setCreneau($creneau);
        $this->em()->flush();
        $this->commande($this->createUser('sans@example.com'), $jour);

        $this->login($this->admin);
        $this->adminPost($jour, '/jour/distrib/'.$jour->getId().'/relance');
        $this->assertSame(['sans@example.com'], $this->sentTo());
        $this->assertStringContainsString('Choisissez votre créneau de retrait', $this->getMailerMessage()->getSubject());

        $this->adminPost($jour, '/jour/distrib/'.$jour->getId().'/rappels');
        $this->assertSame(['avec@example.com', 'sans@example.com'], $this->sentTo());
        $this->client->followRedirect();
        $this->assertSelectorTextContains('.alert-success', 'Rappel envoyé à 2 personne(s).');
    }
}
