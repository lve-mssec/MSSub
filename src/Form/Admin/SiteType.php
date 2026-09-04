<?php

declare(strict_types=1);

namespace App\Form\Admin;

use App\Entity\Organization;
use App\Entity\Site;
use Symfony\Bridge\Doctrine\Form\Type\EntityType;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\CountryType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

final class SiteType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('organization', EntityType::class, [
                'label' => 'Organisation',
                'class' => Organization::class,
                'choice_label' => 'name',
            ])
            ->add('code', TextType::class, [
                'label' => 'Code',
                'help' => 'Unique dans l\'organisation. C\'est lui que l\'import reconnaît.',
            ])
            ->add('name', TextType::class, ['label' => 'Nom'])
            ->add('city', TextType::class, ['label' => 'Ville', 'required' => false])
            ->add('country', CountryType::class, [
                'label' => 'Pays',
                'required' => false,
                'placeholder' => '— non précisé —',
                'preferred_choices' => ['FR', 'BE', 'CH', 'LU'],
            ])
            ->add('address', TextareaType::class, [
                'label' => 'Adresse',
                'required' => false,
                'attr' => ['rows' => 3],
            ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults(['data_class' => Site::class]);
    }
}
