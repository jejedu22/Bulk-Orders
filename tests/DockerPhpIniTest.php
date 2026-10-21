<?php

namespace App\Tests;

use PHPUnit\Framework\TestCase;

/**
 * docker/php/app.ini est lu par PHP dans l'image Docker. Une erreur de syntaxe
 * (commentaire « # » par exemple, refusé par PHP) n'empêche pas le démarrage :
 * PHP ignore silencieusement tout ce qui suit l'erreur (fuseau horaire,
 * limites d'envoi, durcissement de sécurité…).
 */
class DockerPhpIniTest extends TestCase
{
    public function testIniFileIsValidAndAppliesTheExpectedSettings(): void
    {
        $settings = @parse_ini_file(\dirname(__DIR__).'/docker/php/app.ini', false, INI_SCANNER_RAW);
        $this->assertIsArray($settings, 'docker/php/app.ini contient une erreur de syntaxe (les commentaires commencent par « ; »).');

        $this->assertSame('Europe/Paris', $settings['date.timezone']);
        $this->assertSame('12M', $settings['post_max_size']);
        $this->assertSame('8M', $settings['upload_max_filesize']);
        // Durcissement CVE-2026-109618 / CVE-2026-109619
        $this->assertSame('Off', $settings['session.upload_progress.enabled']);
        $this->assertSame('16', $settings['max_input_nesting_level']);
        $this->assertSame('0', $settings['opcache.validate_timestamps']);
    }
}
