<?php

namespace App\Tests\Controller;

use App\Entity\Newsletter;
use App\Entity\NewsletterGroup;
use App\Entity\User;
use App\Tests\WebTestCase;
use Symfony\Component\Mime\Email;

/**
 * Groupes de newsletter : gestion par les administrateurs et envoi ciblé.
 */
class NewsletterGroupTest extends WebTestCase
{
    /** @var User */
    private $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = $this->createAdmin();
    }

    private function createGroup(string $name, array $users = []): NewsletterGroup
    {
        $group = new NewsletterGroup();
        $group->setName($name);
        foreach ($users as $user) {
            $group->addUser($user);
        }
        $this->persist($group);

        return $group;
    }

    private function createNewsletter(array $groups = []): Newsletter
    {
        $newsletter = new Newsletter();
        $newsletter->setSubject('Réunion du bureau');
        $newsletter->setContent('<p>Ordre du jour</p>');
        foreach ($groups as $group) {
            $newsletter->addGroup($group);
        }
        $this->persist($newsletter);

        return $newsletter;
    }

    /**
     * @return string[]
     */
    private function sentTo(): array
    {
        $recipients = array_map(function (Email $email) { return $email->getTo()[0]->getAddress(); }, $this->getMailerMessages());
        sort($recipients);

        return $recipients;
    }

    public function testReservedToAdmins(): void
    {
        $this->login($this->createUser());
        $this->client->request('GET', '/newsletter/groupes/');

        $this->assertResponseStatusCodeSame(403);
    }

    public function testCreateGroupWithMembers(): void
    {
        $alice = $this->createUser('alice@example.com', [], 'Martin', 'Alice');
        $this->createUser('bob@example.com', [], 'Durand', 'Bob');

        $this->login($this->admin);
        $crawler = $this->client->request('GET', '/newsletter/groupes/new');
        $form = $crawler->filter('form[name="newsletter_group"]')->form();
        $form['newsletter_group[name]'] = 'Bureau';
        $form['newsletter_group[users]']->select([(string) $alice->getId()]);
        $this->client->submit($form);

        $this->assertRedirectsTo('/newsletter/groupes/');
        $group = $this->em()->getRepository(NewsletterGroup::class)->findOneBy(['name' => 'Bureau']);
        $this->assertNotNull($group);
        $this->assertSame(['alice@example.com'], $group->getUsers()->map(function (User $u) { return $u->getMail(); })->toArray());

        $this->client->followRedirect();
        $this->assertSelectorTextContains('.rows', 'Bureau');
        $this->assertSelectorTextContains('.rows', '1 membre');
    }

    public function testGroupNameIsUnique(): void
    {
        $this->createGroup('Bureau');

        $this->login($this->admin);
        $crawler = $this->client->request('GET', '/newsletter/groupes/new');
        $this->client->submit($crawler->filter('form[name="newsletter_group"]')->form([
            'newsletter_group[name]' => 'Bureau',
        ]));

        $this->assertSelectorTextContains('body', 'Un groupe porte déjà ce nom.');
        $this->assertCount(1, $this->em()->getRepository(NewsletterGroup::class)->findAll());
    }

    public function testEditMembersFromGroupAndFromUser(): void
    {
        $alice = $this->createUser('alice@example.com');
        $bob = $this->createUser('bob@example.com');
        $group = $this->createGroup('Bureau', [$alice]);

        $this->login($this->admin);
        $crawler = $this->client->request('GET', '/newsletter/groupes/'.$group->getId().'/edit');
        $form = $crawler->filter('form[name="newsletter_group"]')->form();
        $form['newsletter_group[users]']->select([(string) $bob->getId()]);
        $this->client->submit($form);
        $this->assertRedirectsTo('/newsletter/groupes/');
        $members = $this->reload(NewsletterGroup::class, $group->getId())->getUsers()->map(function (User $u) { return $u->getMail(); })->toArray();
        $this->assertSame(['bob@example.com'], array_values($members));

        // Depuis la fiche utilisateur
        $crawler = $this->client->request('GET', '/user/'.$alice->getId().'/edit');
        $form = $crawler->filter('form[name="user_admin"]')->form();
        $form['user_admin[newsletterGroups]'][0]->tick();
        $this->client->submit($form);
        $this->assertCount(2, $this->reload(NewsletterGroup::class, $group->getId())->getUsers());

        $this->client->request('GET', '/user/'.$alice->getId());
        $this->assertSelectorTextContains('.rows', 'Bureau');
    }

    public function testNewsletterTargetsGroups(): void
    {
        $alice = $this->createUser('alice@example.com');
        $bob = $this->createUser('bob@example.com');
        $carol = $this->createUser('carol@example.com');
        $this->createUser('dave@example.com');
        $carol->setNewsletter(false);
        $bureau = $this->createGroup('Bureau', [$alice, $bob, $carol]);
        $nord = $this->createGroup('Quartier Nord', [$bob]);

        $this->login($this->admin);
        $crawler = $this->client->request('GET', '/newsletter/new');
        $form = $crawler->filter('form[name="newsletter"]')->form([
            'newsletter[subject]' => 'Réunion',
            'newsletter[content]' => '<p>Bonjour</p>',
        ]);
        $form['newsletter[groups]'][0]->tick();
        $form['newsletter[groups]'][1]->tick();
        $this->client->submit($form);
        $newsletter = $this->em()->getRepository(Newsletter::class)->findOneBy(['subject' => 'Réunion']);
        $this->assertCount(2, $newsletter->getGroups());

        $crawler = $this->client->request('GET', '/newsletter/'.$newsletter->getId());
        $this->assertSelectorTextContains('.page-head', 'Bureau');
        $this->assertSelectorTextContains('.page-head', '2 abonnés');

        // Bob, membre des deux groupes, ne reçoit qu'un e-mail ; Carol est désinscrite ; Dave hors groupe
        $this->client->submit($crawler->filter('form[action$="/send"]')->form());
        $this->assertEmailCount(2);
        $this->assertSame(['alice@example.com', 'bob@example.com'], $this->sentTo());
        $this->assertSame(2, $this->reload(Newsletter::class, $newsletter->getId())->getRecipientCount());
    }

    public function testNewsletterWithoutGroupGoesToAllSubscribers(): void
    {
        $this->createUser('alice@example.com');
        $this->createGroup('Bureau');
        $newsletter = $this->createNewsletter();

        $this->login($this->admin);
        $this->submitFormWithAction('/newsletter/'.$newsletter->getId(), '/newsletter/'.$newsletter->getId().'/send');

        $this->assertSame(['admin@example.com', 'alice@example.com'], $this->sentTo());
    }

    public function testEmptyTargetGroupSendsNothing(): void
    {
        $newsletter = $this->createNewsletter([$this->createGroup('Bureau')]);

        $this->login($this->admin);
        $this->submitFormWithAction('/newsletter/'.$newsletter->getId(), '/newsletter/'.$newsletter->getId().'/send');

        $this->assertEmailCount(0);
        $this->assertFalse($this->reload(Newsletter::class, $newsletter->getId())->isSent());
        $this->client->followRedirect();
        $this->assertSelectorTextContains('.flash', 'Aucun abonné dans les groupes destinataires');
    }

    public function testCannotDeleteGroupTargetedByDraft(): void
    {
        $group = $this->createGroup('Bureau');
        $this->createNewsletter([$group]);
        $id = $group->getId();

        $this->login($this->admin);
        $this->submitFormWithAction('/newsletter/groupes/'.$id.'/edit', '/newsletter/groupes/'.$id);

        $this->assertRedirectsTo('/newsletter/groupes/'.$id.'/edit');
        $this->assertNotNull($this->reload(NewsletterGroup::class, $id));
    }

    public function testDeleteGroupKeepsMembersAndSentNewsletters(): void
    {
        $alice = $this->createUser('alice@example.com');
        $group = $this->createGroup('Bureau', [$alice]);
        $sent = $this->createNewsletter([$group]);
        $sent->markSent(1, 0);
        $this->em()->flush();
        $id = $group->getId();

        $this->login($this->admin);
        $this->submitFormWithAction('/newsletter/groupes/'.$id.'/edit', '/newsletter/groupes/'.$id);

        $this->assertRedirectsTo('/newsletter/groupes/');
        $this->assertNull($this->reload(NewsletterGroup::class, $id));
        $this->assertNotNull($this->reload(User::class, $alice->getId()));
        $this->assertTrue($this->reload(Newsletter::class, $sent->getId())->isSent());
    }

    public function testDeletingUserRemovesMembership(): void
    {
        $alice = $this->createUser('alice@example.com');
        $group = $this->createGroup('Bureau', [$alice]);

        $this->login($this->admin);
        $this->submitFormWithAction('/user/'.$alice->getId(), '/user/'.$alice->getId());

        $this->assertNull($this->reload(User::class, $alice->getId()));
        $this->assertCount(0, $this->reload(NewsletterGroup::class, $group->getId())->getUsers());
    }
}
