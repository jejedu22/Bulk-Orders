<?php

namespace App\Controller;

use App\Entity\Settings;
use App\Form\SettingsType;
use App\Repository\SettingsRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\HttpFoundation\File\Exception\FileException;
use Symfony\Component\HttpFoundation\File\File;

/**
 * @Route("/settings")
 */
class SettingsController extends AbstractController
{
    /**
     * @Route("/", name="settings_index", methods={"GET"})
     */
    public function index(SettingsRepository $settingsRepository): Response
    {
        return $this->render('settings/index.html.twig', [
            'settings' => $settingsRepository->findAll(),
        ]);
    }


    /**
     * @Route("/{id}/edit", name="settings_edit", methods={"GET","POST"})
     */
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
            $this->getDoctrine()->getManager()->flush();

            return $this->redirectToRoute('settings_index');
        }

        return $this->render('settings/edit.html.twig', [
            'setting' => $setting,
            'form' => $form->createView(),
        ]);
    }

}
