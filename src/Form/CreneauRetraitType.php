<?php

namespace App\Form;

use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\IntegerType;
use Symfony\Component\Form\Extension\Core\Type\TimeType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints\Callback;
use Symfony\Component\Validator\Constraints\NotBlank;
use Symfony\Component\Validator\Constraints\Range;
use Symfony\Component\Validator\Context\ExecutionContextInterface;

/**
 * Un créneau de retrait (horaires le jour de la distribution, places).
 */
class CreneauRetraitType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('debut', TimeType::class, ['label' => 'Début', 'widget' => 'single_text', 'input' => 'string', 'input_format' => 'H:i', 'constraints' => [new NotBlank()]])
            ->add('fin', TimeType::class, ['label' => 'Fin', 'widget' => 'single_text', 'input' => 'string', 'input_format' => 'H:i', 'constraints' => [new NotBlank()]])
            ->add('places', IntegerType::class, ['label' => 'Places', 'attr' => ['min' => 1], 'constraints' => [new NotBlank(), new Range(min: 1, max: 500)]])
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
