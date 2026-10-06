<?php

namespace App\Tests\Controller;

use App\Tests\WebTestCase;

/**
 * Règles d'accès (security.yaml, access_control) : les pages
 * d'administration sont réservées à ROLE_ADMIN.
 */
class AccessControlTest extends WebTestCase
{
    /** @var \App\Entity\Commande */
    private $commande;

    public static function adminUrls(): iterable
    {
        yield 'produits' => ['/product/'];
        yield 'nouveau produit' => ['/product/new'];
        yield 'catégories' => ['/category/'];
        yield 'nouvelle catégorie' => ['/category/new'];
        yield 'jours de distribution' => ['/jour/distrib/'];
        yield 'nouveau jour de distribution' => ['/jour/distrib/new'];
        yield 'suivi des commandes' => ['/synthese/1'];
        yield 'validation des commandes' => ['/synthese/2'];
        yield 'configuration' => ['/settings/'];
        yield 'utilisateurs' => ['/user/'];
        yield 'livraison par commande' => ['/livraison/command'];
        yield 'livraison par produit' => ['/livraison/product'];
        yield 'récapitulatif' => ['/recap'];
    }

    /**
     * @dataProvider adminUrls
     */
    public function testAnonymousIsRedirectedToLogin(string $url): void
    {
        $this->client->request('GET', $url);

        $this->assertRedirectsTo('/login');
    }

    /**
     * @dataProvider adminUrls
     */
    public function testUserIsForbidden(string $url): void
    {
        $this->login($this->createUser());

        $this->client->request('GET', $url);

        $this->assertResponseStatusCodeSame(403);
    }

    /**
     * @dataProvider adminUrls
     */
    public function testAdminCanAccess(string $url): void
    {
        $this->login($this->createAdmin());

        $this->client->request('GET', $url);

        $this->assertResponseIsSuccessful();
    }

    public static function publicUrls(): iterable
    {
        yield 'accueil' => ['/'];
        yield 'connexion' => ['/login'];
        yield 'inscription' => ['/register'];
        yield 'mot de passe oublié' => ['/reset-password'];
    }

    /**
     * @dataProvider publicUrls
     */
    public function testPublicPagesAreAccessibleAnonymously(string $url): void
    {
        $this->client->request('GET', $url);

        $this->assertResponseIsSuccessful();
    }

    /**
     * Actions d'administration qui modifient des données ou exposent des
     * données personnelles : testées sur des entités existantes.
     */
    public static function adminActions(): iterable
    {
        yield 'validation du paiement' => ['/confirme/%commande%'];
        yield 'export CSV' => ['/exportcsv/%jour%'];
        yield 'remise d\'une commande' => ['/livree/%commande%'];
        yield 'remise d\'une ligne' => ['/livree/ligne/%ligne%'];
    }

    /**
     * @dataProvider adminActions
     */
    public function testAdminActionsAreRefusedToAnonymous(string $url): void
    {
        $url = $this->createOrderAndResolve($url);

        $this->client->request('GET', $url);

        $this->assertRedirectsTo('/login');
        $this->assertOrderUntouched();
    }

    /**
     * @dataProvider adminActions
     */
    public function testAdminActionsAreForbiddenToUsers(string $url): void
    {
        $url = $this->createOrderAndResolve($url);
        $this->login($this->createUser('autre@example.com'));

        $this->client->request('GET', $url);

        $this->assertResponseStatusCodeSame(403);
        $this->assertOrderUntouched();
    }

    public static function userUrls(): iterable
    {
        yield 'historique' => ['/synthese/0'];
        yield 'ma commande' => ['/commande/'];
        yield 'passer commande' => ['/new/1'];
    }

    /**
     * @dataProvider userUrls
     */
    public function testUserPagesRequireLogin(string $url): void
    {
        $this->createJourDistrib([$this->createProduct()]);

        $this->client->request('GET', $url);

        $this->assertRedirectsTo('/login');
    }

    public static function removedUrls(): iterable
    {
        yield 'contact' => ['GET', '/contact'];
        yield 'ancienne prise de commande' => ['GET', '/commande/new'];
        yield 'création d\'utilisateur' => ['GET', '/user/new'];
        yield 'liste des lignes de commande' => ['GET', '/ligne/commande/'];
        yield 'nouvelle ligne de commande' => ['GET', '/ligne/commande/new'];
        yield 'modification de ligne de commande' => ['GET', '/ligne/commande/1/edit'];
    }

    /**
     * Pages cassées ou générées par le maker et jamais utilisées, supprimées.
     *
     * @dataProvider removedUrls
     */
    public function testRemovedPagesDoNotExist(string $method, string $url): void
    {
        $this->login($this->createAdmin());

        $this->client->request($method, $url);

        $this->assertContains($this->client->getResponse()->getStatusCode(), [404, 405]);
    }

    public function testUserCanSeeOwnOrderHistory(): void
    {
        $this->login($this->createUser());

        $this->client->request('GET', '/synthese/0');

        $this->assertResponseIsSuccessful();
    }

    private function createOrderAndResolve(string $url): string
    {
        $farine = $this->createProduct();
        $jour = $this->createJourDistrib([$farine]);
        $this->commande = $this->createCommande($this->createUser(), $jour, [[$farine, 1]], false);

        return strtr($url, [
            '%commande%' => $this->commande->getId(),
            '%jour%' => $jour->getId(),
            '%ligne%' => $this->commande->getLigneCommandes()->first()->getId(),
        ]);
    }

    private function assertOrderUntouched(): void
    {
        $commande = $this->reload(\App\Entity\Commande::class, $this->commande->getId());
        $this->assertFalse($commande->getConfirmed());
        $this->assertFalse($commande->getLivree());
        $this->assertFalse($commande->getLigneCommandes()->first()->getLivree());
        $this->assertEmailCount(0);
    }
}
