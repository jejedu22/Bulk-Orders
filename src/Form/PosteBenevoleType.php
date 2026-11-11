<?php

namespace App\Form;

use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\IntegerType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\Extension\Core\Type\TimeType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints\Callback;
use Symfony\Component\Validator\Constraints\Length;
use Symfony\Component\Validator\Constraints\NotBlank;
use Symfony\Component\Validator\Constraints\Range;
use Symfony\Component\Validator\Context\ExecutionContextInterface;

/**
 * Poste de bénévole (intitulé, horaires le jour de la distribution, besoin).
 */
class PosteBenevoleType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('intitule', TextType::class, [
                'label' => 'Poste',
                'attr' => ['placeholder' => 'Installation, Distribution, Rangement…'],
                'constraints' => [new NotBlank(message: 'Donnez un nom au poste.'), new Length(max: 100)],
            ])
            ->add('description', TextType::class, [
                'label' => 'Description',
                'required' => false,
                'attr' => ['placeholder' => 'Tables, balance, affichage…'],
                'constraints' => [new Length(max: 255)],
            ])
            ->add('debut', TimeType::class, ['label' => 'De', 'widget' => 'single_text', 'input' => 'string', 'input_format' => 'H:i', 'constraints' => [new NotBlank()]])
            ->add('fin', TimeType::class, ['label' => 'À', 'widget' => 'single_text', 'input' => 'string', 'input_format' => 'H:i', 'constraints' => [new NotBlank()]])
            ->add('besoin', IntegerType::class, ['label' => 'Personnes', 'attr' => ['min' => 1], 'constraints' => [new NotBlank(), new Range(min: 1, max: 100)]])
        ;
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'constraints' => [new Callback(function ($data, ExecutionContextInterface $context) {
                if (\is_array($data) && !empty($data['debut']) && !empty($data['fin']) && $data['fin'] <= $data['debut']) {
                    $context->buildViolation('L\'heure de fin doit être après l\'heure de début.')->atPath('[fin]')->addViolation();
                }
            })],
        ]);
    }
}
