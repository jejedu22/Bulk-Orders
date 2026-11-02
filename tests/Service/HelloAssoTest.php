<?php

namespace App\Tests\Service;

use App\Entity\Commande;
use App\Entity\LigneCommande;
use App\Entity\Product;
use App\Service\HelloAsso;
use App\Service\HelloAssoException;
use App\Service\OptionsSettings;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

class HelloAssoTest extends TestCase
{
    private const ACCOUNT = [
        HelloAsso::ORGANIZATION => 'mon-asso',
        HelloAsso::CLIENT_ID => 'client',
        HelloAsso::CLIENT_SECRET => 'secret',
    ];

    private function helloAsso(?MockHttpClient $client = null, array $settings = self::ACCOUNT): HelloAsso
    {
        $options = $this->createMock(OptionsSettings::class);
        $options->method('get')->willReturnCallback(fn (string $name) => $settings[$name] ?? '');

        return new HelloAsso($options, $client ?? new MockHttpClient(), new NullLogger());
    }

    /**
     * @param array<array{0: float, 1: ?float, 2: int}> $lignes [prix initial, prix final, quantité]
     */
    private function commande(array $lignes, int $id = 12): Commande
    {
        $commande = new Commande();
        (new \ReflectionProperty(Commande::class, 'id'))->setValue($commande, $id);
        foreach ($lignes as [$prixInit, $prixFinal, $quantite]) {
            $product = (new Product())->setNom('Produit')->setPrixInit($prixInit)->setPrixFinal($prixFinal);
            $commande->addLigneCommande((new LigneCommande())->setProduct($product)->setQuantite($quantite));
        }

        return $commande;
    }

    public function testConfiguredWithFullAccount(): void
    {
        $this->assertTrue($this->helloAsso()->isConfigured());
        $this->assertFalse($this->helloAsso(null, [HelloAsso::CLIENT_ID => 'client', HelloAsso::CLIENT_SECRET => 'secret'])->isConfigured());
        $this->assertFalse($this->helloAsso(null, [])->canPay($this->commande([[10, null, 1]])));
    }

    public function testAmountUsesFinalPricesOnlyWhenAllKnown(): void
    {
        $helloAsso = $this->helloAsso();

        $this->assertSame(2550, $helloAsso->amount($this->commande([[10.5, null, 2], [4.5, 4, 1]])));
        $this->assertSame(2400, $helloAsso->amount($this->commande([[10.5, 10, 2], [4.5, 4, 1]])));
    }

    public function testNoOnlinePaymentUnderOneEuroOrWhenPaid(): void
    {
        $helloAsso = $this->helloAsso();

        $this->assertFalse($helloAsso->canPay($this->commande([[0.5, null, 1]])));
        $this->assertTrue($helloAsso->canPay($this->commande([[1, null, 1]])));
        $this->assertFalse($helloAsso->canPay($this->commande([[10, null, 1]])->setConfirmed(true)));
    }

    public function testCreateCheckoutReusesToken(): void
    {
        $tokens = 0;
        $client = new MockHttpClient(function (string $method, string $url, array $options) use (&$tokens) {
            if ('https://api.helloasso.com/oauth2/token' === $url) {
                ++$tokens;
                $this->assertSame('grant_type=client_credentials&client_id=client&client_secret=secret', $options['body']);

                return new MockResponse(json_encode(['access_token' => 'jeton']));
            }
            $this->assertContains('Authorization: Bearer jeton', $options['headers']);
            if ('POST' === $method) {
                $this->assertSame('https://api.helloasso.com/v5/organizations/mon-asso/checkout-intents', $url);
                $body = json_decode($options['body'], true);
                $this->assertSame(1000, $body['totalAmount']);
                $this->assertSame('Commande n°12', $body['itemName']);
                $this->assertSame(['commande' => 12], $body['metadata']);
                $this->assertArrayNotHasKey('payer', $body);

                return new MockResponse(json_encode(['id' => 99, 'redirectUrl' => 'https://helloasso.test/pay']));
            }

            return new MockResponse(json_encode(['id' => 99, 'metadata' => ['commande' => 12]]));
        });
        $helloAsso = $this->helloAsso($client);
        $commande = $this->commande([[10, null, 1]]);

        $this->assertSame('https://helloasso.test/pay', $helloAsso->createCheckout($commande, 'https://a/retour', 'https://a/erreur', 'https://a/annule'));
        $this->assertSame(99, $commande->getHelloassoCheckoutId());
        $this->assertNull($helloAsso->paidOrderId($commande));
        $this->assertSame(1, $tokens);
    }

