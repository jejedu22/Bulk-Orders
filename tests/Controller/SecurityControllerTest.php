<?php

namespace App\Tests\Controller;

use App\Tests\WebTestCase;

class SecurityControllerTest extends WebTestCase
{
    public function testLoginPageIsDisplayed(): void
    {
        $this->client->request('GET', '/login');

        $this->assertResponseIsSuccessful();
        $this->assertSelectorExists('input[name="username"]');
        $this->assertSelectorExists('input[name="password"]');
    }

    public function testLoginWithValidCredentialsRedirectsToHome(): void
    {
        $user = $this->createUser();

        $this->login($user);

        $this->assertRedirectsTo('/');
        $this->client->followRedirect();
        $this->assertResponseIsSuccessful();
        $this->assertSelectorTextContains('body', 'Jean Dupont');
    }

    public function testLoginRedirectsToTargetPathAfterAccessDenied(): void
    {
        $admin = $this->createAdmin();

        $this->client->request('GET', '/product/');
        $this->assertRedirectsTo('/login');

        $this->login($admin);
        $this->assertRedirectsTo('/product/');
    }

    public function testLoginWithWrongPasswordShowsError(): void
    {
        $user = $this->createUser();

        $this->login($user, 'mauvais-mot-de-passe');

        $this->assertRedirectsTo('/login');
        $this->client->followRedirect();
        $this->assertSelectorExists('.alert-danger');
        // Le dernier identifiant saisi est conservé
        $this->assertSelectorExists('input[name="username"][value="user@example.com"]');
    }

    public function testLoginWithUnknownUserShowsCustomMessage(): void
    {
        $this->client->request('POST', '/login', ['username' => 'inconnu@example.com', 'password' => 'x']);

        $this->client->followRedirect();
        $this->assertSelectorTextContains('.alert-danger', 'Utilisateur Inconnue !');
    }

    public function testLoggedInUserIsRedirectedAwayFromLoginPage(): void
    {
        $this->login($this->createUser());

        $this->client->request('GET', '/login');

        $this->assertRedirectsTo('/');
    }

    public function testLogout(): void
    {
        $this->login($this->createUser());

        $this->client->request('GET', '/logout');
        $this->assertRedirectsTo('/');

        $this->client->followRedirect();
        $this->assertResponseIsSuccessful();
        $this->assertSelectorNotExists('.avatar');
        $this->assertSelectorTextNotContains('body', 'Jean Dupont');
    }
}
