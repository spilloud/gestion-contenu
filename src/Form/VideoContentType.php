<?php

namespace App\Form;

use App\Entity\Client;
use App\Entity\Content;
use App\Entity\Format;
use App\Entity\Status;
use App\Entity\User;
use App\Repository\StatusRepository;
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
    public function __construct(
        private readonly StatusRepository $statusRepository,
    ) {
    }

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
                    ->andWhere('c.isArchived = false')
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
                'label' => 'Statut (réglage manuel)',
                'class' => Status::class,
                'choice_label' => 'name',
                'query_builder' => fn () => $this->statusRepository->createQueryBuilder('s')
                    ->andWhere('s.workflow IN (:workflows)')
                    ->setParameter('workflows', [Status::WORKFLOW_VIDEO, Status::WORKFLOW_BOTH])
                    ->orderBy('s.sortOrder', 'ASC')
                    ->addOrderBy('s.name', 'ASC'),
                'help' => 'Préférez les boutons d\'avancement ci-dessus ; le menu sert aux corrections.',
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
                'label' => 'Monteur (tâche Asana montage)',
                'class' => User::class,
                'choice_label' => 'name',
                'required' => false,
                'placeholder' => '— Par défaut : monteur du client',
                'query_builder' => fn ($repo) => $repo->createQueryBuilder('u')->orderBy('u.name', 'ASC'),
                'help' => 'Changer le monteur réassigne la tâche Asana montage (si elle existe).',
            ])
            ->add('videoCmUser', EntityType::class, [
                'label' => 'CM déléguée',
                'class' => User::class,
                'choice_label' => 'name',
                'required' => false,
                'placeholder' => '— CM du client par défaut',
                'query_builder' => fn ($repo) => $repo->createQueryBuilder('u')->orderBy('u.name', 'ASC'),
                'help' => 'Utilisateur avec gid Asana. Impacte la relecture sous-titres si aucun relecteur dédié.',
            ])
            ->add('videoSubtitlesReviewer', EntityType::class, [
                'label' => 'Relecteur sous-titres',
                'class' => User::class,
                'choice_label' => 'name',
                'required' => false,
                'placeholder' => '— CM déléguée ou CM client',
                'query_builder' => fn ($repo) => $repo->createQueryBuilder('u')->orderBy('u.name', 'ASC'),
                'help' => 'Changer le relecteur réassigne la tâche Asana relecture (si elle existe).',
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
