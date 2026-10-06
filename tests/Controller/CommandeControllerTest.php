<?php

namespace App\Tests\Controller;

use App\Entity\Commande;
use App\Entity\JourDistrib;
use App\Entity\LigneCommande;
use App\Tests\WebTestCase;

/**
 * Consultation et annulation de sa commande par l'utilisateur.
 */
class CommandeControllerTest extends WebTestCase
{
    public function testShowsLastOrderOfTheUser(): void
    {
        $farine = $this->createProduct('Farine', 5);
        $riz = $this->createProduct('Riz', 2);
        $jour = $this->createJourDistrib([$farine, $riz]);
        $user = $this->createUser();
        $this->createCommande($user, $jour, [[$farine, 1]]);
        $this->createCommande($user, $jour, [[$riz, 3]]);
        $this->createCommande($this->createUser('autre@example.com'), $jour, [[$farine, 7]]);

        $this->login($user);
        $this->client->request('GET', '/commande/');

        $this->assertResponseIsSuccessful();
        $content = $this->client->getResponse()->getContent();
        $this->assertStringContainsString('Riz', $content);
        $this->assertStringNotContainsString('Farine', $content);
    }

    public function testRedirectsToHomeWithoutOrder(): void
    {
        $this->login($this->createUser());

        $this->client->request('GET', '/commande/');

        $this->assertRedirectsTo('/');
    }

    public function testCancelOrderReleasesWeight(): void
    {
        $farine = $this->createProduct('Farine', 5);
        $jour = $this->createJourDistrib([$farine], 100);
        $user = $this->createUser();
        $this->createCommande($user, $this->createJourDistrib([$farine], 100), [[$farine, 1]]);
        $commande = $this->createCommande($user, $jour, [[$farine, 2]]);
        $this->assertEquals(10, $jour->getPoidRestant());

        $this->login($user);
        $this->submitFormWithAction('/commande/', '/commande/'.$commande->getId());

        $this->assertRedirectsTo('/');
        $this->assertNull($this->reload(Commande::class, $commande->getId()));
        $this->assertCount(0, $this->em()->getRepository(LigneCommande::class)->findBy(['commande' => $commande->getId()]));
        $this->assertEquals(0, $this->reload(JourDistrib::class, $jour->getId())->getPoidRestant());
    }

    public function testConfirmedOrderCannotBeCancelledFromThePage(): void
    {
        $farine = $this->createProduct('Farine', 5);
        $user = $this->createUser();
        $commande = $this->createCommande($user, $this->createJourDistrib([$farine]), [[$farine, 2]], true);

        $this->login($user);
        $crawler = $this->client->request('GET', '/commande/');

        $this->assertResponseIsSuccessful();
        $this->assertCount(0, $crawler->filter(sprintf('form[action="/commande/%d"]', $commande->getId())));
    }

    public function testCancelWithInvalidTokenDoesNothing(): void
    {
        $farine = $this->createProduct('Farine', 5);
        $user = $this->createUser();
        $commande = $this->createCommande($user, $this->createJourDistrib([$farine]), [[$farine, 2]]);

        $this->login($user);
        $this->client->request('DELETE', '/commande/'.$commande->getId(), ['_token' => 'invalide']);

        $this->assertRedirectsTo('/');
        $this->assertNotNull($this->reload(Commande::class, $commande->getId()));
    }

    public function testRemoveOrderLineReleasesWeight(): void
    {
        $farine = $this->createProduct('Farine', 5);
        $riz = $this->createProduct('Riz', 2);
        $jour = $this->createJourDistrib([$farine, $riz], 100);
        $user = $this->createUser();
        $commande = $this->createCommande($user, $jour, [[$farine, 2], [$riz, 1]]);
        $this->assertEquals(12, $jour->getPoidRestant());
        $ligneRiz = $commande->getLigneCommandes()->last();

        $this->login($user);
        $this->submitFormWithAction('/commande/', '/ligne/commande/'.$ligneRiz->getId());

        $this->assertRedirectsTo('/commande/');
        $this->assertNull($this->reload(LigneCommande::class, $ligneRiz->getId()));
        $this->assertCount(1, $this->reload(Commande::class, $commande->getId())->getLigneCommandes());
        $this->assertEquals(10, $this->reload(JourDistrib::class, $jour->getId())->getPoidRestant());
    }

    public function testOrderHistory(): void
    {
        $farine = $this->createProduct('Farine', 5);
        $user = $this->createUser();
        $this->createCommande($user, $this->createJourDistrib([$farine]), [[$farine, 2]]);

        $this->login($user);
        $this->client->request('GET', '/synthese/0');

        $this->assertResponseIsSuccessful();
        $this->assertStringContainsString('Farine', $this->client->getResponse()->getContent());
    }
}
