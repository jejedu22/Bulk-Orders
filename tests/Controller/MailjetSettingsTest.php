<?php

namespace App\Tests\Controller;

use App\Service\Mailjet;
use App\Tests\WebTestCase;

/**
 * Configuration Mailjet dans l'administration (Paramètres → Mailjet).
 */
class MailjetSettingsTest extends WebTestCase
{
    public function testReservedToAdmins(): void
    {
        $this->login($this->createUser());
        $this->client->request('GET', '/settings/mailjet');

        $this->assertResponseStatusCodeSame(403);
    }

    public function testSaveKeysAndKeepSecretWhenLeftBlank(): void
    {
        $this->login($this->createAdmin());

        $crawler = $this->client->request('GET', '/settings/mailjet');
        $this->assertSelectorTextContains('.page-head', 'Non configuré');
        $this->client->submit($crawler->filter('form[name="mailjet"]')->form([
            'mailjet[apiKey]' => ' cle-publique ',
            'mailjet[secretKey]' => 'cle-secrete',
            'mailjet[sender]' => 'news@example.com',
        ]));
        $this->assertRedirectsTo('/settings/mailjet');
        $this->assertSame('cle-publique', $this->setting(Mailjet::API_KEY));
        $this->assertSame('cle-secrete', $this->setting(Mailjet::SECRET_KEY));
        $this->assertSame('news@example.com', $this->setting(Mailjet::SENDER));

        // La clé secrète n'est jamais réaffichée ; vide = conservée
        $crawler = $this->client->request('GET', '/settings/mailjet');
        $this->assertSelectorTextContains('.page-head', 'Configuré');
        $this->assertStringNotContainsString('cle-secrete', $this->client->getResponse()->getContent());
        $this->client->submit($crawler->filter('form[name="mailjet"]')->form([
            'mailjet[apiKey]' => 'autre-cle',
        ]));
        $this->assertSame('autre-cle', $this->setting(Mailjet::API_KEY));
        $this->assertSame('cle-secrete', $this->setting(Mailjet::SECRET_KEY));

        // Ni la clé secrète ni les réglages Mailjet n'apparaissent dans la configuration générale
        $this->client->request('GET', '/settings/');
        $this->assertStringNotContainsString('mailjet_', $this->client->getResponse()->getContent());
        $this->client->request('GET', '/newsletter/');
        $this->assertSelectorTextContains('.subtitle', 'Mailjet configuré');
    }

    public function testEmptyApiKeyRemovesConfiguration(): void
    {
        $this->login($this->createAdmin());
        $crawler = $this->client->request('GET', '/settings/mailjet');
        $this->client->submit($crawler->filter('form[name="mailjet"]')->form([
            'mailjet[apiKey]' => 'cle',
            'mailjet[secretKey]' => 'secret',
        ]));

        $crawler = $this->client->request('GET', '/settings/mailjet');
        $this->client->submit($crawler->filter('form[name="mailjet"]')->form([
            'mailjet[apiKey]' => '',
        ]));

        $this->assertSame('', $this->setting(Mailjet::API_KEY));
        $this->assertSame('', $this->setting(Mailjet::SECRET_KEY));
    }

    public function testCheckWithoutKeysDoesNotSave(): void
    {
        $this->login($this->createAdmin());
        $crawler = $this->client->request('GET', '/settings/mailjet');
        $this->client->submit($crawler->selectButton('Vérifier la connexion')->form([
            'mailjet[apiKey]' => 'cle',
        ]));

        $this->assertResponseIsSuccessful();
        $this->assertSelectorTextContains('.text-danger, .rows', 'Renseignez la clé API et la clé secrète');
        $this->assertNull($this->setting(Mailjet::API_KEY));
    }

    public function testInvalidSender(): void
    {
        $this->login($this->createAdmin());
        $crawler = $this->client->request('GET', '/settings/mailjet');
        $this->client->submit($crawler->filter('form[name="mailjet"]')->form([
            'mailjet[sender]' => 'pas-une-adresse',
        ]));

        $this->assertResponseIsSuccessful();
        $this->assertSelectorExists('.is-invalid, .invalid-feedback, .form-error-message');
        $this->assertNull($this->setting(Mailjet::SENDER));
    }
}
