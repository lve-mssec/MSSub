<?php

declare(strict_types=1);

namespace App\Form\Admin;

use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\Extension\Core\Type\PasswordType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\Extension\Core\Type\UrlType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

final class OidcSettingsType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('enabled', CheckboxType::class, [
                'label' => 'Proposer la connexion par le fournisseur d\'identité',
                'required' => false,
            ])
            ->add('label', TextType::class, [
                'label' => 'Libellé du bouton',
                'required' => false,
                'help' => 'Affiché sur la page de connexion : « Se connecter avec … ».',
            ])
            ->add('client_id', TextType::class, [
                'label' => 'Identifiant client',
                'required' => false,
            ])
            ->add('client_secret', PasswordType::class, [
                'label' => 'Secret client',
                'required' => false,
                'always_empty' => true,
                'help' => 'Laisser vide pour conserver le secret enregistré.',
            ])
            ->add('authorization_url', UrlType::class, [
                'label' => 'URL d\'autorisation',
                'required' => false,
            ])
            ->add('token_url', UrlType::class, [
                'label' => 'URL du jeton',
                'required' => false,
            ])
            ->add('userinfo_url', UrlType::class, [
                'label' => 'URL des informations utilisateur',
                'required' => false,
            ])
            ->add('scopes', TextType::class, [
                'label' => 'Portées',
                'required' => false,
                'help' => 'Séparées par des espaces. openid est indispensable.',
            ])
            ->add('username_claim', TextType::class, [
                'label' => 'Revendication d\'identifiant',
                'required' => false,
                'help' => 'preferred_username pour Keycloak, upn pour Entra ID.',
            ])
            ->add('groups_claim', TextType::class, [
                'label' => 'Revendication de groupes',
                'required' => false,
                'help' => 'Doit être demandée explicitement chez la plupart des fournisseurs.',
            ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults(['data_class' => null]);
    }
}
