<?php

declare(strict_types=1);

namespace App\Form\Admin;

use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
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
            ->add('encryption', ChoiceType::class, [
                'label' => 'Chiffrement',
                'required' => false,
                'placeholder' => 'Déduire de l\'URL',
                'choices' => [
                    'LDAPS — chiffré d\'emblée, port 636' => 'ldaps',
                    'StartTLS — port 389 élevé en TLS' => 'starttls',
                    'Aucun — déconseillé' => 'aucun',
                ],
                'help' => 'Active Directory refuse les liaisons non chiffrées depuis Windows Server 2019 : '
                    .'choisissez LDAPS ou StartTLS.',
            ])
            ->add('tls_verification', ChoiceType::class, [
                'label' => 'Vérification du certificat',
                'required' => false,
                'placeholder' => 'Vérifier (recommandé)',
                'choices' => [
                    'Vérifier le certificat du serveur' => 'oui',
                    'Ne pas vérifier — mise au point uniquement' => 'non',
                ],
                'help' => 'Sans vérification, la liaison reste chiffrée mais n\'authentifie plus le serveur.',
            ])
            ->add('tls_ca', TextType::class, [
                'label' => 'Autorité de certification',
                'required' => false,
                'help' => 'Chemin d\'un fichier PEM, si votre annuaire utilise une autorité interne. '
                    .'Par exemple /usr/local/share/ca-certificates/ad-interne.crt',
            ])
            ->add('groupes_imbriques', ChoiceType::class, [
                'label' => 'Groupes imbriqués',
                'required' => false,
                'placeholder' => 'Résoudre (recommandé)',
                'choices' => [
                    'Résoudre toute la chaîne d\'appartenance' => 'oui',
                    'Appartenance directe seulement' => 'non',
                ],
                'help' => 'Un compte est rarement membre direct du groupe qui porte le droit : '
                    .'il passe par un groupe global, lui-même membre d\'un groupe local de domaine. '
                    .'Sans résolution, ces comptes entrent en lecture seule.',
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
                'help' => 'Facultatif. Les parenthèses extérieures sont ajoutées si vous les omettez : '
                    .'« objectClass=user » et « (objectClass=user) » sont équivalents. '
                    .'Pour ne retenir que les comptes actifs d\'un Active Directory : '
                    .'(&(objectClass=user)(!(userAccountControl:1.2.840.113556.1.4.803:=2)))',
            ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults(['data_class' => null]);
    }
}
