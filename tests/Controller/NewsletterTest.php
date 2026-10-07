<?php

namespace App\Tests\Controller;

use App\Entity\Newsletter;
use App\Entity\User;
use App\Tests\WebTestCase;
use Symfony\Component\Mime\Email;

/**
 * Newsletters : rédaction, envoi par le transport « newsletter » (Mailjet)
 * et désinscription par le lien signé.
 */
class NewsletterTest extends WebTestCase
{
    /** @var User */
    private $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = $this->createAdmin();
    }

    private function createNewsletter(string $subject = 'Vente de printemps'): Newsletter
    {
        $newsletter = new Newsletter();
        $newsletter->setSubject($subject);
        $newsletter->setContent('<p>La vente ouvre <strong>lundi</strong>.</p>');
        $this->persist($newsletter);

        return $newsletter;
    }

    public function testReservedToAdmins(): void
    {
        $this->login($this->createUser());
        $this->client->request('GET', '/newsletter/');

        $this->assertResponseStatusCodeSame(403);
    }

    public function testCreateNewsletter(): void
    {
        $this->login($this->admin);
        $crawler = $this->client->request('GET', '/newsletter/new');
        $this->client->submit($crawler->filter('form[name="newsletter"]')->form([
            'newsletter[subject]' => 'Vente de printemps',
            'newsletter[content]' => '<p>Bonjour</p>',
        ]));

        $newsletter = $this->em()->getRepository(Newsletter::class)->findOneBy(['subject' => 'Vente de printemps']);
        $this->assertNotNull($newsletter);
        $this->assertRedirectsTo('/newsletter/'.$newsletter->getId());
        $this->assertFalse($newsletter->isSent());

        $this->client->request('GET', '/newsletter/');
        $this->assertSelectorTextContains('.rows', 'Vente de printemps');
    }

    public function testSendToSubscribersOnly(): void
    {
        $this->createUser('abonne@example.com');
        $this->createUser('desinscrit@example.com')->setNewsletter(false);
        $this->em()->flush();
        $newsletter = $this->createNewsletter();

        $this->login($this->admin);
        $this->submitFormWithAction('/newsletter/'.$newsletter->getId(), '/newsletter/'.$newsletter->getId().'/send');

        $this->assertRedirectsTo('/newsletter/'.$newsletter->getId());
        // Le transport « newsletter » (X-Transport) est vérifié dans NewsletterSenderTest :
        // en test, les deux transports sont null:// et indiscernables ici
        $this->assertEmailCount(2);
        $recipients = array_map(function (Email $email) { return $email->getTo()[0]->getAddress(); }, $this->getMailerMessages());
        sort($recipients);
        $this->assertSame(['abonne@example.com', 'admin@example.com'], $recipients);

        $email = $this->getMailerMessage();
        $this->assertSame('Vente de printemps', $email->getSubject());
        $this->assertEmailHtmlBodyContains($email, '<strong>lundi</strong>');
        $this->assertEmailHtmlBodyContains($email, '/desinscription/');
        $this->assertEmailHeaderSame($email, 'List-Unsubscribe-Post', 'List-Unsubscribe=One-Click');

        $newsletter = $this->reload(Newsletter::class, $newsletter->getId());
        $this->assertTrue($newsletter->isSent());
        $this->assertSame(2, $newsletter->getRecipientCount());
        $this->assertSame(0, $newsletter->getFailedCount());
    }

    public function testSentNewsletterIsNotSentAgainNorEditable(): void
    {
        $newsletter = $this->createNewsletter();
        $newsletter->markSent(1, 0);
        $this->em()->flush();

        $this->login($this->admin);
        $crawler = $this->client->request('GET', '/newsletter/'.$newsletter->getId());
        $this->assertCount(0, $crawler->filter('form[action$="/send"]'));

        $this->client->request('GET', '/newsletter/'.$newsletter->getId().'/edit');
        $this->assertRedirectsTo('/newsletter/'.$newsletter->getId());
    }

    public function testSendTestToCurrentAdmin(): void
    {
        $this->createUser('abonne@example.com');
        $newsletter = $this->createNewsletter();

        $this->login($this->admin);
        $this->submitFormWithAction('/newsletter/'.$newsletter->getId(), '/newsletter/'.$newsletter->getId().'/test');
        $this->assertEmailCount(1);
        $this->assertEmailAddressContains($this->getMailerMessage(), 'To', 'admin@example.com');
        $this->assertFalse($this->reload(Newsletter::class, $newsletter->getId())->isSent());
    }

    public function testDeleteNewsletter(): void
    {
        $newsletter = $this->createNewsletter();
        $id = $newsletter->getId();

        $this->login($this->admin);
        $this->submitFormWithAction('/newsletter/'.$id, '/newsletter/'.$id);

        $this->assertRedirectsTo('/newsletter/');
        $this->assertNull($this->reload(Newsletter::class, $id));
    }

    private function unsubscribeUrl(User $user): string
    {
        $newsletter = $this->createNewsletter();
        $this->login($this->admin);
        $this->submitFormWithAction('/newsletter/'.$newsletter->getId(), '/newsletter/'.$newsletter->getId().'/test');

        // L'e-mail de test contient le lien de désinscription de l'administrateur
        $html = $this->getMailerMessage()->getHtmlBody();
        $this->assertSame(1, preg_match('#href="(http[^"]*/desinscription/[^"]+)"#', $html, $m));
        $this->assertStringContainsString('/desinscription/'.$this->admin->getId().'?', html_entity_decode($m[1]));
        $this->client->request('GET', '/logout');

        // Même construction pour l'utilisateur voulu

        return static::getContainer()->get(\App\Service\NewsletterSender::class)->unsubscribeUrl($user);
    }

    public function testUnsubscribeWithSignedLink(): void
    {
        $user = $this->createUser('abonne@example.com');
        $url = $this->unsubscribeUrl($user);

        // Afficher la page ne désinscrit pas (pré-chargement des liens par les messageries)
        $crawler = $this->client->request('GET', $url);
        $this->assertResponseIsSuccessful();
        $this->assertTrue($this->reload(User::class, $user->getId())->isNewsletter());

        $this->client->submit($crawler->filter('form[method="post"]')->last()->form());
        $this->assertResponseIsSuccessful();
        $this->assertSelectorTextContains('h1', 'désinscrit');
        $this->assertFalse($this->reload(User::class, $user->getId())->isNewsletter());

        // Réabonnement depuis la même page
        $crawler = $this->client->request('GET', $url);
        $this->client->submit($crawler->filter('form[method="post"]')->last()->form());
        $this->assertTrue($this->reload(User::class, $user->getId())->isNewsletter());
    }

    public function testOneClickUnsubscribe(): void
    {
        $user = $this->createUser('abonne@example.com');
        $url = $this->unsubscribeUrl($user);

        $this->client->request('POST', $url, ['List-Unsubscribe' => 'One-Click']);

        $this->assertResponseIsSuccessful();
        $this->assertFalse($this->reload(User::class, $user->getId())->isNewsletter());
    }

    public function testTamperedUnsubscribeLinkIsRejected(): void
    {
        $user = $this->createUser('abonne@example.com');
        $other = $this->createUser('autre@example.com');
        $url = $this->unsubscribeUrl($user);

        $this->client->request('POST', str_replace('/desinscription/'.$user->getId(), '/desinscription/'.$other->getId(), $url));
        $this->assertResponseStatusCodeSame(404);

        $this->client->request('POST', '/desinscription/'.$user->getId());
        $this->assertResponseStatusCodeSame(404);
        $this->assertTrue($this->reload(User::class, $user->getId())->isNewsletter());
    }

    public function testAdminCanChangeSubscription(): void
    {
        $user = $this->createUser('abonne@example.com');

        $this->login($this->admin);
        $crawler = $this->client->request('GET', '/user/'.$user->getId().'/edit');
        $form = $crawler->filter('form[name="user_admin"]')->form();
        $form['user_admin[newsletter]']->untick();
        $this->client->submit($form);

        $this->assertFalse($this->reload(User::class, $user->getId())->isNewsletter());
    }
}
