<?php

namespace App\Tests\Controller;

use App\Entity\JourDistrib;
use App\Entity\Newsletter;
use App\Entity\NewsletterGroup;
use App\Entity\User;
use App\Tests\WebTestCase;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\Mime\Email;

/**
 * Newsletters : clients d'une vente, éditeur avancé (envoi d'images) et logo.
 */
class NewsletterSaleAndEditorTest extends WebTestCase
{
    /** @var User */
    private $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = $this->createAdmin();
    }

    protected function tearDown(): void
    {
        foreach (glob(static::getContainer()->getParameter('newsletter_upload_directory').'/*') ?: [] as $file) {
            if (filemtime($file) >= $_SERVER['REQUEST_TIME'] - 5) {
                unlink($file);
            }
        }
        parent::tearDown();
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

    public function testNewsletterToCustomersOfASale(): void
    {
        $product = $this->createProduct();
        $vente = $this->createJourDistrib([$product]);
        $autreVente = $this->createJourDistrib([$product], 0, false, false, '+14 days');
        $alice = $this->createUser('alice@example.com');
        $bob = $this->createUser('bob@example.com');
        $carol = $this->createUser('carol@example.com');
        $carol->setNewsletter(false);
        $this->createCommande($alice, $vente, [[$product, 1]]);
        $this->createCommande($bob, $autreVente, [[$product, 1]]);
        $this->createCommande($carol, $vente, [[$product, 1]]);

        $this->login($this->admin);
        $crawler = $this->client->request('GET', '/newsletter/new');
        $this->client->submit($crawler->filter('form[name="newsletter"]')->form([
            'newsletter[subject]' => 'Retrait des commandes',
            'newsletter[content]' => '<p>Rendez-vous samedi</p>',
            'newsletter[jourDistrib]' => $vente->getId(),
        ]));
        $newsletter = $this->em()->getRepository(Newsletter::class)->findOneBy(['subject' => 'Retrait des commandes']);
        $this->assertSame($vente->getId(), $newsletter->getJourDistrib()->getId());

        $crawler = $this->client->request('GET', '/newsletter/'.$newsletter->getId());
        $this->assertSelectorTextContains('.page-head', 'Clients de la vente du '.$vente->getDate()->format('d/m/Y'));
        $this->assertSelectorTextContains('.page-head', '1 abonné');

        // Alice a commandé sur la vente ; Bob sur une autre ; Carol est désinscrite
        $this->client->submit($crawler->filter('form[action$="/send"]')->form());
        $this->assertSame(['alice@example.com'], $this->sentTo());
    }

    public function testOnlyUpcomingSalesAreListed(): void
    {
        $passee = $this->createJourDistrib([], 0, false, true, '-20 days');
        $enCours = $this->createJourDistrib([], 0, false, true, '-2 days');
        $aVenir = $this->createJourDistrib([], 0, false, false, '+7 days');

        $this->login($this->admin);
        $crawler = $this->client->request('GET', '/newsletter/new');
        $options = $crawler->filter('#newsletter_jourDistrib option')->each(function ($option) { return $option->attr('value'); });

        // Vente close mais distribution dans 1 jour : toujours à venir
        $this->assertSame(['', (string) $enCours->getId(), (string) $aVenir->getId()], $options);
        $this->assertNotContains((string) $passee->getId(), $options);
    }

    public function testPastSaleAlreadyChosenStaysSelected(): void
    {
        $passee = $this->createJourDistrib([], 0, false, true, '-20 days');
        $newsletter = new Newsletter();
        $newsletter->setSubject('Ancienne vente');
        $newsletter->setContent('<p>x</p>');
        $newsletter->setJourDistrib($passee);
        $this->persist($newsletter);

        $this->login($this->admin);
        $crawler = $this->client->request('GET', '/newsletter/'.$newsletter->getId().'/edit');

        $this->assertSame((string) $passee->getId(), $crawler->filter('#newsletter_jourDistrib option[selected]')->attr('value'));
    }

    public function testSaleCombinedWithGroups(): void
    {
        $product = $this->createProduct();
        $vente = $this->createJourDistrib([$product]);
        $alice = $this->createUser('alice@example.com');
        $bob = $this->createUser('bob@example.com');
        $this->createCommande($alice, $vente, [[$product, 1]]);
        $this->createCommande($bob, $vente, [[$product, 1]]);
        $group = new NewsletterGroup();
        $group->setName('Bureau');
        $group->addUser($bob);
        $group->addUser($this->admin);
        $this->persist($group);
        $newsletter = new Newsletter();
        $newsletter->setSubject('Bureau et clients');
        $newsletter->setContent('<p>x</p>');
        $newsletter->setJourDistrib($vente);
        $newsletter->addGroup($group);
        $this->persist($newsletter);

        $this->login($this->admin);
        $this->submitFormWithAction('/newsletter/'.$newsletter->getId(), '/newsletter/'.$newsletter->getId().'/send');

        // Membres du groupe ET clients de la vente
        $this->assertSame(['bob@example.com'], $this->sentTo());
    }

    public function testCannotDeleteSaleTargetedByDraft(): void
    {
        $vente = $this->createJourDistrib();
        $newsletter = new Newsletter();
        $newsletter->setSubject('Retrait');
        $newsletter->setContent('<p>x</p>');
        $newsletter->setJourDistrib($vente);
        $this->persist($newsletter);
        $id = $vente->getId();

        $this->login($this->admin);
        $this->submitFormWithAction('/jour/distrib/'.$id.'/edit', '/jour/distrib/'.$id);

        $this->assertNotNull($this->reload(JourDistrib::class, $id));
        $this->client->followRedirect();
        $this->assertSelectorTextContains('.flash', 'Des newsletters non envoyées sont réservées aux clients de cette vente');
    }

    public function testEditorIsLoaded(): void
    {
        $this->login($this->admin);
        $crawler = $this->client->request('GET', '/newsletter/new');

        $this->assertCount(1, $crawler->filter('textarea.newsletter-editor'));
        $this->assertStringContainsString('/plugins/jodit/jodit.min.js', $this->client->getResponse()->getContent());
    }

    private function upload(string $path, string $name, ?string $token = null): array
    {
        $crawler = $this->client->request('GET', '/newsletter/new');
        if (null === $token) {
            preg_match('#_token: "([^"]+)"#', $this->client->getResponse()->getContent(), $m);
            $token = $m[1];
        }
        $copy = sys_get_temp_dir().'/'.uniqid().'-'.$name;
        copy($path, $copy);
        $this->client->request('POST', '/newsletter/upload', ['_token' => $token], ['files' => [new UploadedFile($copy, $name, null, null, true)]]);

        return json_decode($this->client->getResponse()->getContent(), true);
    }

    public function testUploadImage(): void
    {
        $this->login($this->admin);
        $json = $this->upload(__DIR__.'/../../public/dist/img/lapallogo.png', 'logo.png');

        $this->assertResponseIsSuccessful();
        $this->assertTrue($json['success']);
        $this->assertSame('/uploads/newsletter/', $json['data']['baseurl']);
        $this->assertMatchesRegularExpression('/^[0-9a-f]{24}\.png$/', $json['data']['files'][0]);
        $this->assertFileExists(static::getContainer()->getParameter('newsletter_upload_directory').'/'.$json['data']['files'][0]);
    }

    public function testUploadRejectsNonImagesAndBadToken(): void
    {
        $this->login($this->admin);

        // Extension d'image, mais contenu PHP
        $json = $this->upload(__DIR__.'/../../src/Kernel.php', 'image.png');
        $this->assertResponseStatusCodeSame(400);
        $this->assertFalse($json['success']);

        $json = $this->upload(__DIR__.'/../../public/dist/img/lapallogo.png', 'logo.png', 'faux');
        $this->assertResponseStatusCodeSame(403);
        $this->assertFalse($json['success']);
    }

    public function testUploadReservedToAdmins(): void
    {
        $this->login($this->createUser());
        $this->client->request('POST', '/newsletter/upload');

        $this->assertResponseStatusCodeSame(403);
    }

    public function testEmailHasLogoAndAbsoluteImageUrls(): void
    {
        $directory = static::getContainer()->getParameter('logo_directory');
        @mkdir($directory, 0777, true);
        copy(__DIR__.'/../../public/dist/img/lapallogo.png', $directory.'/logo.png');
        $newsletter = new Newsletter();
        $newsletter->setSubject('Avec image');
        $newsletter->setContent('<p><img src="/uploads/newsletter/photo.jpg"></p>');
        $this->persist($newsletter);

        try {
            $this->login($this->admin);
            $this->submitFormWithAction('/newsletter/'.$newsletter->getId(), '/newsletter/'.$newsletter->getId().'/test');

            $email = $this->getMailerMessage();
            $this->assertStringContainsString('src="http://localhost/uploads/newsletter/photo.jpg"', $email->getHtmlBody());
            $this->assertStringContainsString('<img src="cid:', $email->getHtmlBody());
            $this->assertCount(1, $email->getAttachments());
        } finally {
            unlink($directory.'/logo.png');
        }
    }
}
