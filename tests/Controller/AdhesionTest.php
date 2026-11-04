<?php

namespace App\Tests\Controller;

use App\Entity\Adhesion;
use App\Entity\Newsletter;
use App\Entity\NewsletterGroup;
use App\Entity\User;
use App\Service\AdhesionPeriode;
use App\Service\OptionsSettings;
use App\Tests\WebTestCase;
use Symfony\Component\Mime\Email;

/**
 * Gestion des adhérents : adhésions, saison, création de compte par un administrateur.
 */
class AdhesionTest extends WebTestCase
{
    /** @var User */
    private $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = $this->createAdmin();
    }

    private function adhesion(User $user, string $debut, string $fin, ?float $montant = null): Adhesion
    {
        $adhesion = new Adhesion($user);
        $adhesion->setDateDebut(new \DateTimeImmutable($debut))->setDateFin(new \DateTimeImmutable($fin))->setMontant($montant);
        $this->persist($adhesion);

        return $adhesion;
    }

    private function periode(): AdhesionPeriode
    {
        return static::getContainer()->get(AdhesionPeriode::class);
    }

    private function setOption(string $name, string $value): void
    {
        static::getContainer()->get(OptionsSettings::class)->set($name, $value);
    }

    public function testReservedToAdmins(): void
    {
        $user = $this->createUser();
        $this->login($user);
        $this->client->request('GET', '/user/'.$user->getId());
        $this->assertResponseStatusCodeSame(403);
        $this->client->request('GET', '/settings/adhesions');
        $this->assertResponseStatusCodeSame(403);
    }

    public function testSeasonFollowsTheStartMonth(): void
    {
        $this->assertSame(['2026-01-01', '2026-12-31'], array_map(function ($d) { return $d->format('Y-m-d'); }, $this->periode()->saison(new \DateTimeImmutable('2026-03-15'))));

        $this->setOption(AdhesionPeriode::MOIS_DEBUT, '9');
        $this->assertSame(['2025-09-01', '2026-08-31'], array_map(function ($d) { return $d->format('Y-m-d'); }, $this->periode()->saison(new \DateTimeImmutable('2026-03-15'))));
        $this->assertSame(['2026-09-01', '2027-08-31'], array_map(function ($d) { return $d->format('Y-m-d'); }, $this->periode()->saison(new \DateTimeImmutable('2026-09-01'))));
        $this->assertSame('2026-2027', $this->periode()->libelle(new \DateTimeImmutable('2026-09-01')));
    }

    public function testSettingsPage(): void
    {
        $this->login($this->admin);
        $crawler = $this->client->request('GET', '/settings/adhesions');
        $this->client->submit($crawler->filter('form[name="adhesion_settings"]')->form([
            'adhesion_settings[moisDebut]' => '9',
            'adhesion_settings[montant]' => '15',
        ]));

        $this->assertRedirectsTo('/settings/adhesions');
        $this->assertSame(9, $this->periode()->moisDebut());
        $this->assertSame(15.0, $this->periode()->montantParDefaut());
    }

    public function testRecordMembershipFromTheUserPage(): void
    {
        $this->setOption(AdhesionPeriode::MONTANT, '12');
        $alice = $this->createUser('alice@example.com', [], 'Martin', 'Alice');
        [$debut, $fin] = $this->periode()->saison();

        $this->login($this->admin);
        $crawler = $this->client->request('GET', '/user/'.$alice->getId());
        $this->assertSelectorTextContains('.page-head', 'Non adhérent');
        $form = $crawler->filter('form[name="adhesion"]')->form();
        // Pré-rempli avec la saison en cours et la cotisation par défaut
        $this->assertSame($debut->format('Y-m-d'), $form['adhesion[dateDebut]']->getValue());
        $this->assertSame($fin->format('Y-m-d'), $form['adhesion[dateFin]']->getValue());
        $this->assertSame(12.0, (float) str_replace(',', '.', $form['adhesion[montant]']->getValue()));
        $form['adhesion[modePaiement]'] = 'cheque';
        $form['adhesion[note]'] = 'Chèque n° 1234';
        $this->client->submit($form);

        $this->assertRedirectsTo('/user/'.$alice->getId());
        $alice = $this->reload(User::class, $alice->getId());
        $this->assertCount(1, $alice->getAdhesions());
        $adhesion = $alice->getAdhesions()->first();
        $this->assertSame(12.0, $adhesion->getMontant());
        $this->assertSame('Chèque', $adhesion->getModePaiementLabel());
        $this->assertTrue($alice->isAdherent());

        $this->client->followRedirect();
        $this->assertSelectorTextContains('.page-head', 'Adhérent·e jusqu\'au '.$fin->format('d/m/Y'));
        $this->assertSelectorTextContains('#adhesions', 'Chèque n° 1234');
    }

    public function testRenewalStartsAfterTheCurrentMembership(): void
    {
        $alice = $this->createUser('alice@example.com');
        $this->adhesion($alice, '-6 months', '+10 days');
        $debut = new \DateTimeImmutable('+11 days');

        $this->login($this->admin);
        $crawler = $this->client->request('GET', '/user/'.$alice->getId());
        $form = $crawler->filter('form[name="adhesion"]')->form();

        $this->assertSame($debut->format('Y-m-d'), $form['adhesion[dateDebut]']->getValue());
        $this->assertSame($this->periode()->saison($debut)[1]->format('Y-m-d'), $form['adhesion[dateFin]']->getValue());
        $this->assertSelectorTextContains('#adhesions summary', 'Renouveler');
    }

    public function testEndMustFollowStart(): void
    {
        $alice = $this->createUser('alice@example.com');

        $this->login($this->admin);
        $crawler = $this->client->request('GET', '/user/'.$alice->getId());
        $this->client->submit($crawler->filter('form[name="adhesion"]')->form([
            'adhesion[dateDebut]' => '2026-09-01',
            'adhesion[dateFin]' => '2026-08-31',
        ]));

        $this->assertResponseStatusCodeSame(422);
        $this->assertSelectorTextContains('#adhesions', 'La date de fin doit être postérieure à la date de début.');
        $this->assertCount(0, $this->reload(User::class, $alice->getId())->getAdhesions());
    }

    public function testEditAndDeleteMembership(): void
    {
        $alice = $this->createUser('alice@example.com');
        $adhesion = $this->adhesion($alice, '-1 month', '+1 month', 10);

        $this->login($this->admin);
        $crawler = $this->client->request('GET', '/user/adhesion/'.$adhesion->getId().'/edit');
        $this->client->submit($crawler->filter('form[name="adhesion"]')->form([
            'adhesion[montant]' => '20',
            'adhesion[dateFin]' => (new \DateTimeImmutable('-1 day'))->format('Y-m-d'),
        ]));
        $this->assertRedirectsTo('/user/'.$alice->getId());
        $alice = $this->reload(User::class, $alice->getId());
        $this->assertSame(20.0, $alice->getAdhesions()->first()->getMontant());
        $this->assertSame(User::ADHESION_EXPIREE, $alice->getStatutAdhesion());

        $this->submitFormWithAction('/user/'.$alice->getId(), '/user/adhesion/'.$adhesion->getId());
        $this->assertRedirectsTo('/user/'.$alice->getId());
        $this->assertSame(User::ADHESION_AUCUNE, $this->reload(User::class, $alice->getId())->getStatutAdhesion());
    }

    public function testListShowsStatusesAndStats(): void
    {
        [$debut] = $this->periode()->saison();
        $alice = $this->createUser('alice@example.com', [], 'Martin', 'Alice');
        $bob = $this->createUser('bob@example.com', [], 'Durand', 'Bob');
        $this->createUser('carol@example.com', [], 'Petit', 'Carol');
        $this->adhesion($alice, $debut->format('Y-m-d'), '+1 month', 15);
        $this->adhesion($bob, '-2 years', '-1 year', 10);

        $this->login($this->admin);
        $crawler = $this->client->request('GET', '/user/');

        $this->assertResponseIsSuccessful();
        $stats = $crawler->filter('.stat .value')->each(function ($node) { return $node->text(); });
        // À jour, expirées, non adhérents (admin et Carol), cotisations de la saison
        $this->assertSame(['1', '1', '2', '15,00 €'], $stats);
        $statuses = $crawler->filter('[data-filter-item]')->each(function ($node) { return trim($node->attr('data-status')); });
        $this->assertContains('ROLE_USER a_jour', $statuses);
        $this->assertContains('ROLE_USER expiree', $statuses);
    }

    public function testDeletingAUserDeletesTheirMemberships(): void
    {
        $alice = $this->createUser('alice@example.com');
        $this->adhesion($alice, '-1 month', '+1 month');

        $this->login($this->admin);
        $this->submitFormWithAction('/user/'.$alice->getId(), '/user/'.$alice->getId());

        $this->assertCount(0, $this->em()->getRepository(Adhesion::class)->findAll());
    }

    public function testAdminCreatesAMemberAccount(): void
    {
        $this->setOption(AdhesionPeriode::MONTANT, '10');

        $this->login($this->admin);
        $crawler = $this->client->request('GET', '/user/new');
        $form = $crawler->filter('form[name="user_admin"]')->form([
            'user_admin[nom]' => 'Lefebvre',
            'user_admin[prenom]' => 'Denise',
            'user_admin[mail]' => 'denise@example.com',
            'user_admin[phone]' => '0601020304',
        ]);
        $form['user_admin[adhesion]']->tick();
        $this->client->submit($form);

        $user = $this->em()->getRepository(User::class)->findOneBy(['mail' => 'denise@example.com']);
        $this->assertNotNull($user);
        $this->assertRedirectsTo('/user/'.$user->getId());
        $this->assertSame('denise@example.com', $user->getUserIdentifier());
        $this->assertNotContains('ROLE_ADMIN', $user->getRoles());
        $this->assertTrue($user->isAdherent());
        $this->assertSame(10.0, $user->getAdhesions()->first()->getMontant());
        // Mot de passe aléatoire, haché
        $this->assertStringStartsWith('$', $user->getPassword());
    }

    public function testCreatedAccountNeedsAUniqueEmail(): void
    {
        $this->createUser('alice@example.com');

        $this->login($this->admin);
        $crawler = $this->client->request('GET', '/user/new');
        $this->client->submit($crawler->filter('form[name="user_admin"]')->form([
            'user_admin[nom]' => 'Martin',
            'user_admin[prenom]' => 'Alice',
            'user_admin[mail]' => 'alice@example.com',
            'user_admin[phone]' => '0601020304',
        ]));

        $this->assertSelectorTextContains('body', 'L\'email renseigné est déjà utilisé.');
        $this->assertCount(1, $this->em()->getRepository(User::class)->findBy(['mail' => 'alice@example.com']));
    }

    private function newsletter(bool $adherentsOnly, array $groups = []): Newsletter
    {
        $newsletter = new Newsletter();
        $newsletter->setSubject('Assemblée générale')->setContent('<p>Convocation</p>')->setAdherentsOnly($adherentsOnly);
        foreach ($groups as $group) {
            $newsletter->addGroup($group);
        }
        $this->persist($newsletter);

        return $newsletter;
    }

    /** @return string[] */
    private function sentTo(): array
    {
        $recipients = array_map(function (Email $email) { return $email->getTo()[0]->getAddress(); }, $this->getMailerMessages());
        sort($recipients);

        return $recipients;
    }

    public function testNewsletterForCurrentMembersOnly(): void
    {
        $alice = $this->createUser('alice@example.com');
        $bob = $this->createUser('bob@example.com');
        $carol = $this->createUser('carol@example.com');
        $denise = $this->createUser('denise@example.com');
        $denise->setNewsletter(false);
        $this->em()->flush();
        $this->adhesion($alice, '-1 month', '+1 month');
        $this->adhesion($bob, '-2 years', '-1 year');
        $this->adhesion($carol, '+1 day', '+1 year');
        $this->adhesion($denise, '-1 month', '+1 month');
        $newsletter = $this->newsletter(true);

        $this->login($this->admin);
        $this->client->request('GET', '/newsletter/'.$newsletter->getId());
        $this->assertSelectorTextContains('.page-head', 'Adhérents à jour uniquement');
        $this->assertSelectorTextContains('body', 'Envoyer à 1 abonné');

        $this->submitFormWithAction('/newsletter/'.$newsletter->getId(), '/newsletter/'.$newsletter->getId().'/send');

        // Bob a expiré, Carol n'a pas commencé, Denise est désinscrite
        $this->assertSame(['alice@example.com'], $this->sentTo());
    }

    public function testCurrentMembersOptionCombinesWithGroups(): void
    {
        $alice = $this->createUser('alice@example.com');
        $bob = $this->createUser('bob@example.com');
        $this->createUser('carol@example.com');
        $this->adhesion($alice, '-1 month', '+1 month');
        $this->adhesion($bob, '-1 month', '+1 month');
        $group = new NewsletterGroup();
        $group->setName('Bureau');
        $group->addUser($alice);
        $this->persist($group);
        $newsletter = $this->newsletter(true, [$group]);

        $this->login($this->admin);
        $this->submitFormWithAction('/newsletter/'.$newsletter->getId(), '/newsletter/'.$newsletter->getId().'/send');

        $this->assertSame(['alice@example.com'], $this->sentTo());
    }

    public function testNoCurrentMemberSendsNothing(): void
    {
        $this->createUser('alice@example.com');
        $newsletter = $this->newsletter(true);

        $this->login($this->admin);
        $this->submitFormWithAction('/newsletter/'.$newsletter->getId(), '/newsletter/'.$newsletter->getId().'/send');

        $this->assertEmailCount(0);
        $this->assertFalse($this->reload(Newsletter::class, $newsletter->getId())->isSent());
        $this->client->followRedirect();
        $this->assertSelectorTextContains('.alert-warning', 'Aucun abonné parmi les destinataires choisis.');
    }

    public function testOptionIsSetFromTheFormAndKeptWhenDuplicating(): void
    {
        $this->login($this->admin);
        $crawler = $this->client->request('GET', '/newsletter/new');
        $form = $crawler->filter('form[name="newsletter"]')->form([
            'newsletter[subject]' => 'Assemblée générale',
            'newsletter[content]' => '<p>Convocation</p>',
        ]);
        $form['newsletter[adherentsOnly]']->tick();
        $this->client->submit($form);

        $newsletter = $this->em()->getRepository(Newsletter::class)->findOneBy(['subject' => 'Assemblée générale']);
        $this->assertTrue($newsletter->isAdherentsOnly());

        $this->submitFormWithAction('/newsletter/'.$newsletter->getId(), '/newsletter/'.$newsletter->getId().'/duplicate');
        $copies = $this->em()->getRepository(Newsletter::class)->findBy([], ['id' => 'DESC'], 1);
        $this->assertNotSame($newsletter->getId(), $copies[0]->getId());
        $this->assertTrue($copies[0]->isAdherentsOnly());

        $this->client->request('GET', '/newsletter/');
        $this->assertSelectorTextContains('.rows', 'adhérents à jour');
    }
}
