<?php

namespace App\Form;

use App\Entity\Content;
use App\Entity\Client;
use App\Entity\Format;
use App\Entity\Status;
use Symfony\Bridge\Doctrine\Form\Type\EntityType;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\DateType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

class ContentType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('title', TextType::class, [
                'label' => 'Titre du contenu',
                'attr' => ['placeholder' => 'Titre du contenu'],
            ])
            ->add('scheduledDate', DateType::class, [
                'label' => 'Date prévue',
                'widget' => 'single_text',
                'attr' => [
                    'style' => 'max-width: 320px; padding: 0.9rem 0.8rem; font-size: 1.05rem; min-height: 52px;',
                ],
            ])
            ->add('client', EntityType::class, [
                'label' => 'Client',
                'class' => Client::class,
                'choice_label' => 'name',
                'placeholder' => 'Sélectionner un client',
                'query_builder' => fn ($repo) => $repo->createQueryBuilder('c')
                    ->leftJoin('c.communityManager', 'cm')
                    ->orderBy('c.name', 'ASC'),
            ])
            ->add('format', EntityType::class, [
                'label' => 'Format',
                'class' => Format::class,
                'choice_label' => 'name',
            ])
            ->add('status', EntityType::class, [
                'label' => 'Statut',
                'class' => Status::class,
                'choice_label' => 'name',
            ])
            ->add('notes', TextareaType::class, [
                'label' => 'Notes',
                'required' => false,
            ])
        ;
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => Content::class,
        ]);
    }
}
