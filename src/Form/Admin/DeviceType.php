<?php

declare(strict_types=1);

namespace App\Form\Admin;

use App\Entity\Device;
use App\Entity\Site;
use App\Enum\DeviceType as DeviceKind;
use Symfony\Bridge\Doctrine\Form\Type\EntityType;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\EnumType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

final class DeviceType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('name', TextType::class, ['label' => 'Nom'])
            ->add('type', EnumType::class, [
                'label' => 'Type',
                'class' => DeviceKind::class,
                'choice_label' => fn (DeviceKind $kind): string => $kind->label(),
            ])
            ->add('site', EntityType::class, [
                'label' => 'Site',
                'class' => Site::class,
                'required' => false,
                'placeholder' => '— non rattaché —',
                'choice_label' => 'name',
            ])
            ->add('vendor', TextType::class, ['label' => 'Constructeur', 'required' => false])
            ->add('model', TextType::class, ['label' => 'Modèle', 'required' => false])
            ->add('serialNumber', TextType::class, ['label' => 'Numéro de série', 'required' => false])
            ->add('description', TextareaType::class, [
                'label' => 'Description',
                'required' => false,
                'attr' => ['rows' => 2],
            ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults(['data_class' => Device::class]);
    }
}
