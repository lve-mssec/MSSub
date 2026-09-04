<?php

declare(strict_types=1);

namespace App\Form\Admin;

use App\Entity\User;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\EmailType;
use Symfony\Component\Form\Extension\Core\Type\PasswordType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Édition d'un compte par un administrateur.
 *
 * Le mot de passe n'apparaît que pour un compte local : un compte d'annuaire ou
 * de SSO n'en possède pas ici, et en proposer un laisserait croire à une
 * seconde voie d'entrée qui n'existe pas.
 */
final class UserType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('username', TextType::class, [
                'label' => 'Identifiant de connexion',
                'disabled' => !$options['with_password'],
                'help' => $options['with_password']
                    ? null
                    : 'Fourni par la source d\'authentification : non modifiable ici.',
            ])
            ->add('displayName', TextType::class, ['label' => 'Nom affiché', 'required' => false])
            ->add('email', EmailType::class, ['label' => 'Courriel', 'required' => false])
            ->add('roles', ChoiceType::class, [
                'label' => 'Rôles',
                'multiple' => true,
                'expanded' => true,
                'choices' => [
                    'Lecture seule' => User::ROLE_USER,
                    'Opérateur — peut modifier le référentiel' => User::ROLE_OPERATOR,
                    'Administrateur — peut supprimer et configurer' => User::ROLE_ADMIN,
                ],
            ])
            ->add('active', CheckboxType::class, [
                'label' => 'Compte actif',
                'required' => false,
                'help' => 'Un compte inactif est refusé quelle que soit sa source.',
            ]);

        if ($options['with_password']) {
            $builder->add('password', PasswordType::class, [
                'label' => 'Mot de passe',
                'required' => $options['new'],
                'mapped' => false,
                'always_empty' => true,
                'help' => $options['new']
                    ? 'Douze caractères au minimum.'
                    : 'Laisser vide pour conserver le mot de passe actuel.',
                // Un champ facultatif laissé vide vaut null, que Length ignore :
                // la contrainte ne s'applique donc qu'à une saisie réelle.
                'constraints' => $options['new']
                    ? [new Assert\NotBlank(), new Assert\Length(min: 12)]
                    : [new Assert\Length(min: 12)],
            ]);
        }
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver
            ->setDefaults([
                'data_class' => User::class,
                'with_password' => true,
                'new' => false,
            ])
            ->setAllowedTypes('with_password', 'bool')
            ->setAllowedTypes('new', 'bool');
    }
}
