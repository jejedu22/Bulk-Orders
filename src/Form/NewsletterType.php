<?php

namespace App\Form;

use App\Entity\Newsletter;
use App\Entity\NewsletterGroup;
use Doctrine\ORM\EntityRepository;
use Symfony\Bridge\Doctrine\Form\Type\EntityType;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

class NewsletterType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('subject', TextType::class, [
                'label' => 'Sujet',
            ])
            ->add('groups', EntityType::class, [
                'label' => 'Destinataires',
                'class' => NewsletterGroup::class,
                'multiple' => true,
                'expanded' => true,
                'required' => false,
                'by_reference' => false,
                'query_builder' => function (EntityRepository $repository) {
                    return $repository->createQueryBuilder('g')->orderBy('g.name', 'ASC');
                },
                'choice_label' => 'name',
                'help' => 'Aucun groupe coché : tous les abonnés. Sinon, les abonnés membres d\'au moins un des groupes.',
            ])
            ->add('content', TextareaType::class, [
                'label' => 'Contenu',
                'attr' => ['class' => 'summernote', 'rows' => 12],
            ])
        ;
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => Newsletter::class,
        ]);
    }
}
