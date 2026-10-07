<?php

namespace App\Tests\Service;

use App\Service\Mailjet;
use App\Service\OptionsSettings;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\EventDispatcher\EventDispatcher;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Component\Mailer\Bridge\Mailjet\Transport\MailjetApiTransport;

class MailjetTest extends TestCase
{
    private function mailjet(?MockHttpClient $client = null, array $settings = []): Mailjet
    {
        $options = $this->createMock(OptionsSettings::class);
        $options->method('get')->willReturnCallback(function (string $name) use ($settings) {
            return $settings[$name] ?? '';
        });

        return new Mailjet($options, $client ?? new MockHttpClient(), new EventDispatcher(), new NullLogger());
    }

    private function senders(array $senders): MockHttpClient
    {
        return new MockHttpClient(function (string $method, string $url, array $options) use ($senders) {
            $this->assertSame('GET', $method);
            $this->assertStringStartsWith('https://api.mailjet.com/v3/REST/sender', $url);
            $this->assertContains('Authorization: Basic '.base64_encode('cle:secret'), $options['headers']);

            return new MockResponse(json_encode(['Count' => \count($senders), 'Data' => $senders]));
        });
    }

    public function testConfiguredWithBothKeys(): void
    {
        $this->assertFalse($this->mailjet(null, [Mailjet::API_KEY => 'cle'])->isConfigured());

        $mailjet = $this->mailjet(null, [Mailjet::API_KEY => 'cle', Mailjet::SECRET_KEY => 'secret']);
        $this->assertTrue($mailjet->isConfigured());
        $this->assertInstanceOf(MailjetApiTransport::class, $mailjet->transport());
    }

    public function testMissingKeys(): void
    {
        $checks = $this->mailjet()->check('', 'secret', 'news@example.com');

        $this->assertSame('danger', $checks[0][0]);
    }

    public function testRejectedKeys(): void
    {
        $client = new MockHttpClient(new MockResponse('', ['http_code' => 401]));

        $checks = $this->mailjet($client)->check('cle', 'faux', 'news@example.com');

        $this->assertCount(1, $checks);
        $this->assertSame('danger', $checks[0][0]);
        $this->assertStringContainsString('refuse ces clés', $checks[0][1]);
    }

    public function testActiveSender(): void
    {
        $checks = $this->mailjet($this->senders([['Email' => 'News@example.com', 'Status' => 'Active']]))->check('cle', 'secret', 'news@example.com');

        $this->assertSame(['success', 'success'], array_column($checks, 0));
    }

    public function testValidatedDomain(): void
    {
        $checks = $this->mailjet($this->senders([['Email' => '*@example.com', 'Status' => 'Active']]))->check('cle', 'secret', 'news@example.com');

        $this->assertSame('success', $checks[1][0]);
    }

    public function testPendingSender(): void
    {
        $checks = $this->mailjet($this->senders([['Email' => 'news@example.com', 'Status' => 'Inactive']]))->check('cle', 'secret', 'news@example.com');

        $this->assertSame('warning', $checks[1][0]);
        $this->assertStringContainsString('pas encore validé', $checks[1][1]);
    }

    public function testUnknownSender(): void
    {
        $checks = $this->mailjet($this->senders([['Email' => 'autre@example.org', 'Status' => 'Active']]))->check('cle', 'secret', 'news@example.com');

        $this->assertSame('warning', $checks[1][0]);
        $this->assertStringContainsString('inconnu de Mailjet', $checks[1][1]);
    }

    public function testUnreachable(): void
    {
        $client = new MockHttpClient(new MockResponse('', ['error' => 'Connexion refusée']));

        $checks = $this->mailjet($client)->check('cle', 'secret', 'news@example.com');

        $this->assertSame('danger', $checks[0][0]);
        $this->assertStringContainsString('injoignable', $checks[0][1]);
    }
}
