<?php

namespace App\Tests\Controller;

use App\Entity\Commande;
use App\Service\HelloAsso;
use App\Tests\WebTestCase;
use Symfony\Component\Mime\Email;

/**
 * Règlement en deux temps : validation (chèque d'engagement non encaissé),
 * puis paiement définitif une fois les prix définitifs connus (demande de
 * paiement par e-mail, chèque ou paiement en ligne).
 */
class DemandePaiementTest extends WebTestCase
{
    private function configureHelloAsso(): void
    {
        $connection = $this->em()->getConnection();
        foreach ([HelloAsso::ORGANIZATION => 'mon-asso', HelloAsso::CLIENT_ID => 'client', HelloAsso::CLIENT_SECRET => 'secret'] as $name => $value) {
            $connection->insert('settings', ['name' => $name, 'value' => $value]);
        }
    }

    private function requestPayments(int $jourId): void
    {
        $this->submitFormWithAction('/synthese/2', '/jour/distrib/' . $jourId . '/demande-paiement');
        $this->assertRedirectsTo('/synthese/2');
    }

    public function testValidateThenRecordPayment(): void
    {
        $product = $this->createProduct('Farine', 5, 10);
        $commande = $this->createCommande($this->createUser(), $this->createJourDistrib([$product]), [[$product, 1]]);
        $this->login($this->createAdmin());

        $this->client->request('GET', '/synthese/2');
        $this->assertSelectorTextContains('.row-item', 'En attente de validation');
        $this->assertSelectorTextContains('.panel-head', '1 à valider');

        // Chèque d'engagement reçu : commande validée, e-mail de confirmation
        $this->client->request('GET', '/confirme/' . $commande->getId());
        $this->assertEmailCount(1);
        $validated = $this->reload(Commande::class, $commande->getId());
        $this->assertTrue($validated->getconfirmed());
        $this->assertFalse($validated->getPayee());
        $this->client->request('GET', '/synthese/2');
        $this->assertSelectorTextContains('.row-item', 'Validée');
        $this->assertSelectorTextContains('.panel-head', '1 à payer');

        // Chèque définitif reçu
        $this->client->request('GET', '/confirme/' . $commande->getId() . '/paiement');
        $this->assertRedirectsTo('/synthese/2');
        $this->assertTrue($this->reload(Commande::class, $commande->getId())->getPayee());
        $this->client->request('GET', '/synthese/2');
        $this->assertSelectorTextContains('.row-item', 'Payée');
    }

    public function testRecordPaymentReservedToAdmins(): void
    {
        $product = $this->createProduct();
        $user = $this->createUser();
        $commande = $this->createCommande($user, $this->createJourDistrib([$product]), [[$product, 1]]);
        $this->login($user);

        $this->client->request('GET', '/confirme/' . $commande->getId() . '/paiement');
        $this->assertResponseStatusCodeSame(403);
    }

    public function testPaymentRequestsWithOnlinePayment(): void
    {
        $this->configureHelloAsso();
        $farine = $this->createProduct('Farine', 5, 10);
        $farine->setPrixFinal(9.5);
        $sucre = $this->createProduct('Sucre', 1, 3);
        $jour = $this->createJourDistrib([$farine, $sucre]);
        $alice = $this->createUser('alice@example.com', [], 'Martin', 'Alice');
        $bob = $this->createUser('bob@example.com', [], 'Durand', 'Bob');
        $carole = $this->createUser('carole@example.com', [], 'Petit', 'Carole');
        $aPayer = $this->createCommande($alice, $jour, [[$farine, 2]], true);
        $sansPrix = $this->createCommande($bob, $jour, [[$farine, 1], [$sucre, 1]]);
        $payee = $this->createCommande($carole, $jour, [[$farine, 1]], true);
        $payee->setPayee(true);
        $this->persist($payee);
        $this->login($this->createAdmin());

        $this->client->request('GET', '/synthese/2');
        $this->assertSelectorTextContains('.panel-head', 'Demander le paiement (1)');
        $this->assertSelectorTextContains('.panel-head', '1 sans prix définitif');

        $this->requestPayments($jour->getId());
        $this->assertEmailCount(1);
        /** @var Email $email */
        $email = $this->getMailerMessage();
        $this->assertSame('alice@example.com', $email->getTo()[0]->getAddress());
        $this->assertSame('Montant définitif de votre commande', $email->getSubject());
        $this->assertStringContainsString('19,00 €', $email->getHtmlBody());
        $this->assertStringContainsString('http://localhost/commande/' . $aPayer->getId() . '/paiement', $email->getHtmlBody());
        $this->assertStringContainsString('Payer en ligne', $email->getHtmlBody());
        $this->assertNotNull($this->reload(Commande::class, $aPayer->getId())->getPaiementDemandeLe());
        $this->assertNull($this->reload(Commande::class, $sansPrix->getId())->getPaiementDemandeLe());

        // Déjà envoyée : plus de bouton, pas de second e-mail
        $crawler = $this->client->request('GET', '/synthese/2');
        $this->assertCount(0, $crawler->filter('form[action="/jour/distrib/' . $jour->getId() . '/demande-paiement"]'));

        // Prix définitif du sucre saisi : la demande part pour Bob
        $sucre = $this->reload(\App\Entity\Product::class, $sucre->getId());
        $sucre->setPrixFinal(2.5);
        $this->em()->flush();
        $this->requestPayments($jour->getId());
        $this->assertEmailCount(1);
        $this->assertSame('bob@example.com', $this->getMailerMessage()->getTo()[0]->getAddress());
        $this->assertStringContainsString('12,00 €', $this->getMailerMessage()->getHtmlBody());
    }

    public function testPaymentRequestWithoutHelloAssoAsksForCheque(): void
    {
        $farine = $this->createProduct('Farine', 5, 10);
        $farine->setPrixFinal(11);
        $jour = $this->createJourDistrib([$farine]);
        $this->createCommande($this->createUser(), $jour, [[$farine, 1]], true);
        $this->login($this->createAdmin());

        $this->requestPayments($jour->getId());
        $this->assertEmailCount(1);
        $html = $this->getMailerMessage()->getHtmlBody();
        $this->assertStringContainsString('11,00 €', $html);
        $this->assertStringContainsString('remettre un chèque', $html);
        $this->assertStringNotContainsString('Payer en ligne', $html);
    }

    public function testPaymentRequestReservedToAdmins(): void
    {
        $jour = $this->createJourDistrib();
        $this->login($this->createUser());

        $this->client->request('POST', '/jour/distrib/' . $jour->getId() . '/demande-paiement');
        $this->assertResponseStatusCodeSame(403);
    }
}
