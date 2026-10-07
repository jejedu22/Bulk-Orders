<?php

namespace App\Form;

use App\Entity\JourDistrib;
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
            ->add('jourDistrib', EntityType::class, [
                'label' => 'Clients d\'une vente',
                'class' => JourDistrib::class,
                'required' => false,
                'placeholder' => 'Pas de filtre : abonnés avec ou sans commande',
                'query_builder' => function (EntityRepository $repository) {
                    return $repository->createQueryBuilder('j')->orderBy('j.date', 'DESC');
                },
                'choice_label' => function (JourDistrib $jour) {
                    return sprintf('Vente du %s (%d commande%s)', $jour->getDate()->format('d/m/Y'), $jour->getCommandes()->count(), $jour->getCommandes()->count() > 1 ? 's' : '');
                },
                'help' => 'Uniquement les abonnés qui ont passé une commande sur cette vente.',
            ])
            ->add('content', TextareaType::class, [
                'label' => 'Contenu',
                'attr' => ['class' => 'newsletter-editor', 'rows' => 12],
                // Le contenu peut être enregistré vide côté éditeur (« <p><br></p> »)
                'empty_data' => '',
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
