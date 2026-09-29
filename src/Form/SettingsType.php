<?php

namespace App\Form;

use App\Entity\Settings;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\EmailType;

use Symfony\Component\Form\FormEvent;
use Symfony\Component\Form\FormEvents;
use Symfony\Component\Form\Extension\Core\Type\HiddenType;
use Symfony\Component\Form\Extension\Core\Type\FileType;
use Symfony\Component\Validator\Constraints\File;
use Symfony\Component\Validator\Constraints\Email as EmailConstraint;
use Symfony\Component\Validator\Constraints\NotBlank;

class SettingsType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options)
    {
        $builder
            ->add('name', HiddenType::class,[])
        ;
        $builder->addEventListener(FormEvents::PRE_SET_DATA, function (FormEvent $event) {
            $settings = $event->getData();
            $form = $event->getForm();

            // checks if the Product object is "new"
            // If no data is passed to the form, the data is "null".
            // This should be considered a new "Product"
            if (!$settings || 1 === $settings->getId()) {
                $form->add('value', ChoiceType::class, [
                    'choices'  => [
                        'settings.form.blue' => 'blue',
                        'settings.form.cyan' => 'cyan',
                        'settings.form.gray' => 'gray',
                        'settings.form.gray-dark' => 'gray-dark',
                        'settings.form.indigo' => 'indigo',
                        'settings.form.yellow' => 'yellow',
                        'settings.form.orange' => 'orange',
                        'settings.form.pink' => 'pink',
                        'settings.form.red' => 'red',
                        'settings.form.teal' => 'teal',
                        'settings.form.green' => 'green',
                        'settings.form.purple' => 'purple',
                    ],
                    'multiple'=>false,
                    'expanded'=>true,
                    'choice_attr' => function($choice, $key, $value) {
                        return ['class' => 'color-'.strtolower($value)];
                    },
                    'placeholder' => false
                ]);
            }
            elseif (!$settings || 2 === $settings->getId()){
                $form->add('value', FileType::class, [
                    'mapped' => false,
                    'required' => false,
                    'constraints' => [
                        new File([
                            'maxSize' => '100k',
                            'mimeTypes' => [
                                'image/png',
                            ],
                            'mimeTypesMessage' => 'settings.form.valid_image',
                        ])
                    ],
                    'label' => 'settings.form.choice_file',
                ]);
            }
            elseif (!$settings || 5 === $settings->getId()){
                // Champ lié à la valeur : il est prérempli et ne peut plus être vidé par erreur
                $form->add('value', EmailType::class, [
                    'help' => 'settings.form.valid_mail',
                    'required' => true,
                    'constraints' => [new NotBlank(), new EmailConstraint()],
            ]);
            }
            else {
                $form->add('value');
            }
        });
    }

    public function configureOptions(OptionsResolver $resolver)
    {
        $resolver->setDefaults([
            'data_class' => Settings::class,
        ]);
    }
}
