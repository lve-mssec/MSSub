<?php

declare(strict_types=1);

namespace App\Form;

use App\Entity\IpAddress;
use App\Entity\NetworkInterface;
use App\Enum\IpStatus;
use Doctrine\ORM\EntityRepository;
use Symfony\Bridge\Doctrine\Form\Type\EntityType;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\EnumType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints as Assert;

final class IpAddressType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('address', TextType::class, [
                'label' => 'Adresse',
                'attr' => ['autofocus' => true],
                'constraints' => [
                    new Assert\NotBlank(),
                    new Assert\Ip(version: Assert\Ip::ALL),
                ],
            ])
            ->add('status', EnumType::class, [
                'label' => 'État',
                'class' => IpStatus::class,
                'choice_label' => fn (IpStatus $status): string => $status->label(),
            ])
            ->add('hostname', TextType::class, [
                'label' => "Nom d'hôte",
                'required' => false,
            ])
            ->add('macAddress', TextType::class, [
                'label' => 'Adresse MAC',
                'required' => false,
                'attr' => ['placeholder' => 'AA:BB:CC:DD:EE:FF'],
            ])
            ->add('interface', EntityType::class, [
                'label' => 'Interface',
                'class' => NetworkInterface::class,
                'required' => false,
                'placeholder' => '— aucune —',
                'choice_label' => fn (NetworkInterface $i): string => (string) $i,
                'query_builder' => fn (EntityRepository $r) => $r->createQueryBuilder('i')
                    ->leftJoin('i.device', 'd')
                    ->orderBy('d.name', 'ASC')
                    ->addOrderBy('i.name', 'ASC'),
            ])
            ->add('description', TextareaType::class, [
                'label' => 'Description',
                'required' => false,
                'attr' => ['rows' => 2],
            ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults(['data_class' => IpAddress::class]);
    }
}
