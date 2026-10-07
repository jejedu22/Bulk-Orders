<?php

namespace App\Tests\Controller;

use App\Entity\User;
use App\Tests\WebTestCase;

class ResetPasswordControllerTest extends WebTestCase
{
    public function testFullResetPasswordFlow(): void
    {
        $user = $this->createUser();

        $this->requestReset('user@example.com');

        $this->assertRedirectsTo('/reset-password/check-email');
        $this->assertEmailCount(1);
        $email = $this->getMailerMessage(0);
        $this->assertEmailHeaderSame($email, 'To', 'user@example.com');
        $this->client->followRedirect();
        $this->assertResponseIsSuccessful();

        // E-mail habillé : bouton et lien de secours
        $this->assertStringContainsString('Choisir un nouveau mot de passe', $email->getHtmlBody());
        $this->assertStringContainsString('Ce lien est valable 1 heure', $email->getHtmlBody());

        // Lien reçu par e-mail
        $this->assertSame(1, preg_match('#/reset-password/reset/[A-Za-z0-9]+#', $email->getHtmlBody(), $match), 'Lien de réinitialisation absent de l\'e-mail.');
        $this->client->request('GET', $match[0]);
        // Le jeton est retiré de l'URL et stocké en session
        $this->assertRedirectsTo('/reset-password/reset');
        $crawler = $this->client->followRedirect();

        $this->client->submit($crawler->filter('form[name="change_password_form"]')->form([
            'change_password_form[plainPassword][first]' => 'nouveaumotdepasse',
            'change_password_form[plainPassword][second]' => 'nouveaumotdepasse',
        ]));
        $this->assertRedirectsTo('/');

        // Ancien mot de passe refusé, nouveau accepté
        $this->client->request('GET', '/logout');
        $this->login($this->reload(User::class, $user->getId()));
        $this->assertRedirectsTo('/login');
        $this->login($user, 'nouveaumotdepasse');
        $this->assertRedirectsTo('/');
    }

    public function testUnknownEmailDoesNotRevealAnything(): void
    {
        $this->requestReset('inconnu@example.com');

        $this->assertRedirectsTo('/reset-password/check-email');
        $this->assertEmailCount(0);
    }

    public function testCheckEmailPageRequiresARequest(): void
    {
        $this->client->request('GET', '/reset-password/check-email');

        $this->assertRedirectsTo('/reset-password');
    }

    public function testInvalidTokenIsRefused(): void
    {
        $this->client->request('GET', '/reset-password/reset/jetoninvalide');
        $this->client->followRedirect();

        $this->assertRedirectsTo('/reset-password');
    }

    private function requestReset(string $mail): void
    {
        $crawler = $this->client->request('GET', '/reset-password');
        $this->client->submit($crawler->filter('form[name="reset_password_request_form"]')->form([
            'reset_password_request_form[mail]' => $mail,
        ]));
    }
}
