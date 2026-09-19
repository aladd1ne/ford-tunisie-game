<?php

declare(strict_types=1);

namespace App\Form;

use App\Dto\RegistrationDto;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\EmailType;
use Symfony\Component\Form\Extension\Core\Type\TelType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

/**
 * Formulaire d'inscription à « La Roue Ford ».
 *
 * La protection CSRF est active (comportement par défaut de Symfony Forms)
 * et la validation est entièrement effectuée côté serveur à partir des
 * contraintes portées par RegistrationDto.
 */
class RegistrationType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('firstName', TextType::class, [
                'label' => 'Prénom',
                'required' => true,
                'attr' => [
                    'placeholder' => 'Votre prénom',
                    'autocomplete' => 'given-name',
                    'maxlength' => 80,
                ],
            ])
            ->add('lastName', TextType::class, [
                'label' => 'Nom',
                'required' => true,
                'attr' => [
                    'placeholder' => 'Votre nom',
                    'autocomplete' => 'family-name',
                    'maxlength' => 80,
                ],
            ])
            ->add('company', TextType::class, [
                'label' => 'Société ou agence',
                'required' => true,
                'attr' => [
                    'placeholder' => 'Nom de votre société ou agence',
                    'autocomplete' => 'organization',
                    'maxlength' => 160,
                ],
            ])
            ->add('email', EmailType::class, [
                'label' => 'Adresse e-mail',
                'required' => true,
                'attr' => [
                    'placeholder' => 'prenom.nom@exemple.fr',
                    'autocomplete' => 'email',
                    'maxlength' => 180,
                ],
            ])
            ->add('phone', TelType::class, [
                'label' => 'Téléphone',
                'required' => false,
                'help' => 'Facultatif',
                'attr' => [
                    'placeholder' => '+33 6 12 34 56 78',
                    'autocomplete' => 'tel',
                    'maxlength' => 40,
                ],
            ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => RegistrationDto::class,
            'csrf_protection' => true,
            'csrf_field_name' => '_token',
            'csrf_token_id' => 'inscription_roue_ford',
        ]);
    }
}
