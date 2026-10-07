<?php

namespace App\Form;

use App\Entity\LigneCommande;
use App\Entity\Product;
use App\Repository\JourDistribRepository;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Bridge\Doctrine\Form\Type\EntityType;
use Symfony\Component\Form\Extension\Core\Type\IntegerType;
use Symfony\Component\Form\Extension\Core\DataTransformer\IntegerToLocalizedStringTransformer;
use Symfony\Component\Form\Extension\Core\Type\HiddenType;

class LigneCommandeType extends AbstractType
{

    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('product', EntityType::class, [
                'class' => Product::class,
                'choice_label' => function (Product $product = null) {
                    return $product->getNom() . " - " . $product->getConditionnement() . $product->getUnit() . " - " . $product->getPrixInit() . " €";
                },
                'choice_value' => 'id',
                'choices' => $options['products'],
                'label' => false,
                'required' => true,
                ])
            ->add('quantite', IntegerType::class,[
                'label' => 'ligne_commande.form.quantite',
                'required' => true,
                'attr' => array('min' => 1),
                'data' => 1,
            ])
            ->add('livree', HiddenType::class, [
                'data' => 0,
            ])

        ;
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => LigneCommande::class,
            'products' => Products::class,
        ]);
    }
}
