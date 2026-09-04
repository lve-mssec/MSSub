<?php

declare(strict_types=1);

namespace App\Form\Admin;

use App\Entity\NetworkInterface;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

final class NetworkInterfaceType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('name', TextType::class, [
                'label' => 'Nom',
                'help' => 'Tel qu\'il apparaît sur l\'équipement : port1, eth0, Gi1/0/1…',
            ])
            ->add('macAddress', TextType::class, [
                'label' => 'Adresse MAC',
                'required' => false,
                'attr' => ['placeholder' => 'AA:BB:CC:DD:EE:FF'],
            ])
            ->add('description', TextareaType::class, [
                'label' => 'Description',
                'required' => false,
                'attr' => ['rows' => 2],
            ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults(['data_class' => NetworkInterface::class]);
    }
}
