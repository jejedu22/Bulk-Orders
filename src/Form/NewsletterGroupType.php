<?php

namespace App\Form;

use App\Entity\NewsletterGroup;
use App\Entity\User;
use Doctrine\ORM\EntityRepository;
use Symfony\Bridge\Doctrine\Form\Type\EntityType;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

class NewsletterGroupType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('name', TextType::class, [
                'label' => 'Nom du groupe',
            ])
            ->add('users', EntityType::class, [
                'label' => 'Membres',
                'class' => User::class,
                'multiple' => true,
                'required' => false,
                'by_reference' => false,
                'query_builder' => function (EntityRepository $repository) {
                    return $repository->createQueryBuilder('u')->orderBy('u.nom', 'ASC')->addOrderBy('u.prenom', 'ASC');
                },
                'choice_label' => function (User $user) {
                    return sprintf('%s %s (%s)', $user->getPrenom(), $user->getNom(), $user->getMail());
                },
                'attr' => ['class' => 'select2', 'data-placeholder' => 'Ajouter des membres…'],
            ])
        ;
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => NewsletterGroup::class,
        ]);
    }
}
