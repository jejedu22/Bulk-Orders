<?php

namespace App\Tests\Controller;

use App\Entity\User;
use App\Tests\WebTestCase;

class RegistrationControllerTest extends WebTestCase
{
    public function testRegisterThenLogin(): void
    {
        $this->register([
            'registration_form[mail]' => 'nouveau@example.com',
            'registration_form[password]' => 'unmotdepasse',
            'registration_form[confirmPassword]' => 'unmotdepasse',
        ]);

        $this->assertRedirectsTo('/login');
        $user = $this->em()->getRepository(User::class)->findOneBy(['mail' => 'nouveau@example.com']);
        $this->assertNotNull($user);
        // L'identifiant de connexion est l'e-mail
        $this->assertSame('nouveau@example.com', $user->getUserIdentifier());
        $this->assertSame(['ROLE_USER'], $user->getRoles());
        $this->assertNotSame('unmotdepasse', $user->getPassword());

        $this->client->request('POST', '/login', ['username' => 'nouveau@example.com', 'password' => 'unmotdepasse']);
        $this->assertRedirectsTo('/');
    }

    public function testPasswordConfirmationMustMatch(): void
    {
        $this->register([
            'registration_form[password]' => 'unmotdepasse',
            'registration_form[confirmPassword]' => 'autrechose',
        ]);

        $this->assertResponseIsSuccessful();
        $this->assertCount(0, $this->em()->getRepository(User::class)->findAll());
    }

    public function testPasswordMustHaveAtLeastEightCharacters(): void
    {
        $this->register([
            'registration_form[password]' => 'court',
            'registration_form[confirmPassword]' => 'court',
        ]);

        $this->assertResponseIsSuccessful();
        $this->assertCount(0, $this->em()->getRepository(User::class)->findAll());
    }

    public function testEmailMustBeUnique(): void
    {
        $this->createUser('pris@example.com');

        $this->register(['registration_form[mail]' => 'pris@example.com']);

        $this->assertResponseIsSuccessful();
        $this->assertSelectorTextContains('body', 'L\'email renseigné est déjà utilisé.');
        $this->assertCount(1, $this->em()->getRepository(User::class)->findAll());
    }

    public function testTermsMustBeAccepted(): void
    {
        $this->register(['registration_form[agreeTerms]' => false]);

        $this->assertResponseIsSuccessful();
        $this->assertCount(0, $this->em()->getRepository(User::class)->findAll());
    }

    private function register(array $values): void
    {
        $crawler = $this->client->request('GET', '/register');
        $this->assertResponseIsSuccessful();

        $form = $crawler->filter('form[name="registration_form"]')->form();
        $values += [
            'registration_form[nom]' => 'Martin',
            'registration_form[prenom]' => 'Jean',
            'registration_form[mail]' => 'jean@example.com',
            'registration_form[phone]' => '0600000000',
            'registration_form[password]' => 'unmotdepasse',
            'registration_form[confirmPassword]' => 'unmotdepasse',
            'registration_form[agreeTerms]' => true,
        ];
        foreach ($values as $field => $value) {
            if (is_bool($value)) {
                $value ? $form[$field]->tick() : $form[$field]->untick();
            } else {
                $form[$field] = $value;
            }
        }
        $this->client->submit($form);
    }
}
