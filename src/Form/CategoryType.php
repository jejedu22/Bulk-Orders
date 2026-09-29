<?php

namespace App\Form;

use App\Entity\Category;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\IntegerType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

class CategoryType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options)
    {
        $builder
            ->add('nom', TextType::class, [
                'label' => 'category.form.nom',
            ])
            ->add('icon', TextType::class, [
                'label' => 'category.form.icon',
                'required' => false,
                'help' => 'category.form.icon_help',
                'attr' => ['placeholder' => 'fas fa-apple-alt'],
            ])
            ->add('position', IntegerType::class, [
                'label' => 'category.form.position',
                'help' => 'category.form.position_help',
                'required' => false,
                'empty_data' => '0',
            ])
        ;
    }

    public function configureOptions(OptionsResolver $resolver)
    {
        $resolver->setDefaults([
            'data_class' => Category::class,
        ]);
    }
}
