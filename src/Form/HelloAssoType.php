<?php

namespace App\Form;

use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\Extension\Core\Type\PasswordType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints\Regex;

/**
 * Configuration du compte HelloAsso (Paramètres → HelloAsso).
 */
class HelloAssoType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('organization', TextType::class, [
                'label' => 'Organisation',
                'required' => false,
                'help' => 'Identifiant de l\'association dans l\'adresse de sa page HelloAsso : helloasso.com/associations/<strong>mon-association</strong>.',
                'help_html' => true,
                'attr' => ['autocomplete' => 'off', 'spellcheck' => 'false', 'placeholder' => 'mon-association'],
                'constraints' => [new Regex(pattern: '/^[a-z0-9][a-z0-9-]*$/', message: 'Uniquement des minuscules, chiffres et tirets.')],
            ])
            ->add('clientId', TextType::class, [
                'label' => 'Identifiant client (Client ID)',
                'required' => false,
                'attr' => ['autocomplete' => 'off', 'spellcheck' => 'false'],
            ])
            ->add('clientSecret', PasswordType::class, [
                'label' => 'Clé secrète (Client Secret)',
                'required' => false,
                'help' => $options['has_secret'] ? 'Laisser vide pour conserver la clé enregistrée.' : null,
                'attr' => ['autocomplete' => 'new-password', 'placeholder' => $options['has_secret'] ? '••••••••••••' : ''],
            ])
            ->add('sandbox', CheckboxType::class, [
                'label' => 'Environnement de test (helloasso-sandbox.com)',
                'required' => false,
                'help' => 'Paiements fictifs, avec un compte créé sur le site de test de HelloAsso.',
            ])
        ;
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults(['has_secret' => false]);
        $resolver->setAllowedTypes('has_secret', 'bool');
    }
}
