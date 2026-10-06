<?php

namespace App\Tests\Controller;

use App\Tests\WebTestCase;

/**
 * Règles d'accès (security.yaml, access_control) : les pages
 * d'administration sont réservées à ROLE_ADMIN.
 */
class AccessControlTest extends WebTestCase
{
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

    public function testUserCanSeeOwnOrderHistory(): void
    {
        $this->login($this->createUser());

        $this->client->request('GET', '/synthese/0');

        $this->assertResponseIsSuccessful();
    }
}
