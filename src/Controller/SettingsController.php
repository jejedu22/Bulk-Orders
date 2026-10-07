<?php

namespace App\Controller;

use App\Entity\Settings;
use App\Form\MailjetType;
use App\Form\SettingsType;
use App\Service\Mailjet;
use App\Service\OptionsSettings;
use App\Repository\SettingsRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\HttpFoundation\File\Exception\FileException;
use Symfony\Component\HttpFoundation\File\File;

#[Route('/settings')]
class SettingsController extends AbstractController
{
    private $entityManager;

    public function __construct(EntityManagerInterface $entityManager)
    {
        $this->entityManager = $entityManager;
    }

    #[Route('/', name: 'settings_index', methods: ['GET'])]
    public function index(SettingsRepository $settingsRepository): Response
    {
        // Les réglages Mailjet ont leur propre page (la clé secrète n'est jamais affichée)
        $settings = array_filter($settingsRepository->findAll(), function (Settings $setting) {
            return !str_starts_with((string) $setting->getName(), 'mailjet_');
        });

        return $this->render('settings/index.html.twig', [
            'settings' => $settings,
        ]);
    }

    #[Route('/mailjet', name: 'settings_mailjet', methods: ['GET', 'POST'])]
    public function mailjet(Request $request, Mailjet $mailjet, OptionsSettings $options): Response
    {
        $form = $this->createForm(MailjetType::class, [
            'apiKey' => $mailjet->apiKey(),
            'sender' => $mailjet->sender(),
        ], ['has_secret' => '' !== $mailjet->secretKey()]);
        $form->handleRequest($request);

        $checks = null;
        if ($form->isSubmitted() && $form->isValid()) {
            $data = $form->getData();
            $apiKey = trim((string) $data['apiKey']);
            // Clé secrète vide : on garde celle enregistrée
            $secretKey = trim((string) $data['secretKey']) ?: $mailjet->secretKey();
            $sender = trim((string) $data['sender']);

            if ($request->request->has('check')) {
                // Vérification des valeurs saisies, sans les enregistrer
                $checks = $mailjet->check($apiKey, $secretKey, $sender ?: $this->defaultSender($options));
            } else {
                $options->set(Mailjet::API_KEY, $apiKey);
                $options->set(Mailjet::SECRET_KEY, '' === $apiKey ? '' : $secretKey);
                $options->set(Mailjet::SENDER, $sender);
                $this->addFlash('success', '' === $apiKey ? 'Configuration Mailjet supprimée.' : 'Configuration Mailjet enregistrée.');

                return $this->redirectToRoute('settings_mailjet');
            }
        }

        return $this->render('settings/mailjet.html.twig', [
            'form' => $form->createView(),
            'configured' => $mailjet->isConfigured(),
            'checks' => $checks,
            'default_sender' => $this->defaultSender($options),
        ]);
    }


    #[Route('/{id}/edit', name: 'settings_edit', methods: ['GET', 'POST'])]
    public function edit(Request $request, Settings $setting): Response
    {
        $form = $this->createForm(SettingsType::class, $setting);
        $form->handleRequest($request);

        if ($setting->getName() == 'logo') {
            if ($setting->getValue()) {
                $setting->setValue(
                    new File($this->getParameter('logo_directory') . '/' . $setting->getValue())
                );
            }
        }

        if ($form->isSubmitted() && $form->isValid()) {
            if ($form->get('name')->getData() == 'logo') {
                $logo = $form->get('value')->getData();
                if ($logo) {
                    $originalFilename = pathinfo($logo->getClientOriginalName(), PATHINFO_FILENAME);
                    $safeFilename = transliterator_transliterate('Any-Latin; Latin-ASCII; [^A-Za-z0-9_] remove; Lower()', $originalFilename);
                    $newFilename = $safeFilename . '-' . uniqid() . '.' . $logo->guessExtension();

                    try {
                        $logo->move(
                            $this->getParameter('logo_directory'),
                            $newFilename
                        );
                    } catch (FileException $e) {
                    }
                    $setting->setValue($newFilename);
                }
            }
            $this->entityManager->flush();

            return $this->redirectToRoute('settings_index');
        }

        return $this->render('settings/edit.html.twig', [
            'setting' => $setting,
            'form' => $form->createView(),
        ]);
    }

    private function defaultSender(OptionsSettings $options): string
    {
        $from = trim((string) $this->getParameter('mailer_from'));

        return '' !== $from ? $from : trim($options->get('contact_email'));
    }
}