    #[DataProvider('checkoutStates')]
    public function testPaidOrderId(array $checkout, ?int $expected): void
    {
        $client = new MockHttpClient([
            new MockResponse(json_encode(['access_token' => 'jeton'])),
            new MockResponse(json_encode($checkout)),
        ]);
        $commande = $this->commande([[10, null, 1]])->setHelloassoCheckoutId(99);

        $this->assertSame($expected, $this->helloAsso($client)->paidOrderId($commande));
    }

    public static function checkoutStates(): iterable
    {
        $authorized = ['id' => 5, 'payments' => [['state' => 'Authorized']]];

        yield 'payé' => [['metadata' => ['commande' => 12], 'order' => $authorized], 5];
        yield 'pas encore payé' => [['metadata' => ['commande' => 12]], null];
        yield 'paiement refusé' => [['metadata' => ['commande' => 12], 'order' => ['id' => 5, 'payments' => [['state' => 'Refused']]]], null];
        yield 'autre commande' => [['metadata' => ['commande' => 13], 'order' => $authorized], null];
    }

    public function testNoCheckoutNoRequest(): void
    {
        $client = new MockHttpClient(function () {
            $this->fail('Aucune requête attendue.');
        });

        $this->assertNull($this->helloAsso($client)->paidOrderId($this->commande([[10, null, 1]])));
    }

    public function testApiErrorThrows(): void
    {
        $client = new MockHttpClient([
            new MockResponse(json_encode(['access_token' => 'jeton'])),
            new MockResponse('{"errors":[]}', ['http_code' => 400]),
        ]);

        $this->expectException(HelloAssoException::class);
        $this->helloAsso($client)->createCheckout($this->commande([[10, null, 1]]), 'a', 'b', 'c');
    }

    public function testCheckMissingValues(): void
    {
        $checks = $this->helloAsso()->check('client', '', 'mon-asso', false);

        $this->assertSame('danger', $checks[0][0]);
    }

    public function testCheckRejectedCredentials(): void
    {
        $client = new MockHttpClient(new MockResponse('{"error":"unauthorized_client"}', ['http_code' => 400]));

        $checks = $this->helloAsso($client)->check('client', 'faux', 'mon-asso', true);

        $this->assertCount(1, $checks);
        $this->assertSame('danger', $checks[0][0]);
        $this->assertStringContainsString('environnement de test', $checks[0][1]);
    }

    public function testCheckUnknownOrganization(): void
    {
        $client = new MockHttpClient([
            new MockResponse(json_encode(['access_token' => 'jeton'])),
            new MockResponse('', ['http_code' => 404]),
        ]);

        $checks = $this->helloAsso($client)->check('client', 'secret', 'inconnue', false);

        $this->assertSame('success', $checks[0][0]);
        $this->assertSame('danger', $checks[1][0]);
        $this->assertStringContainsString('introuvable', $checks[1][1]);
    }

    public function testCheckOrganizationFound(): void
    {
        $client = new MockHttpClient([
            new MockResponse(json_encode(['access_token' => 'jeton'])),
            new MockResponse(json_encode(['name' => 'Mon Association'])),
        ]);

        $checks = $this->helloAsso($client)->check('client', 'secret', 'mon-asso', false);

        $this->assertSame([['success', 'Connexion à HelloAsso réussie : les identifiants sont valides.'], ['success', 'Organisation trouvée : Mon Association.']], $checks);
    }
}
