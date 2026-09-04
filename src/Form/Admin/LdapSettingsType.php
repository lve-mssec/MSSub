<?php

declare(strict_types=1);

namespace App\Form\Admin;

use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\Extension\Core\Type\PasswordType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

final class LdapSettingsType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('enabled', CheckboxType::class, [
                'label' => 'Autoriser les connexions par l\'annuaire',
                'required' => false,
            ])
            ->add('url', TextType::class, [
                'label' => 'Serveur',
                'required' => false,
                'help' => 'ldap://serveur:389 ou ldaps://serveur:636',
            ])
            ->add('base_dn', TextType::class, [
                'label' => 'Base de recherche',
                'required' => false,
                'help' => 'dc=mssec,dc=local',
            ])
            ->add('search_dn', TextType::class, [
                'label' => 'Compte de service',
                'required' => false,
                'help' => 'Sert à retrouver le DN d\'un utilisateur, et à lire ses groupes.',
            ])
            ->add('search_password', PasswordType::class, [
                'label' => 'Mot de passe du compte de service',
                'required' => false,
                'always_empty' => true,
                // Le secret enregistré n'est jamais renvoyé au navigateur :
                // un champ laissé vide conserve la valeur en place.
                'help' => 'Laisser vide pour conserver le mot de passe enregistré.',
            ])
            ->add('uid_key', TextType::class, [
                'label' => 'Attribut d\'identifiant',
                'required' => false,
                'help' => 'uid pour OpenLDAP, sAMAccountName pour Active Directory.',
            ])
            ->add('extra_filter', TextType::class, [
                'label' => 'Filtre additionnel',
                'required' => false,
                'help' => 'Facultatif, par exemple (objectClass=user) pour restreindre aux comptes.',
            ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults(['data_class' => null]);
    }
}
