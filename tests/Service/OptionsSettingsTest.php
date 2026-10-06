<?php

namespace App\Tests\Service;

use App\Service\OptionsSettings;
use App\Tests\WebTestCase;

class OptionsSettingsTest extends WebTestCase
{
    public function testGet(): void
    {
        $settings = new OptionsSettings($this->em());

        $this->assertSame('Groupement de test', $settings->get('name'));
        $this->assertSame('défaut', $settings->get('inexistant', 'défaut'));
        // Valeur vide : la valeur par défaut est retournée
        $this->assertSame('défaut', $settings->get('favicon', 'défaut'));
    }

    public function testObfuscatedEmailLink(): void
    {
        $settings = new OptionsSettings($this->em());

        $link = $settings->getObfuscatedEmailLink('contact@example.com');

        $this->assertStringStartsWith('<a class="nav-link" href="', $link);
        $this->assertStringContainsString('rel="nofollow"', $link);
        $this->assertStringNotContainsString('contact@example.com', $link);
        // Une fois décodé par le navigateur, le lien est un mailto: valide
        preg_match('#href="([^"]+)"#', $link, $match);
        $this->assertSame('mailto:contact@example.com', rawurldecode(html_entity_decode($match[1])));
    }
}
