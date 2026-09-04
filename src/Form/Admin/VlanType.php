<?php

declare(strict_types=1);

namespace App\Form\Admin;

use App\Entity\Site;
use App\Entity\Vlan;
use Symfony\Bridge\Doctrine\Form\Type\EntityType;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\IntegerType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

final class VlanType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('number', IntegerType::class, [
                'label' => 'Numéro',
                'help' => 'De 1 à 4094 : 0 et 4095 sont réservés par la norme 802.1Q.',
            ])
            ->add('name', TextType::class, ['label' => 'Nom'])
            ->add('site', EntityType::class, [
                'label' => 'Site',
                'class' => Site::class,
                'required' => false,
                'placeholder' => '— transverse à l\'organisation —',
                'choice_label' => 'name',
                'help' => 'Sans site, le numéro n\'est plus contraint à l\'unicité.',
            ])
            ->add('description', TextareaType::class, [
                'label' => 'Description',
                'required' => false,
                'attr' => ['rows' => 2],
            ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults(['data_class' => Vlan::class]);
    }
}
