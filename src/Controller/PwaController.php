<?php

namespace App\Controller;

use App\Service\OptionsSettings;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;

class PwaController extends AbstractController
{
    private const COLORS = [
        'blue' => '#007bff', 'cyan' => '#17a2b8', 'gray' => '#6c757d', 'gray-dark' => '#343a40',
        'indigo' => '#6610f2', 'yellow' => '#e0a800', 'orange' => '#fd7e14', 'pink' => '#e83e8c',
        'red' => '#dc3545', 'teal' => '#20c997', 'green' => '#28a745', 'purple' => '#6f42c1',
    ];

    /**
     * @Route("/manifest.webmanifest", name="pwa_manifest")
     */
    public function manifest(OptionsSettings $options): JsonResponse
    {
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
            'icons' => [
                ['src' => '/pwa/icon-192.png', 'sizes' => '192x192', 'type' => 'image/png', 'purpose' => 'any'],
                ['src' => '/pwa/icon-512.png', 'sizes' => '512x512', 'type' => 'image/png', 'purpose' => 'any'],
                ['src' => '/pwa/icon-maskable-512.png', 'sizes' => '512x512', 'type' => 'image/png', 'purpose' => 'maskable'],
            ],
        ]);
        $response->headers->set('Content-Type', 'application/manifest+json');

        return $response;
    }

    /**
     * @Route("/offline", name="pwa_offline")
     */
    public function offline(): Response
    {
        return $this->render('pwa/offline.html.twig');
    }
}
