<?php

namespace App\Form;

use App\Service\AdhesionPeriode;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\MoneyType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\Validator\Constraints\PositiveOrZero;

/**
 * Paramètres → Adhésions : saison et cotisation proposée par défaut.
 */
class AdhesionSettingsType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('moisDebut', ChoiceType::class, [
                'label' => 'La saison commence en',
                'choices' => AdhesionPeriode::MOIS,
                'help' => 'Janvier : les adhésions courent sur l\'année civile. Septembre : de septembre à fin août, par exemple.',
            ])
            ->add('montant', MoneyType::class, [
                'label' => 'Cotisation proposée',
                'currency' => 'EUR',
                'required' => false,
                'help' => 'Pré-remplie lors de l\'enregistrement d\'une adhésion (modifiable à chaque fois).',
                'constraints' => [new PositiveOrZero()],
            ])
        ;
    }
}
