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
        // Vente déjà choisie : gardée dans la liste même si elle est passée
        $current = $options['data'] instanceof Newsletter ? $options['data']->getJourDistrib() : null;

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
                // Ventes à venir : distribution aujourd'hui ou plus tard
                'query_builder' => function (EntityRepository $repository) use ($current) {
                    $qb = $repository->createQueryBuilder('j')
                        ->where('COALESCE(j.dateLivraison, j.date) >= :today')
                        ->setParameter('today', new \DateTime('today'))
                        ->orderBy('j.date', 'ASC');
                    if (null !== $current) {
                        $qb->orWhere('j = :current')->setParameter('current', $current);
                    }

                    return $qb;
                },
                'choice_label' => function (JourDistrib $jour) {
                    $count = $jour->getCommandes()->count();
                    $label = sprintf('Vente du %s', $jour->getDate()->format('d/m/Y'));
                    if (null !== $jour->getDateLivraison()) {
                        $label .= sprintf(', distribution le %s', $jour->getDateLivraison()->format('d/m/Y'));
                    }

                    return $label . sprintf(' (%d commande%s)', $count, $count > 1 ? 's' : '');
                },
                'help' => 'Ventes à venir uniquement. Seuls les abonnés qui ont commandé sur la vente choisie reçoivent la newsletter.',
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
