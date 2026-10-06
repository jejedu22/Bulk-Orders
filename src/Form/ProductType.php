<?php

namespace App\Form;

use App\Entity\Category;
use App\Entity\Product;
use App\Repository\CategoryRepository;
use Symfony\Bridge\Doctrine\Form\Type\EntityType;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\MoneyType;
use Symfony\Component\Form\Extension\Core\Type\NumberType;

class ProductType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('nom',TextType::class, [
                'label' => 'product.form.nom',
            ])
            ->add('category', EntityType::class, [
                'class' => Category::class,
                'choice_label' => 'nom',
                'query_builder' => function (CategoryRepository $repository) {
                    return $repository->createQueryBuilder('c')
                        ->orderBy('c.position', \SortDirection::Ascending)
                        ->addOrderBy('c.nom', \SortDirection::Ascending);
                },
                'required' => false,
                'placeholder' => 'category.none',
                'label' => 'product.form.category',
            ])
            ->add('conditionnement',NumberType::class, [
                'label' => 'product.form.conditionnement',
                'scale' => 3,
            ])
            ->add('unit',ChoiceType::class, [
                'choices'  => [
                    'kg' => 'kg',
                    'L' => 'L',
                    'boite' => 'boite'
                ],
                'multiple'=>false,
                'expanded'=>false,
                'label' => 'product.form.unit',
            ])
            ->add('prixInit',MoneyType::class, [
                'label' => 'product.form.prixInit',
            ])
            ->add('prixFinal',MoneyType::class, [
                'label' => 'product.form.prixFinal',
                'required' => false,
            ])
        ;
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => Product::class,
        ]);
    }
}
