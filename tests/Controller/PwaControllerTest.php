<?php

namespace App\Tests\Controller;

use App\Tests\WebTestCase;

class PwaControllerTest extends WebTestCase
{
    public function testManifestUsesSettings(): void
    {
        $this->client->request('GET', '/manifest.webmanifest');

        $this->assertResponseIsSuccessful();
        $this->assertResponseHeaderSame('Content-Type', 'application/manifest+json');
        $manifest = json_decode($this->client->getResponse()->getContent(), true);
        $this->assertSame('Groupement de test', $manifest['name']);
        $this->assertSame('#007bff', $manifest['theme_color']);
        $this->assertNotEmpty($manifest['icons']);
    }

    public function testIconFallsBackToStaticIconWithoutLogoFile(): void
    {
        $this->client->request('GET', '/icon/512-maskable.png');

        $this->assertResponseRedirects('/pwa/icon-maskable-512.png');
    }

    public function testOfflinePageIsPublic(): void
    {
        $this->client->request('GET', '/offline');

        $this->assertResponseIsSuccessful();
    }
}
