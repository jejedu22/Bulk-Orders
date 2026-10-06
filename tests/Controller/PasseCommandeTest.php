<?php

namespace App\Tests\Controller;

use App\Entity\Commande;
use App\Entity\JourDistrib;
use App\Tests\WebTestCase;

/**
 * Prise de commande par un utilisateur (DefaultController::index et ::new).
 */
class PasseCommandeTest extends WebTestCase
{
    public function testHomeListsOpenDistributionDays(): void
    {
        $farine = $this->createProduct('Farine');
        $jour = $this->createJourDistrib([$farine]);

        $this->login($this->createUser());
        $crawler = $this->client->request('GET', '/');

        $this->assertResponseIsSuccessful();
        $this->assertCount(1, $crawler->filter(sprintf('a[href="/new/%d"]', $jour->getId())));
    }

    public function testPastDistributionDaysAreNotListed(): void
    {
        $this->createJourDistrib([$this->createProduct()], 0, false, false, '-10 days');

        $crawler = $this->client->request('GET', '/');

        $this->assertResponseIsSuccessful();
        $this->assertCount(0, $crawler->filter('a[href^="/new/"]'));
    }

    public function testOrderFormDisplaysProductsOfTheDay(): void
    {
        $farine = $this->createProduct('Farine');
        $this->createProduct('Riz');
        $jour = $this->createJourDistrib([$farine]);

        $this->login($this->createUser());
        $this->client->request('GET', '/new/'.$jour->getId());

        $this->assertResponseIsSuccessful();
        $content = $this->client->getResponse()->getContent();
        $this->assertStringContainsString('Farine', $content);
        $this->assertStringNotContainsString('Riz', $content);
    }

    public function testPlaceOrder(): void
    {
        $farine = $this->createProduct('Farine', 5);
        $riz = $this->createProduct('Riz', 2);
        $jour = $this->createJourDistrib([$farine, $riz], 100);
        $user = $this->createUser();

        $this->login($user);
        $this->submitOrder($jour, [[$farine->getId(), 2], [$riz->getId(), 3]], 'Merci !');

        $this->assertRedirectsTo('/commande/');
        $this->assertEmailCount(1);
        $email = $this->getMailerMessage(0);
        $this->assertEmailHeaderSame($email, 'To', 'user@example.com');
        $this->assertEmailHtmlBodyContains($email, 'Votre commande est enregistrée.');
        $this->assertEmailHtmlBodyContains($email, 'Farine');

        /** @var Commande[] $commandes */
        $commandes = $this->em()->getRepository(Commande::class)->findAll();
        $this->assertCount(1, $commandes);
        $commande = $commandes[0];
        $this->assertSame($user->getId(), $commande->getUser()->getId());
        $this->assertSame('Merci !', $commande->getCommentaire());
        $this->assertFalse($commande->getConfirmed());
        $this->assertFalse($commande->getLivree());
        $this->assertCount(2, $commande->getLigneCommandes());
        // Poids commandé : 2 x 5 kg + 3 x 2 kg
        $this->assertEquals(16, $commande->getJourDistrib()->getPoidRestant());

        $this->client->followRedirect();
        $this->assertResponseIsSuccessful();
        $this->assertSelectorTextContains('.alert-success', 'Votre commande est enregistrée.');
    }

    public function testOrderOnUnlimitedDay(): void
    {
        $farine = $this->createProduct('Farine', 5);
        // total = 0 : pas de limite de poids
        $jour = $this->createJourDistrib([$farine], 0);

        $this->login($this->createUser());
        $this->submitOrder($jour, [[$farine->getId(), 50]]);

        $this->assertRedirectsTo('/commande/');
        $this->assertEquals(250, $this->reload(JourDistrib::class, $jour->getId())->getPoidRestant());
    }

    public function testOrderExceedingRemainingWeightIsRefused(): void
    {
        $farine = $this->createProduct('Farine', 5);
        $jour = $this->createJourDistrib([$farine], 20);

        $this->login($this->createUser());
        $this->submitOrder($jour, [[$farine->getId(), 5]]);

        $this->assertRedirectsTo('/');
        $this->assertCount(0, $this->em()->getRepository(Commande::class)->findAll());
        $this->assertEmailCount(0);
        $this->client->followRedirect();
        $this->assertSelectorExists('.alert-warning');
    }

    public function testSecondOrderOnLimitedDayIsRefused(): void
    {
        $farine = $this->createProduct('Farine', 5);
        $jour = $this->createJourDistrib([$farine], 0, true);
        $user = $this->createUser();
        $this->createCommande($user, $jour, [[$farine, 1]]);

        $this->login($user);
        $this->submitOrder($jour, [[$farine->getId(), 1]]);

        $this->assertRedirectsTo('/');
        $this->assertCount(1, $this->em()->getRepository(Commande::class)->findAll());
    }

    public function testSecondOrderOnUnlimitedDayIsAccepted(): void
    {
        $farine = $this->createProduct('Farine', 5);
        $jour = $this->createJourDistrib([$farine], 0, false);
        $user = $this->createUser();
        $this->createCommande($user, $jour, [[$farine, 1]]);

        $this->login($user);
        $this->submitOrder($jour, [[$farine->getId(), 1]]);

        $this->assertRedirectsTo('/commande/');
        $this->assertCount(2, $this->em()->getRepository(Commande::class)->findAll());
    }

    public function testClosedDayRefusesOrders(): void
    {
        $jour = $this->createJourDistrib([$this->createProduct()], 0, false, true);

        $this->login($this->createUser());
        $this->client->request('GET', '/new/'.$jour->getId());

        $this->assertRedirectsTo('/');
        $this->client->followRedirect();
        $this->assertSelectorExists('.alert-warning');
    }

    public function testOrderIsSavedEvenIfEmailCannotBeSent(): void
    {
        // Aucun expéditeur : ni MAILER_FROM ni e-mail de contact
        $this->em()->getConnection()->update('settings', ['value' => ''], ['name' => 'contact_email']);
        $farine = $this->createProduct('Farine', 5);
        $jour = $this->createJourDistrib([$farine]);

        $this->login($this->createUser());
        $this->submitOrder($jour, [[$farine->getId(), 1]]);

        $this->assertRedirectsTo('/commande/');
        $this->assertCount(1, $this->em()->getRepository(Commande::class)->findAll());
        $this->client->followRedirect();
        $this->assertSelectorTextContains('.alert-warning', 'user@example.com');
    }

    /**
     * Soumet le formulaire de commande comme le fait le navigateur (les lignes
     * sont ajoutées en JavaScript à partir du prototype de la collection).
     *
     * @param array<array{0: int, 1: int}> $lignes [id produit, quantité]
     */
    private function submitOrder(JourDistrib $jour, array $lignes, string $commentaire = ''): void
    {
        $crawler = $this->client->request('GET', '/new/'.$jour->getId());
        $this->assertResponseIsSuccessful();
        $token = $crawler->filter('input[name="commande[_token]"]')->attr('value');

        $ligneCommandes = [];
        foreach ($lignes as [$productId, $quantite]) {
            $ligneCommandes[] = ['product' => $productId, 'quantite' => $quantite, 'livree' => 0];
        }

        $this->client->request('POST', '/new/'.$jour->getId(), [
            'commande' => [
                'jourDistrib' => $jour->getId(),
                'ligneCommandes' => $ligneCommandes,
                'commentaire' => $commentaire,
                'livree' => 0,
                'confirmed' => 0,
                '_token' => $token,
            ],
        ]);
    }
}
