<?php

namespace App\Form;

use App\Entity\User;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\Extension\Core\Type\PasswordType;
use Symfony\Component\Form\Extension\Core\Type\EmailType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\TextType;

use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints\IsTrue;
use Symfony\Component\Validator\Constraints\Length;
use Symfony\Component\Validator\Constraints\NotBlank;

class UserAdminType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('nom',TextType::class, [
                'label' => 'user.form.nom', 
                ])
            ->add('prenom',TextType::class, [
                'label' => 'user.form.prenom', 
                ])
            ->add('roles', ChoiceType::class, [
                'label' => 'user.form.roles.name', 
                'choices' => [
                    'user.form.roles.admin' => 'ROLE_ADMIN',
                    'user.form.roles.user' => 'ROLE_USER',
                ],
                'expanded' => true,
                'multiple' => true,
            ])
            ->add('mail', EmailType::class, [
                'label' => 'user.form.mail', 
            ])
            ->add('newsletter', CheckboxType::class, [
                'label' => 'Reçoit la newsletter',
                'required' => false,
            ])
            ->add('phone',TextType::class, [
                'label' => 'user.form.phone', 
            ])
        ;
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => User::class,
        ]);
    }
}
