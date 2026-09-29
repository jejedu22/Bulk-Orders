<?php

namespace App\Form;

use App\Entity\Category;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\IntegerType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\Form\FormEvent;
use Symfony\Component\Form\FormEvents;
use Symfony\Component\OptionsResolver\OptionsResolver;

class CategoryType extends AbstractType
{
    /**
     * Icônes proposées (Font Awesome 5 Free, style « solid »), par thème.
     */
    const ICONS = [
        'Fruits & légumes' => [
            'Pomme' => 'fas fa-apple-alt',
            'Carotte' => 'fas fa-carrot',
            'Citron' => 'fas fa-lemon',
            'Piment' => 'fas fa-pepper-hot',
            'Pousse' => 'fas fa-seedling',
            'Feuille' => 'fas fa-leaf',
        ],
        'Épicerie' => [
            'Pain' => 'fas fa-bread-slice',
            'Biscuit' => 'fas fa-cookie',
            'Gaufre' => 'fas fa-stroopwafel',
            'Bonbon' => 'fas fa-candy-cane',
            'Mortier (épices)' => 'fas fa-mortar-pestle',
            'Couverts' => 'fas fa-utensils',
            'Cuillère' => 'fas fa-utensil-spoon',
        ],
        'Produits frais' => [
            'Fromage' => 'fas fa-cheese',
            'Œuf' => 'fas fa-egg',
            'Poisson' => 'fas fa-fish',
            'Viande' => 'fas fa-drumstick-bite',
            'Charcuterie' => 'fas fa-bacon',
            'Glace' => 'fas fa-ice-cream',
        ],
        'Boissons & huiles' => [
            'Goutte (huile)' => 'fas fa-tint',
            'Bidon' => 'fas fa-oil-can',
            'Café' => 'fas fa-coffee',
            'Boisson chaude' => 'fas fa-mug-hot',
            'Vin' => 'fas fa-wine-bottle',
            'Verre de vin' => 'fas fa-wine-glass-alt',
            'Bière' => 'fas fa-beer',
            'Cocktail' => 'fas fa-cocktail',
        ],
        'Maison & hygiène' => [
            'Savon' => 'fas fa-soap',
            'Distributeur de savon' => 'fas fa-pump-soap',
            'Spray' => 'fas fa-spray-can',
            'Papier toilette' => 'fas fa-toilet-paper',
            'Bien-être' => 'fas fa-spa',
            'Pilules' => 'fas fa-pills',
            'Bébé' => 'fas fa-baby',
            'Animaux' => 'fas fa-paw',
            'Vêtement' => 'fas fa-tshirt',
        ],
        'Divers' => [
            'Carton' => 'fas fa-box-open',
            'Panier' => 'fas fa-shopping-basket',
            'Sac' => 'fas fa-shopping-bag',
            'Cadeau' => 'fas fa-gift',
            'Étoile' => 'fas fa-star',
            'Cœur' => 'fas fa-heart',
            'Arbre' => 'fas fa-tree',
            'Étiquette' => 'fas fa-tag',
        ],
    ];

    public function buildForm(FormBuilderInterface $builder, array $options)
    {
        $builder
            ->add('nom', TextType::class, [
                'label' => 'category.form.nom',
            ])
            ->add('position', IntegerType::class, [
                'label' => 'category.form.position',
                'help' => 'category.form.position_help',
                'required' => false,
                'empty_data' => '0',
            ])
        ;

        // Ajouté à PRE_SET_DATA pour conserver une icône saisie auparavant
        // qui ne ferait pas partie de la liste.
        $builder->addEventListener(FormEvents::PRE_SET_DATA, function (FormEvent $event) {
            $choices = self::ICONS;
            $category = $event->getData();
            $current = $category ? $category->getIcon() : null;
            if ($current && !in_array($current, array_merge(...array_values($choices)), true)) {
                $choices['Actuelle'] = [$current => $current];
            }

            $event->getForm()->add('icon', ChoiceType::class, [
                'label' => 'category.form.icon',
                'choices' => $choices,
                'required' => false,
                'placeholder' => 'category.form.icon_none',
                'choice_translation_domain' => false,
            ]);
        });
    }

    public function configureOptions(OptionsResolver $resolver)
    {
        $resolver->setDefaults([
            'data_class' => Category::class,
        ]);
    }
}
