<?php

namespace App\Form;

use App\Entity\Client;
use App\Entity\Content;
use App\Entity\Format;
use App\Entity\Status;
use App\Entity\User;
use Symfony\Bridge\Doctrine\Form\Type\EntityType;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\Extension\Core\Type\DateType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\Extension\Core\Type\UrlType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

class VideoContentType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('title', TextType::class, [
                'label' => 'Titre de la vidéo',
                'attr' => ['placeholder' => 'Titre de la vidéo'],
            ])
            ->add('scheduledDate', DateType::class, [
                'label' => 'Date de publication prévue',
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
                'disabled' => true,
            ])
            ->add('status', EntityType::class, [
                'label' => 'Statut',
                'class' => Status::class,
                'choice_label' => 'name',
            ])
            ->add('notes', TextareaType::class, [
                'label' => 'Notes (interne)',
                'required' => false,
            ])
            ->add('videoHasSubtitles', CheckboxType::class, [
                'label' => false,
                'required' => false,
            ])
            ->add('videoEditor', EntityType::class, [
                'label' => 'Monteur',
                'class' => User::class,
                'choice_label' => 'name',
                'required' => false,
                'placeholder' => '—',
            ])
            ->add('videoRushesUrl', UrlType::class, [
                'label' => 'Lien KDrive rushs (dossier)',
                'required' => false,
                'attr' => ['placeholder' => 'https://...'],
            ])
            ->add('videoEditUrl', UrlType::class, [
                'label' => 'Lien KDrive montage',
                'required' => false,
                'attr' => ['placeholder' => 'https://...'],
            ])
            ->add('videoEditFilename', TextType::class, [
                'label' => 'Nom fichier montage',
                'required' => false,
            ])
            ->add('videoSubmagicUrl', UrlType::class, [
                'label' => 'Lien SubMagic (si sous-titres)',
                'required' => false,
                'attr' => ['placeholder' => 'https://...'],
            ])
            ->add('videoFinalUrl', UrlType::class, [
                'label' => 'Lien KDrive final',
                'required' => false,
                'attr' => ['placeholder' => 'https://...'],
            ])
            ->add('videoFinalFilename', TextType::class, [
                'label' => 'Nom fichier final',
                'required' => false,
            ])
            ->add('videoThumbnailUrl', UrlType::class, [
                'label' => 'Lien miniature (KDrive)',
                'required' => false,
                'attr' => ['placeholder' => 'https://...'],
            ])
            ->add('videoCaption', TextareaType::class, [
                'label' => 'Légende',
                'required' => false,
            ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => Content::class,
        ]);
    }
}
