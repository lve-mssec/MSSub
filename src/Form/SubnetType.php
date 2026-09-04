<?php

declare(strict_types=1);

namespace App\Form;

use App\Entity\Organization;
use App\Entity\Site;
use App\Entity\Subnet;
use App\Entity\Vlan;
use App\Enum\SubnetStatus;
use Doctrine\ORM\EntityRepository;
use Symfony\Bridge\Doctrine\Form\Type\EntityType;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\CallbackTransformer;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\Extension\Core\Type\EnumType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints as Assert;

final class SubnetType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $organization = $options['organization'];
        \assert($organization instanceof Organization);

        if ($options['with_cidr']) {
            // Le réseau n'est saisi qu'à la création. Le déplacer reviendrait à
            // renuméroter tout ce qu'il contient : c'est une suppression suivie
            // d'une création, pas une modification.
            $builder->add('cidr', TextType::class, [
                'label' => 'Réseau (notation CIDR)',
                'mapped' => false,
                'attr' => ['placeholder' => '10.10.50.0/24', 'autofocus' => true],
                'constraints' => [new Assert\NotBlank(message: 'Indiquez un réseau, par exemple 10.10.50.0/24.')],
            ]);
        }

        $builder
            ->add('name', TextType::class, [
                'label' => 'Nom',
                'required' => false,
            ])
            ->add('status', EnumType::class, [
                'label' => 'État',
                'class' => SubnetStatus::class,
                'choice_label' => fn (SubnetStatus $status): string => $status->label(),
            ])
            ->add('site', EntityType::class, [
                'label' => 'Site',
                'class' => Site::class,
                'required' => false,
                'placeholder' => '— aucun —',
                'choice_label' => 'name',
                'query_builder' => fn (EntityRepository $r) => $r->createQueryBuilder('s')
                    ->andWhere('s.organization = :org')
                    ->setParameter('org', $organization)
                    ->orderBy('s.name', 'ASC'),
            ])
            ->add('vlan', EntityType::class, [
                'label' => 'VLAN',
                'class' => Vlan::class,
                'required' => false,
                'placeholder' => '— aucun —',
                'choice_label' => fn (Vlan $vlan): string => \sprintf('%d — %s', $vlan->getNumber(), $vlan->getName()),
                'query_builder' => fn (EntityRepository $r) => $r->createQueryBuilder('v')
                    ->leftJoin('v.site', 'site')
                    ->andWhere('site.organization = :org OR v.site IS NULL')
                    ->setParameter('org', $organization)
                    ->orderBy('v.number', 'ASC'),
            ])
            ->add('gateway', TextType::class, [
                'label' => 'Passerelle',
                'required' => false,
                'constraints' => [new Assert\Ip(version: Assert\Ip::ALL)],
            ])
            ->add('dnsServers', TextType::class, [
                'label' => 'Serveurs DNS (séparés par des virgules)',
                'required' => false,
            ])
            ->add('dhcpEnabled', CheckboxType::class, [
                'label' => 'Plage DHCP',
                'required' => false,
            ])
            ->add('dhcpRangeStart', TextType::class, [
                'label' => 'Début de plage DHCP',
                'required' => false,
                'constraints' => [new Assert\Ip(version: Assert\Ip::ALL)],
            ])
            ->add('dhcpRangeEnd', TextType::class, [
                'label' => 'Fin de plage DHCP',
                'required' => false,
                'constraints' => [new Assert\Ip(version: Assert\Ip::ALL)],
            ])
            ->add('description', TextareaType::class, [
                'label' => 'Description',
                'required' => false,
                'attr' => ['rows' => 3],
            ]);

        // Une liste d'adresses côté modèle, une chaîne lisible côté formulaire.
        $builder->get('dnsServers')->addModelTransformer(new CallbackTransformer(
            static fn (?array $servers): string => null === $servers ? '' : implode(', ', $servers),
            static function (?string $text): ?array {
                $items = array_values(array_filter(array_map('trim', explode(',', (string) $text)), 'strlen'));

                return [] === $items ? null : $items;
            },
        ));
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver
            ->setDefaults([
                'data_class' => Subnet::class,
                'with_cidr' => false,
            ])
            ->setRequired('organization')
            ->setAllowedTypes('organization', Organization::class)
            ->setAllowedTypes('with_cidr', 'bool');
    }
}
