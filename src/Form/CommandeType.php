<?php

namespace App\Form;

use App\Entity\Commande;
use App\Entity\Product;
use App\Entity\LigneCommande;
use App\Entity\JourDistrib;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Form\Extension\Core\Type\CollectionType;
use Symfony\Component\Form\Extension\Core\Type\SubmitType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Bridge\Doctrine\Form\Type\EntityType;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Form\Extension\Core\Type\HiddenType;

use Symfony\Component\Form\FormEvent;
use Symfony\Component\Form\FormEvents;

use App\Repository\JourDistribRepository;

class CommandeType extends AbstractType
{    
    private $jourDistribRepository;

    public function __construct(JourDistribRepository $jourDistribRepository)
    {
        $this->jourDistribRepository = $jourDistribRepository;
    }

    
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {

        $builder
            ->add('jourDistrib', EntityType::class, [
                'class' => JourDistrib::class,
                'choice_label' => 'id',
                'choices' => [$options['jourDistrib']],
                'attr' => ['class' => 'd-none'],
                'label' => false,
            ])
            ->add('ligneCommandes', CollectionType::class, [
                'entry_type'   => LigneCommandeType::class,
                'entry_options' => ['label' => false, 'products' => $options['products']],
                'label' => false,
                'allow_add'    => true,
                'allow_delete' => true,
                'prototype'    => true,
                'required'     => true,
                'by_reference' => false,
                'delete_empty' => true,
            ])
            ->add('commentaire', TextareaType::class, [
                'required' => false,   
            ])
            ->add('livree', HiddenType::class, [
                'data' => 0,
            ])
            ->add('confirmed', HiddenType::class, [
                'data' => 0,
            ])
            // ->addEventListener(FormEvents::PRE_SUBMIT, $listener);
        ;
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => Commande::class,
            'idJourDistrib' => 1,
            'products' => Product::class,
            'jourDistrib' => JourDistrib::class,
            'lastNom' => null,
            'lastPrenom' => null,
        ]);
        $resolver->setAllowedTypes('idJourDistrib', 'int');
    }
}
