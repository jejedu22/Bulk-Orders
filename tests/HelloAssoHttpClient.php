<?php

namespace App\Tests;

use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Contracts\HttpClient\ResponseInterface;
use Symfony\Contracts\HttpClient\ResponseStreamInterface;

/**
 * Client HTTP du service HelloAsso dans l'environnement de test
 * (config/services_test.yaml) : aucune requête ne part vers HelloAsso.
 *
 * L'état est statique car le client de test redémarre le noyau entre deux
 * requêtes.
 */
class HelloAssoHttpClient implements HttpClientInterface
{
    /** @var callable|null fn (string $method, string $url, array $options): MockResponse */
    public static $handler;

    /** @var array<array{0: string, 1: string, 2: array}> requêtes reçues [méthode, URL, options] */
    public static $requests = [];

    public static function reset(): void
    {
        self::$handler = null;
        self::$requests = [];
    }

    public function request(string $method, string $url, array $options = []): ResponseInterface
    {
        $mock = new MockHttpClient(function (string $method, string $url, array $options) {
            self::$requests[] = [$method, $url, $options];

            return null !== self::$handler ? (self::$handler)($method, $url, $options) : new MockResponse('', ['http_code' => 500]);
        });

        return $mock->request($method, $url, $options);
    }

    public function stream(ResponseInterface|iterable $responses, ?float $timeout = null): ResponseStreamInterface
    {
        return (new MockHttpClient())->stream($responses, $timeout);
    }

    public function withOptions(array $options): static
    {
        return $this;
    }
}
