<?php

namespace App\Form;

use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\EmailType;
use Symfony\Component\Form\Extension\Core\Type\PasswordType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\Validator\Constraints\Email;

/**
 * Configuration Mailjet (Paramètres → Mailjet).
 */
class MailjetType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('apiKey', TextType::class, [
                'label' => 'Clé API',
                'required' => false,
                'attr' => ['autocomplete' => 'off', 'spellcheck' => 'false'],
            ])
            ->add('secretKey', PasswordType::class, [
                'label' => 'Clé secrète',
                'required' => false,
                'help' => $options['has_secret'] ? 'Laisser vide pour conserver la clé enregistrée.' : null,
                'attr' => ['autocomplete' => 'new-password', 'placeholder' => $options['has_secret'] ? '••••••••••••' : ''],
            ])
            ->add('sender', EmailType::class, [
                'label' => 'Expéditeur des newsletters',
                'required' => false,
                'help' => 'Adresse validée dans Mailjet. Vide : expéditeur habituel de l\'application.',
                'constraints' => [new Email()],
            ])
        ;
    }

    public function configureOptions(\Symfony\Component\OptionsResolver\OptionsResolver $resolver): void
    {
        $resolver->setDefaults(['has_secret' => false]);
        $resolver->setAllowedTypes('has_secret', 'bool');
    }
}
