<?php

namespace App\Form;

use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\IntegerType;
use Symfony\Component\Form\Extension\Core\Type\TimeType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints\Callback;
use Symfony\Component\Validator\Constraints\NotBlank;
use Symfony\Component\Validator\Constraints\Range;
use Symfony\Component\Validator\Context\ExecutionContextInterface;

/**
 * Génération d'une série de créneaux de retrait.
 */
class GenerateurCreneauxType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('de', TimeType::class, [
                'label' => 'De',
                'widget' => 'single_text',
                'input' => 'string',
                'input_format' => 'H:i',
                'constraints' => [new NotBlank()],
            ])
            ->add('a', TimeType::class, [
                'label' => 'À',
                'widget' => 'single_text',
                'input' => 'string',
                'input_format' => 'H:i',
                'constraints' => [new NotBlank()],
            ])
            ->add('duree', ChoiceType::class, [
                'label' => 'Durée',
                'choices' => ['10 min' => 10, '15 min' => 15, '20 min' => 20, '30 min' => 30, '45 min' => 45, '1 h' => 60],
            ])
            ->add('places', IntegerType::class, [
                'label' => 'Places par créneau',
                'attr' => ['min' => 1, 'max' => 500],
                'constraints' => [new NotBlank(), new Range(min: 1, max: 500)],
            ])
        ;
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'constraints' => [new Callback(function ($data, ExecutionContextInterface $context) {
                if (\is_array($data) && !empty($data['de']) && !empty($data['a']) && $data['a'] <= $data['de']) {
                    $context->buildViolation('L\'heure de fin doit être après l\'heure de début.')->atPath('[a]')->addViolation();
                }
            })],
        ]);
    }
}
