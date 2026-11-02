<?php

namespace App\Tests\Controller;

use App\Entity\Commande;
use App\Service\HelloAsso;
use App\Tests\HelloAssoHttpClient;
use App\Tests\WebTestCase;
use Symfony\Component\HttpClient\Response\MockResponse;

/**
 * Compte HelloAsso (Paramètres → HelloAsso) et paiement en ligne des commandes.
 */
class HelloAssoTest extends WebTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        HelloAssoHttpClient::reset();
    }

    protected function tearDown(): void
    {
        HelloAssoHttpClient::reset();
        parent::tearDown();
    }

    private function configure(): void
    {
        $connection = $this->em()->getConnection();
        foreach ([
            HelloAsso::ORGANIZATION => 'mon-asso',
            HelloAsso::CLIENT_ID => 'client',
            HelloAsso::CLIENT_SECRET => 'secret',
        ] as $name => $value) {
            $connection->insert('settings', ['name' => $name, 'value' => $value]);
        }
    }

    /**
     * Réponses de l'API HelloAsso : jeton, création du paiement et état du
     * paiement ($order = commande HelloAsso, null tant que non payé).
     */
    private function api(int $commandeId, ?array $order = null): void
    {
        HelloAssoHttpClient::$handler = function (string $method, string $url) use ($commandeId, $order) {
            if (str_ends_with($url, '/oauth2/token')) {
                return new MockResponse(json_encode(['access_token' => 'jeton', 'expires_in' => 1800]));
            }
            if ('POST' === $method && str_ends_with($url, '/v5/organizations/mon-asso/checkout-intents')) {
                return new MockResponse(json_encode(['id' => 4242, 'redirectUrl' => 'https://www.helloasso.com/paiement/4242']));
            }
            if ('GET' === $method && str_ends_with($url, '/v5/organizations/mon-asso/checkout-intents/4242')) {
                return new MockResponse(json_encode(array_filter([
                    'id' => 4242,
                    'metadata' => ['commande' => $commandeId],
                    'order' => $order,
                ])));
            }

            return new MockResponse('', ['http_code' => 404]);
        };
    }

    private function paidOrder(): array
    {
        return ['id' => 777, 'payments' => [['id' => 1, 'state' => 'Authorized', 'amount' => 2100]]];
    }

    /**
     * Commande de 2 × 10,50 € = 21 €.
     */
    private function commande(?\App\Entity\User $user = null): Commande
    {
        $product = $this->createProduct('Farine', 5, 10.5);
        $jour = $this->createJourDistrib([$product]);

        return $this->createCommande($user ?? $this->createUser(), $jour, [[$product, 2]]);
    }

    private function request(string $name): array
    {
        foreach (HelloAssoHttpClient::$requests as $request) {
            if ($request[0] . ' ' . $request[1] === $name) {
                return $request;
            }
        }
        $this->fail(sprintf('Requête « %s » non envoyée à HelloAsso.', $name));
    }

    public function testSettingsReservedToAdmins(): void
    {
        $this->login($this->createUser());
        $this->client->request('GET', '/settings/helloasso');

        $this->assertResponseStatusCodeSame(403);
    }

    public function testSaveAccountAndKeepSecretWhenLeftBlank(): void
    {
        $this->login($this->createAdmin());

        $crawler = $this->client->request('GET', '/settings/helloasso');
        $this->assertSelectorTextContains('.page-head', 'Non configuré');
        $this->assertStringContainsString('/helloasso/notification', $this->client->getResponse()->getContent());
        $this->client->submit($crawler->filter('form[name="hello_asso"]')->form([
            'hello_asso[organization]' => 'mon-asso',
            'hello_asso[clientId]' => ' client ',
            'hello_asso[clientSecret]' => 'secret-helloasso',
            'hello_asso[sandbox]' => true,
        ]));
        $this->assertRedirectsTo('/settings/helloasso');
        $this->assertSame('mon-asso', $this->setting(HelloAsso::ORGANIZATION));
        $this->assertSame('client', $this->setting(HelloAsso::CLIENT_ID));
        $this->assertSame('secret-helloasso', $this->setting(HelloAsso::CLIENT_SECRET));
        $this->assertSame('1', $this->setting(HelloAsso::SANDBOX));

        // La clé secrète n'est jamais réaffichée ; vide = conservée
        $crawler = $this->client->request('GET', '/settings/helloasso');
        $this->assertSelectorTextContains('.page-head', 'Configuré (test)');
        $this->assertStringNotContainsString('secret-helloasso', $this->client->getResponse()->getContent());
        $form = $crawler->filter('form[name="hello_asso"]')->form(['hello_asso[clientId]' => 'autre']);
        $form['hello_asso[sandbox]']->untick();
        $this->client->submit($form);
        $this->assertSame('autre', $this->setting(HelloAsso::CLIENT_ID));
        $this->assertSame('secret-helloasso', $this->setting(HelloAsso::CLIENT_SECRET));
        $this->assertSame('', (string) $this->setting(HelloAsso::SANDBOX));

        // Absents de la configuration générale
        $this->client->request('GET', '/settings/');
        $this->assertStringNotContainsString('helloasso_', $this->client->getResponse()->getContent());

        // Identifiant client vide : configuration supprimée
        $crawler = $this->client->request('GET', '/settings/helloasso');
        $this->client->submit($crawler->filter('form[name="hello_asso"]')->form(['hello_asso[clientId]' => '']));
        $this->assertSame('', (string) $this->setting(HelloAsso::CLIENT_SECRET));
    }

    public function testCheckConnection(): void
    {
        HelloAssoHttpClient::$handler = function (string $method, string $url) {
            if (str_ends_with($url, '/oauth2/token')) {
                return new MockResponse(json_encode(['access_token' => 'jeton']));
            }

            return new MockResponse(json_encode(['name' => 'Mon Association']));
        };
        $this->login($this->createAdmin());
        $crawler = $this->client->request('GET', '/settings/helloasso');
        $this->client->submit($crawler->selectButton('Vérifier la connexion')->form([
            'hello_asso[organization]' => 'mon-asso',
            'hello_asso[clientId]' => 'client',
            'hello_asso[clientSecret]' => 'secret',
            'hello_asso[sandbox]' => true,
        ]));

        $this->assertResponseIsSuccessful();
        $this->assertSelectorTextContains('.rows', 'Organisation trouvée : Mon Association');
        $this->assertSame('https://api.helloasso-sandbox.com/v5/organizations/mon-asso', HelloAssoHttpClient::$requests[1][1]);
        // Rien n'est enregistré
        $this->assertNull($this->setting(HelloAsso::CLIENT_ID));
    }

    public function testInvalidOrganization(): void
    {
        $this->login($this->createAdmin());
        $crawler = $this->client->request('GET', '/settings/helloasso');
        $this->client->submit($crawler->filter('form[name="hello_asso"]')->form([
            'hello_asso[organization]' => 'Mon Asso',
        ]));

        $this->assertResponseIsSuccessful();
        $this->assertNull($this->setting(HelloAsso::ORGANIZATION));
    }

    public function testNoPayButtonWithoutAccount(): void
    {
        $commande = $this->commande();
        $this->login($commande->getUser());

        $this->client->request('GET', '/commande/');
        $this->assertResponseIsSuccessful();
        $this->assertSelectorNotExists('form[action$="/paiement"]');

        // Requête forgée : refusée sans appeler HelloAsso
        $this->client->request('POST', '/commande/' . $commande->getId() . '/paiement');
        $this->assertRedirectsTo('/commande/');
        $this->assertSame([], HelloAssoHttpClient::$requests);
    }

    public function testPayOnlineThenReturnConfirmsOrder(): void
    {
        $this->configure();
        $commande = $this->commande();
        $this->api($commande->getId(), $this->paidOrder());
        $this->login($commande->getUser());

        $crawler = $this->client->request('GET', '/commande/');
        $this->client->submit($crawler->filter('form[action$="/paiement"]')->form());
        $this->assertResponseRedirects('https://www.helloasso.com/paiement/4242');

        [, , $options] = $this->request('POST https://api.helloasso.com/v5/organizations/mon-asso/checkout-intents');
        $body = json_decode($options['body'], true);
        $this->assertSame(2100, $body['totalAmount']);
        $this->assertSame(2100, $body['initialAmount']);
        $this->assertFalse($body['containsDonation']);
        $this->assertSame(['commande' => $commande->getId()], $body['metadata']);
        $this->assertSame('http://localhost/commande/' . $commande->getId() . '/paiement/retour', $body['returnUrl']);
        $this->assertSame('user@example.com', $body['payer']['email']);
        $this->assertSame(4242, $this->reload(Commande::class, $commande->getId())->getHelloassoCheckoutId());

        // Retour de HelloAsso : le paiement est relu auprès de l'API
        $this->client->request('GET', '/commande/' . $commande->getId() . '/paiement/retour?checkoutIntentId=4242&code=succeeded&orderId=777');
        $this->assertRedirectsTo('/commande/');
        $this->assertEmailCount(1);
        $paid = $this->reload(Commande::class, $commande->getId());
        $this->assertTrue($paid->getconfirmed());
        $this->assertSame(777, $paid->getHelloassoOrderId());

        $this->client->followRedirect();
        $this->assertSelectorTextContains('.flash', 'paiement a bien été reçu');
        $this->assertSelectorTextContains('.panel-head', 'Paiement reçu (en ligne)');
        $this->assertSelectorNotExists('form[action$="/paiement"]');
    }

    public function testReturnWithoutPaymentDoesNotConfirm(): void
    {
        $this->configure();
        $commande = $this->commande();
        $commande->setHelloassoCheckoutId(4242);
        $this->persist($commande);
        $this->api($commande->getId());
        $this->login($commande->getUser());

        // Paramètres d'URL forgés : sans paiement côté HelloAsso, rien n'est confirmé
        $this->client->request('GET', '/commande/' . $commande->getId() . '/paiement/retour?code=succeeded&orderId=1');
        $this->assertRedirectsTo('/commande/');
        $this->assertFalse($this->reload(Commande::class, $commande->getId())->getconfirmed());
        $this->assertEmailCount(0);
    }

    public function testPaymentOfAnotherUsersOrderIsDenied(): void
    {
        $this->configure();
        $commande = $this->commande();
        $this->login($this->createUser('autre@example.com'));

        $this->client->request('POST', '/commande/' . $commande->getId() . '/paiement');
        $this->assertResponseStatusCodeSame(403);
        $this->client->request('GET', '/commande/' . $commande->getId() . '/paiement/retour');
        $this->assertResponseStatusCodeSame(403);
    }

    public function testInvalidCsrfToken(): void
    {
        $this->configure();
        $commande = $this->commande();
        $this->api($commande->getId());
        $this->login($commande->getUser());

        $this->client->request('POST', '/commande/' . $commande->getId() . '/paiement', ['_token' => 'faux']);
        $this->assertRedirectsTo('/commande/');
        $this->assertSame([], HelloAssoHttpClient::$requests);
    }

    public function testHelloAssoErrorIsShown(): void
    {
        $this->configure();
        $commande = $this->commande();
        $this->login($commande->getUser());
        // HelloAsso injoignable (réponse 500 par défaut)

        $crawler = $this->client->request('GET', '/commande/');
        $this->client->submit($crawler->filter('form[action$="/paiement"]')->form());
        $this->assertRedirectsTo('/commande/');
        $this->client->followRedirect();
        $this->assertSelectorTextContains('.flash', 'momentanément indisponible');
    }

    public function testNotificationConfirmsOrderOnce(): void
    {
        $this->configure();
        $commande = $this->commande();
        $commande->setHelloassoCheckoutId(4242);
        $this->persist($commande);
        $this->api($commande->getId(), $this->paidOrder());

        $payload = json_encode([
            'eventType' => 'Order',
            'data' => ['id' => 777, 'payments' => [['state' => 'Authorized']]],
            'metadata' => ['commande' => $commande->getId()],
        ]);
        $this->client->request('POST', '/helloasso/notification', [], [], ['CONTENT_TYPE' => 'application/json'], $payload);
        $this->assertResponseIsSuccessful();
        $this->assertEmailCount(1);
        $paid = $this->reload(Commande::class, $commande->getId());
        $this->assertTrue($paid->getconfirmed());
        $this->assertSame(777, $paid->getHelloassoOrderId());

        // Notification répétée : pas de second e-mail
        $this->client->request('POST', '/helloasso/notification', [], [], ['CONTENT_TYPE' => 'application/json'], $payload);
        $this->assertResponseIsSuccessful();
        $this->assertEmailCount(0);
    }

    public function testForgedNotificationIsIgnored(): void
    {
        $this->configure();
        $commande = $this->commande();
        $commande->setHelloassoCheckoutId(4242);
        $this->persist($commande);
        // HelloAsso : pas encore payé
        $this->api($commande->getId());

        $this->client->request('POST', '/helloasso/notification', [], [], ['CONTENT_TYPE' => 'application/json'], json_encode([
            'eventType' => 'Order',
            'data' => ['payments' => [['state' => 'Authorized']]],
            'metadata' => ['commande' => $commande->getId()],
        ]));
        $this->assertResponseIsSuccessful();
        $this->assertFalse($this->reload(Commande::class, $commande->getId())->getconfirmed());

        $this->client->request('POST', '/helloasso/notification', [], [], [], 'pas du json');
        $this->assertResponseStatusCodeSame(400);
    }

    public function testPayButtonInOrderHistory(): void
    {
        $this->configure();
        $user = $this->createUser();
        $paid = $this->commande($user);
        $paid->setConfirmed(true);
        $this->persist($paid);
        $unpaid = $this->commande($user);
        $this->login($user);

        $crawler = $this->client->request('GET', '/synthese/0');
        $this->assertResponseIsSuccessful();
        $this->assertCount(1, $crawler->filter('form[action="/commande/' . $unpaid->getId() . '/paiement"]'));
        $this->assertCount(0, $crawler->filter('form[action="/commande/' . $paid->getId() . '/paiement"]'));
    }
}
