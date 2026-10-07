<?php

namespace App\Tests;

use PHPUnit\Framework\TestCase;

/**
 * Les plugins front sont exclus de l'image Docker sauf liste blanche
 * (.dockerignore) : un fichier chargé par un template mais absent de la liste
 * fonctionne en local et renvoie une 404 en production.
 */
class DockerignoreTest extends TestCase
{
    public function testEveryPluginAssetUsedByTemplatesIsCopiedIntoTheImage(): void
    {
        $root = \dirname(__DIR__);
        $allowed = [];
        foreach (file($root.'/.dockerignore', FILE_IGNORE_NEW_LINES) as $line) {
            if (str_starts_with($line, '!public/plugins/')) {
                $allowed[] = rtrim(substr($line, 1), '/');
            }
        }

        $used = [];
        $templates = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root.'/templates', \FilesystemIterator::SKIP_DOTS));
        foreach ($templates as $template) {
            preg_match_all("#asset\\('/?(plugins/[^']+)'\\)#", file_get_contents($template->getPathname()), $matches);
            foreach ($matches[1] as $path) {
                $used['public/'.$path] = true;
            }
        }
        $this->assertNotEmpty($used);

        foreach (array_keys($used) as $path) {
            $copied = false;
            foreach ($allowed as $entry) {
                if ($path === $entry || str_starts_with($path, $entry.'/')) {
                    $copied = true;
                }
            }
            $this->assertTrue($copied, sprintf('%s est chargé par un template mais exclu de l\'image Docker : l\'ajouter à .dockerignore.', $path));
            $this->assertFileExists($root.'/'.$path);
        }
    }
}
