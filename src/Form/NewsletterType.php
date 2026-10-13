<?php

namespace App\Form;

use App\Entity\JourDistrib;
use App\Entity\Newsletter;
use App\Entity\NewsletterAttachment;
use App\Entity\NewsletterGroup;
use App\Service\NewsletterAttachments;
use Doctrine\ORM\EntityRepository;
use Symfony\Bridge\Doctrine\Form\Type\EntityType;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\FileType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\Form\FormError;
use Symfony\Component\Form\FormEvent;
use Symfony\Component\Form\FormEvents;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints\All;
use Symfony\Component\Validator\Constraints\File;

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
            ->add('orderButton', CheckboxType::class, [
                'label' => 'Ajouter un bouton « Commander » à la fin de l\'e-mail',
                'required' => false,
                'help' => 'Le bouton renvoie vers le site : directement sur la vente choisie ci-dessus si elle est ouverte aux commandes, sinon sur la liste des ventes.',
            ])
            ->add('newAttachments', FileType::class, [
                'label' => 'Ajouter des pièces jointes',
                'mapped' => false,
                'required' => false,
                'multiple' => true,
                'attr' => ['accept' => '.pdf,.png,.jpg,.jpeg,.gif,.webp,.txt,.csv,.zip,.doc,.docx,.xls,.xlsx,.ppt,.pptx,.odt,.ods,.odp'],
                'help' => sprintf(
                    'PDF, images, documents Office ou LibreOffice, CSV, ZIP. %d Mo maximum par fichier, %d Mo en tout.',
                    NewsletterAttachments::MAX_FILE_SIZE / 1048576,
                    NewsletterAttachments::MAX_TOTAL_SIZE / 1048576
                ),
                'constraints' => [new All([new File(
                    maxSize: NewsletterAttachments::MAX_FILE_SIZE,
                    mimeTypes: NewsletterAttachments::MIME_TYPES,
                    maxSizeMessage: 'Le fichier « {{ name }} » est trop lourd ({{ size }} {{ suffix }}) : {{ limit }} {{ suffix }} maximum.',
                    mimeTypesMessage: 'Le fichier « {{ name }} » n\'est pas d\'un type accepté ({{ type }}).',
                )])],
            ])
        ;

        // Pièces jointes déjà enregistrées : cases à cocher pour les retirer
        $builder->addEventListener(FormEvents::PRE_SET_DATA, function (FormEvent $event) {
            $newsletter = $event->getData();
            if (!$newsletter instanceof Newsletter || $newsletter->getAttachments()->isEmpty()) {
                return;
            }
            $event->getForm()->add('removedAttachments', ChoiceType::class, [
                'label' => 'Pièces jointes actuelles',
                'help' => 'Cochez celles à retirer.',
                'mapped' => false,
                'required' => false,
                'multiple' => true,
                'expanded' => true,
                'choices' => $newsletter->getAttachments()->toArray(),
                'choice_value' => function (?NewsletterAttachment $attachment) {
                    return $attachment ? (string) $attachment->getId() : '';
                },
                'choice_label' => function (NewsletterAttachment $attachment) {
                    return sprintf('%s (%s)', $attachment->getOriginalName(), self::formatSize($attachment->getSize()));
                },
            ]);
        });

        // Total des fichiers : ceux qui restent + les nouveaux
        $builder->addEventListener(FormEvents::POST_SUBMIT, function (FormEvent $event) {
            $form = $event->getForm();
            $newsletter = $event->getData();
            $removed = $form->has('removedAttachments') ? (array) $form->get('removedAttachments')->getData() : [];
            $total = 0;
            foreach ($newsletter->getAttachments() as $attachment) {
                if (!\in_array($attachment, $removed, true)) {
                    $total += $attachment->getSize();
                }
            }
            foreach ((array) $form->get('newAttachments')->getData() as $file) {
                $total += (int) $file->getSize();
            }
            if ($total > NewsletterAttachments::MAX_TOTAL_SIZE) {
                $form->get('newAttachments')->addError(new FormError(sprintf(
                    'Les pièces jointes pèsent %s en tout : %d Mo maximum.',
                    self::formatSize($total),
                    NewsletterAttachments::MAX_TOTAL_SIZE / 1048576
                )));
            }
        });
    }

    public static function formatSize(int $bytes): string
    {
        if ($bytes >= 1048576) {
            return number_format($bytes / 1048576, 1, ',', ' ') . ' Mo';
        }

        return max(1, (int) round($bytes / 1024)) . ' Ko';
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => Newsletter::class,
        ]);
    }
}
