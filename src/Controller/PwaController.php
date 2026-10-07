<?php

namespace App\Controller;

use App\Service\OptionsSettings;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

class PwaController extends AbstractController
{
    private const COLORS = [
        'blue' => '#007bff', 'cyan' => '#17a2b8', 'gray' => '#6c757d', 'gray-dark' => '#343a40',
        'indigo' => '#6610f2', 'yellow' => '#e0a800', 'orange' => '#fd7e14', 'pink' => '#e83e8c',
        'red' => '#dc3545', 'teal' => '#20c997', 'green' => '#28a745', 'purple' => '#6f42c1',
    ];

    private const DEFAULT_ICONS = [
        ['src' => '/pwa/icon-192.png', 'sizes' => '192x192', 'type' => 'image/png', 'purpose' => 'any'],
        ['src' => '/pwa/icon-512.png', 'sizes' => '512x512', 'type' => 'image/png', 'purpose' => 'any'],
        ['src' => '/pwa/icon-maskable-512.png', 'sizes' => '512x512', 'type' => 'image/png', 'purpose' => 'maskable'],
    ];

    /**
     * Icônes issues du logo téléversé dans les paramètres.
     * Retourne [] si le logo est absent ou trop petit pour être installable (< 192 px).
     */
    private function logoIcons(string $logo): array
    {
        if ($logo === '') {
            return [];
        }
        $path = $this->getParameter('logo_directory') . '/' . basename($logo);
        if (!is_file($path)) {
            return [];
        }
        $src = '/uploads/logo/' . rawurlencode(basename($logo));
        $info = @getimagesize($path);
        if ($info === false) {
            // SVG : vectoriel, utilisable à toute taille
            if (strtolower(pathinfo($path, PATHINFO_EXTENSION)) === 'svg') {
                return [['src' => $src, 'sizes' => 'any', 'type' => 'image/svg+xml', 'purpose' => 'any']];
            }

            return [];
        }
        if (min($info[0], $info[1]) < 192) {
            return [];
        }

        return [['src' => $src, 'sizes' => $info[0] . 'x' . $info[1], 'type' => $info['mime'], 'purpose' => 'any']];
    }

    /**
     * Icônes PNG générées : logo des paramètres centré sur la couleur du thème.
     * Retourne [] si aucun logo raster lisible par GD n'est disponible.
     */
    private function generatedIcons(OptionsSettings $options): array
    {
        if ($this->logoImage($options->get('logo')) === null) {
            return [];
        }
        $v = $this->iconVersion($options);

        return [
            ['src' => $this->generateUrl('pwa_icon', ['size' => 192, 'purpose' => 'any', 'v' => $v]), 'sizes' => '192x192', 'type' => 'image/png', 'purpose' => 'any'],
            ['src' => $this->generateUrl('pwa_icon', ['size' => 512, 'purpose' => 'any', 'v' => $v]), 'sizes' => '512x512', 'type' => 'image/png', 'purpose' => 'any'],
            ['src' => $this->generateUrl('pwa_icon', ['size' => 512, 'purpose' => 'maskable', 'v' => $v]), 'sizes' => '512x512', 'type' => 'image/png', 'purpose' => 'maskable'],
        ];
    }

    private function iconVersion(OptionsSettings $options): string
    {
        $logo = $options->get('logo');
        $path = $this->getParameter('logo_directory') . '/' . basename($logo);

        return substr(md5($logo . '|' . $options->get('color') . '|' . (@filemtime($path) ?: 0)), 0, 10);
    }

    /** @return resource|\GdImage|null */
    private function logoImage(string $logo)
    {
        if ($logo === '' || !function_exists('imagecreatefromstring')) {
            return null;
        }
        $path = $this->getParameter('logo_directory') . '/' . basename($logo);
        if (!is_file($path)) {
            return null;
        }
        $img = @imagecreatefromstring((string) file_get_contents($path));

        return $img ?: null;
    }

    #[Route('/icon/{size}-{purpose}.png', name: 'pwa_icon', requirements: ['size' => '180|192|512', 'purpose' => 'any|maskable'])]
    public function icon(Request $request, OptionsSettings $options, int $size, string $purpose): Response
    {
        $logo = $this->logoImage($options->get('logo'));
        if ($logo === null) {
            return $this->redirect('/pwa/icon-' . ($purpose === 'maskable' ? 'maskable-' : '') . ($size === 180 ? 192 : $size) . '.png');
        }

        $response = new Response();
        $response->setEtag(md5($this->iconVersion($options) . "|$size|$purpose"));
        $response->setPublic();
        $response->setMaxAge(3600);
        if ($response->isNotModified($request)) {
            return $response;
        }

        $hex = self::COLORS[$options->get('color')] ?? self::COLORS['green'];
        $canvas = imagecreatetruecolor($size, $size);
        $bg = imagecolorallocate($canvas, hexdec(substr($hex, 1, 2)), hexdec(substr($hex, 3, 2)), hexdec(substr($hex, 5, 2)));
        imagefill($canvas, 0, 0, $bg);

        // Zone sûre des icônes « maskable » : cercle central de 80 % ; on reste à 60 % pour ne pas rogner le logo
        $box = (int) round($size * ($purpose === 'maskable' ? 0.6 : 0.72));
        $w = imagesx($logo);
        $h = imagesy($logo);
        $ratio = min($box / $w, $box / $h);
        $dw = max(1, (int) round($w * $ratio));
        $dh = max(1, (int) round($h * $ratio));
        imagealphablending($canvas, true);
        imagecopyresampled($canvas, $logo, (int) (($size - $dw) / 2), (int) (($size - $dh) / 2), 0, 0, $dw, $dh, $w, $h);

        ob_start();
        imagepng($canvas);
        $response->setContent(ob_get_clean());
        $response->headers->set('Content-Type', 'image/png');

        return $response;
    }

    #[Route('/manifest.webmanifest', name: 'pwa_manifest')]
    public function manifest(OptionsSettings $options): JsonResponse
    {
        $icons = $this->generatedIcons($options) ?: $this->logoIcons($options->get('logo')) ?: self::DEFAULT_ICONS;

        $name = $options->get('name', 'Bulk Orders');
        $color = self::COLORS[$options->get('color')] ?? self::COLORS['green'];

        $response = new JsonResponse([
            'name' => $name,
            'short_name' => mb_substr($name, 0, 12),
            'description' => 'Commandes groupées',
            'lang' => 'fr',
            'start_url' => $this->generateUrl('passe_commande_index'),
            'scope' => '/',
            'display' => 'standalone',
            'background_color' => '#ffffff',
            'theme_color' => $color,
            'icons' => $icons,
        ]);
        $response->headers->set('Content-Type', 'application/manifest+json');

        return $response;
    }

    #[Route('/offline', name: 'pwa_offline')]
    public function offline(): Response
    {
        return $this->render('pwa/offline.html.twig');
    }
}
