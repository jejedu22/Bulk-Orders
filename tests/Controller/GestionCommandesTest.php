<?php

namespace App\Tests\Controller;

use App\Entity\Commande;
use App\Entity\LigneCommande;
use App\Tests\WebTestCase;

/**
 * Suivi des commandes par l'administrateur : validation du paiement,
 * livraison, récapitulatif et export (DefaultController).
 */
class GestionCommandesTest extends WebTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->login($this->createAdmin());
    }

    public function testAllOrdersPageListsEveryOrder(): void
    {
        $farine = $this->createProduct('Farine', 5);
        $jour = $this->createJourDistrib([$farine]);
        $this->createCommande($this->createUser('jean@example.com', [], 'Martin', 'Jean'), $jour, [[$farine, 2]]);

        $this->client->request('GET', '/synthese/2');

        $this->assertResponseIsSuccessful();
        $this->assertStringContainsString('Martin', $this->client->getResponse()->getContent());
    }

    /**
     * « Suivi de livraison » : les commandes restant à livrer, de tous les
     * clients (n'affichait auparavant que celles de l'administrateur connecté).
     */
    public function testDeliveryFollowUpListsUndeliveredOrdersOfAllUsers(): void
    {
        $farine = $this->createProduct('Farine', 5);
        $jour = $this->createJourDistrib([$farine]);
        $this->createCommande($this->createUser('jean@example.com', [], 'Martin', 'Jean'), $jour, [[$farine, 2]], true);
        $this->createCommande($this->createUser('paul@example.com', [], 'Durand', 'Paul'), $jour, [[$farine, 1]]);
        $this->createCommande($this->createUser('luc@example.com', [], 'Bernard', 'Luc'), $jour, [[$farine, 1]], true, true);

        $this->client->request('GET', '/synthese/1');

        $this->assertResponseIsSuccessful();
        $content = $this->client->getResponse()->getContent();
        $this->assertStringContainsString('Jean Martin', $content);
        $this->assertStringContainsString('Paul Durand', $content);
        // Déjà livrée
        $this->assertStringNotContainsString('Luc Bernard', $content);
    }

    public function testConfirmPayment(): void
    {
        $farine = $this->createProduct('Farine', 5);
        $commande = $this->createCommande($this->createUser(), $this->createJourDistrib([$farine]), [[$farine, 2]]);

        $this->client->request('GET', '/confirme/'.$commande->getId());

        $this->assertRedirectsTo('/synthese/2');
        $this->assertTrue($this->reload(Commande::class, $commande->getId())->getConfirmed());
        $this->assertEmailCount(1);
        $email = $this->getMailerMessage(0);
        $this->assertEmailHeaderSame($email, 'To', 'user@example.com');
        $this->assertEmailHtmlBodyContains($email, 'Votre commande est confirmée.');
    }

    public function testDeliveryPagesListConfirmedOrders(): void
    {
        $farine = $this->createProduct('Farine', 5);
        $riz = $this->createProduct('Riz', 2);
        $jour = $this->createJourDistrib([$farine, $riz]);
        $this->createCommande($this->createUser('jean@example.com', [], 'Martin', 'Jean'), $jour, [[$farine, 2]], true);
        // Non confirmée : n'apparaît pas à la livraison
        $this->createCommande($this->createUser('paul@example.com', [], 'Durand', 'Paul'), $jour, [[$riz, 1]]);

        $this->client->request('GET', '/livraison/command');
        $this->assertResponseIsSuccessful();
        $content = $this->client->getResponse()->getContent();
        $this->assertStringContainsString('Martin', $content);
        $this->assertStringNotContainsString('Durand', $content);

        $this->client->request('GET', '/livraison/product');
        $this->assertResponseIsSuccessful();
        $content = $this->client->getResponse()->getContent();
        $this->assertStringContainsString('Farine', $content);
        $this->assertStringNotContainsString('Riz', $content);
    }

    public function testDeliverWholeOrder(): void
    {
        $farine = $this->createProduct('Farine', 5);
        $riz = $this->createProduct('Riz', 2);
        $commande = $this->createCommande($this->createUser(), $this->createJourDistrib([$farine, $riz]), [[$farine, 2], [$riz, 1]], true);

        $this->client->request('GET', '/livree/'.$commande->getId());

        $this->assertRedirectsTo('/livraison/command');
        $commande = $this->reload(Commande::class, $commande->getId());
        $this->assertTrue($commande->getLivree());
        foreach ($commande->getLigneCommandes() as $ligne) {
            $this->assertTrue($ligne->getLivree());
        }
    }

    public function testDeliverOrderLineByLine(): void
    {
        $farine = $this->createProduct('Farine', 5);
        $riz = $this->createProduct('Riz', 2);
        $commande = $this->createCommande($this->createUser(), $this->createJourDistrib([$farine, $riz]), [[$farine, 2], [$riz, 1]], true);
        [$ligneFarine, $ligneRiz] = $commande->getLigneCommandes()->toArray();

        $this->client->request('GET', '/livree/ligne/'.$ligneFarine->getId());
        $this->assertRedirectsTo('/livraison/product');
        $this->assertTrue($this->reload(LigneCommande::class, $ligneFarine->getId())->getLivree());
        // Une ligne reste à remettre : la commande n'est pas livrée
        $this->assertFalse($this->reload(Commande::class, $commande->getId())->getLivree());

        $this->client->request('GET', '/livree/ligne/'.$ligneRiz->getId());
        $this->assertTrue($this->reload(Commande::class, $commande->getId())->getLivree());
    }

    public function testRecapSumsConfirmedQuantitiesByProduct(): void
    {
        $farine = $this->createProduct('Farine', 5, 10, $this->createCategory('Céréales'));
        $riz = $this->createProduct('Riz', 2);
        $sucre = $this->createProduct('Sucre', 1);
        $jour = $this->createJourDistrib([$farine, $riz, $sucre]);
        $this->createCommande($this->createUser('a@example.com'), $jour, [[$farine, 2], [$riz, 1]], true);
        $this->createCommande($this->createUser('b@example.com'), $jour, [[$farine, 3]], true);
        // Non confirmée : exclue du récapitulatif
        $this->createCommande($this->createUser('c@example.com'), $jour, [[$sucre, 4]]);

        $this->client->request('GET', '/recap');

        $this->assertResponseIsSuccessful();
        $content = $this->client->getResponse()->getContent();
        $this->assertStringContainsString('Farine', $content);
        $this->assertStringContainsString('Riz', $content);
        $this->assertStringNotContainsString('Sucre', $content);

        // Requête du récapitulatif : 5 farines (catégorie d'abord), 1 riz
        $recap = $this->em()->getRepository(\App\Entity\JourDistrib::class)->recap($jour);
        $this->assertSame(['Farine', 'Riz'], array_column($recap, 'nom'));
        $this->assertEquals([5, 1], array_column($recap, 'total'));
        $this->assertSame('Céréales', $recap[0]['categoryNom']);
    }

    public function testExportCsv(): void
    {
        $farine = $this->createProduct('Farine', 5, 10.5);
        $jour = $this->createJourDistrib([$farine]);
        $commande = $this->createCommande($this->createUser('jean@example.com', [], 'Martin', 'Jean'), $jour, [[$farine, 2]], true);
        $commande->setCommentaire('Le matin');
        $this->persist($commande);

        $this->client->request('GET', '/exportcsv/'.$jour->getId());

        $this->assertResponseIsSuccessful();
        $response = $this->client->getResponse();
        $this->assertStringStartsWith('text/csv', $response->headers->get('Content-Type'));
        $this->assertStringContainsString('export_commandes.csv', $response->headers->get('Content-Disposition'));

        $lines = array_map('str_getcsv', array_filter(explode("\n", $response->getContent())));
        $this->assertSame(['id', 'nom', 'prenom', 'commentaire', 'produit', 'conditionnement', 'unite', 'quantitee', 'prix_initial', 'prix_final', 'livre', 'valid'], $lines[0]);
        $this->assertCount(2, $lines);
        $this->assertSame((string) $commande->getId(), $lines[1][0]);
        $this->assertSame(['Martin', 'Jean', 'Le matin', 'Farine', '5', 'kg', '2', '10,50', '0,00'], array_slice($lines[1], 1, 9));
    }
}
