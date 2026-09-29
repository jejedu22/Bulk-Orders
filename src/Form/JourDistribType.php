<?php

namespace App\Form;

use App\Entity\JourDistrib;
use App\Entity\Product;
use App\Repository\ProductRepository;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Bridge\Doctrine\Form\Type\EntityType;
use Symfony\Component\Form\Extension\Core\Type\NumberType;
use Symfony\Component\Form\Extension\Core\Type\DateType;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Translation\Translator; 

class JourDistribType extends AbstractType
{

    public function buildForm(FormBuilderInterface $builder, array $options)
    {
        $builder->add('closed', CheckboxType::class, [
            'label' => 'jour_distrib.form.closed',
            'required' => false,
        ]);
        $productsOptions = [
            'class' => Product::class,
            'choice_label' => function (Product $product = null) {
                return $product->getNom() . " - " . $product->getConditionnement() . $product->getUnit();
            },
            'choice_value' => 'id',
            // Produits regroupés par catégorie, dans l'ordre des catégories
            'query_builder' => function (ProductRepository $repository) {
                return $repository->createQueryBuilder('p')
                    ->leftJoin('p.category', 'c')
                    ->addSelect('CASE WHEN c.id IS NULL THEN 1 ELSE 0 END AS HIDDEN sansCategorie')
                    ->orderBy('sansCategorie', 'ASC')
                    ->addOrderBy('c.position', 'ASC')
                    ->addOrderBy('c.nom', 'ASC')
                    ->addOrderBy('p.nom', 'ASC');
            },
            'group_by' => function (Product $product) {
                return $product->getCategory() ? $product->getCategory()->getNom() : 'Sans catégorie';
            },
            'multiple' => true,
            'expanded' => true,
            'label' => 'jour_distrib.form.products',
        ];
        if (!$options['edit']) {
            // À la création, tous les produits sont proposés par défaut
            $productsOptions['choice_attr'] = function($val, $key, $index) {
                return array('checked' => true);
            };
        }
        $builder->add('products', EntityType::class, $productsOptions);

        $builder
            ->add('date', DateType::class, [
                'label' => 'jour_distrib.form.date_limite',
                'widget' => 'single_text',
                // 'attr' => ['class' => 'ui-datepicker'],
                // 'format' => 'dd/MM/yyyy',
                // 'html5' => false,
                // 'model_timezone' => 'Europe/Paris',
            ])
            ->add('datelivraison', DateType::class, [
                'label' => 'jour_distrib.form.date_livraison',
                'widget' => 'single_text',
                'required' => false,
                // 'attr' => ['class' => 'ui-datepicker'],
                // 'format' => 'dd/MM/yyyy',
                // 'html5' => false,
                // 'model_timezone' => 'Europe/Paris',
            ])
            ->add('total', NumberType::class,[
                'label' => 'jour_distrib.form.poid_commande'
            ])
            ->add('limite', CheckboxType::class,[
                'label' => 'jour_distrib.form.limite',
                'required' => false,
            ])
            ;
    }

    public function configureOptions(OptionsResolver $resolver)
    {
        $resolver->setDefaults([
            'data_class' => JourDistrib::class,
            'edit' => false,
        ]);
    }
}
