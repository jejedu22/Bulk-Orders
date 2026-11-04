<?php

namespace App\Form;

use App\Entity\Adhesion;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\DateType;
use Symfony\Component\Form\Extension\Core\Type\MoneyType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

class AdhesionType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('dateDebut', DateType::class, [
                'label' => 'Du',
                'widget' => 'single_text',
                'input' => 'datetime_immutable',
            ])
            ->add('dateFin', DateType::class, [
                'label' => 'Au',
                'widget' => 'single_text',
                'input' => 'datetime_immutable',
            ])
            ->add('montant', MoneyType::class, [
                'label' => 'Montant',
                'currency' => 'EUR',
                'required' => false,
            ])
            ->add('modePaiement', ChoiceType::class, [
                'label' => 'Paiement',
                'choices' => Adhesion::MODES_PAIEMENT,
                'required' => false,
                'placeholder' => '—',
            ])
            ->add('note', TextType::class, [
                'label' => 'Note',
                'required' => false,
                'attr' => ['placeholder' => 'N° de chèque, remarque…'],
            ])
        ;
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => Adhesion::class,
        ]);
    }
}
