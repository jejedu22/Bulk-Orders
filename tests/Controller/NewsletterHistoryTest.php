<?php

namespace App\Tests\Controller;

use App\Entity\Newsletter;
use App\Entity\NewsletterDelivery;
use App\Entity\User;
use App\Tests\WebTestCase;

/**
 * Historique des envois de newsletters par utilisateur.
 */
class NewsletterHistoryTest extends WebTestCase
{
    /** @var User */
    private $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = $this->createAdmin();
    }

    private function createNewsletter(string $subject): Newsletter
    {
        $newsletter = new Newsletter();
        $newsletter->setSubject($subject);
        $newsletter->setContent('<p>x</p>');
        $this->persist($newsletter);

        return $newsletter;
    }

    private function deliveries(): array
    {
        $em = $this->em();
        $em->clear();

        return $em->getRepository(NewsletterDelivery::class)->findBy([], ['id' => 'ASC']);
    }

    public function testSendingRecordsOneDeliveryPerRecipient(): void
    {
        $alice = $this->createUser('alice@example.com', [], 'Martin', 'Alice');
        $this->createUser('bob@example.com')->setNewsletter(false);
        $this->em()->flush();
        $newsletter = $this->createNewsletter('Vente de printemps');

        $this->login($this->admin);
        $this->submitFormWithAction('/newsletter/'.$newsletter->getId(), '/newsletter/'.$newsletter->getId().'/send');

        $deliveries = $this->deliveries();
        $this->assertCount(2, $deliveries);
        $emails = array_map(function (NewsletterDelivery $d) { return $d->getEmail(); }, $deliveries);
        sort($emails);
        $this->assertSame(['admin@example.com', 'alice@example.com'], $emails);
        foreach ($deliveries as $delivery) {
            $this->assertTrue($delivery->isSuccessful());
            $this->assertFalse($delivery->isTest());
            $this->assertSame($newsletter->getId(), $delivery->getNewsletter()->getId());
        }

        // Fiche de l'utilisateur
        $this->client->request('GET', '/user/'.$alice->getId());
        $this->assertSelectorTextContains('body', 'Newsletters reçues');
        $this->assertSelectorTextContains('body', 'Vente de printemps');

        // Page de la newsletter
        $this->client->request('GET', '/newsletter/'.$newsletter->getId());
        $this->assertSelectorTextContains('body', 'Envois');
        $this->assertSelectorTextContains('body', '2 réussis');
        $this->assertSelectorTextContains('body', 'Alice Martin');
    }

    public function testTestSendIsRecordedAsTest(): void
    {
        $newsletter = $this->createNewsletter('Brouillon');

        $this->login($this->admin);
        $this->submitFormWithAction('/newsletter/'.$newsletter->getId(), '/newsletter/'.$newsletter->getId().'/test');

        $deliveries = $this->deliveries();
        $this->assertCount(1, $deliveries);
        $this->assertTrue($deliveries[0]->isTest());
        $this->assertSame('admin@example.com', $deliveries[0]->getEmail());

        $this->client->request('GET', '/user/'.$this->admin->getId());
        $this->assertSelectorTextContains('.newsletter-history', 'Test');
    }

    public function testFailureIsShown(): void
    {
        $alice = $this->createUser('alice@example.com');
        $newsletter = $this->createNewsletter('Rejetée');
        $newsletter->markSent(1, 1);
        $this->persist(new NewsletterDelivery($newsletter, $alice, 'Adresse refusée par Mailjet'));

        $this->login($this->admin);
        $this->client->request('GET', '/user/'.$alice->getId());
        $this->assertSelectorTextContains('.newsletter-history', 'Adresse refusée par Mailjet');

        $this->client->request('GET', '/newsletter/'.$newsletter->getId());
        $this->assertSelectorTextContains('body', '1 échec');
    }

    public function testEmailAtSendTimeIsKept(): void
    {
        $alice = $this->createUser('alice@example.com');
        $newsletter = $this->createNewsletter('Ancienne adresse');
        $this->persist(new NewsletterDelivery($newsletter, $alice));
        $alice->setMail('alice@nouveau.org');
        $this->em()->flush();

        $this->login($this->admin);
        $this->client->request('GET', '/user/'.$alice->getId());
        $this->assertSelectorTextContains('.newsletter-history', 'à alice@example.com');
    }

    public function testNoHistory(): void
    {
        $alice = $this->createUser('alice@example.com');

        $this->login($this->admin);
        $this->client->request('GET', '/user/'.$alice->getId());

        $this->assertSelectorTextContains('body', 'Aucune newsletter envoyée à cet utilisateur.');
    }

    public function testDeletingNewsletterOrUserDeletesHistory(): void
    {
        $alice = $this->createUser('alice@example.com');
        $bob = $this->createUser('bob@example.com');
        $first = $this->createNewsletter('Première');
        $second = $this->createNewsletter('Seconde');
        $this->persist(new NewsletterDelivery($first, $alice));
        $this->persist(new NewsletterDelivery($second, $alice));
        $this->persist(new NewsletterDelivery($second, $bob));

        $this->login($this->admin);
        $this->submitFormWithAction('/newsletter/'.$first->getId(), '/newsletter/'.$first->getId());
        $this->assertCount(2, $this->deliveries());

        $this->submitFormWithAction('/user/'.$alice->getId(), '/user/'.$alice->getId());
        $deliveries = $this->deliveries();
        $this->assertCount(1, $deliveries);
        $this->assertSame('bob@example.com', $deliveries[0]->getEmail());
    }
}
