<?php

namespace App\Form;

use App\Entity\Client;
use App\Entity\Content;
use App\Entity\Format;
use App\Entity\Status;
use App\Entity\User;
use App\Repository\StatusRepository;
use App\Repository\UserRepository;
use App\Service\VideoAssigneeResolver;
use Symfony\Bridge\Doctrine\Form\Type\EntityType;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\Extension\Core\Type\DateType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\Extension\Core\Type\UrlType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\Form\FormEvent;
use Symfony\Component\Form\FormEvents;
use Symfony\Component\OptionsResolver\OptionsResolver;

class VideoContentType extends AbstractType
{
    public function __construct(
        private readonly StatusRepository $statusRepository,
        private readonly UserRepository $userRepository,
        private readonly VideoAssigneeResolver $videoAssigneeResolver,
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

        $builder->addEventListener(FormEvents::PRE_SET_DATA, function (FormEvent $event): void {
            $content = $event->getData();
            if (!$content instanceof Content) {
                return;
            }

            $this->videoAssigneeResolver->applyClientTeamDefaultsForForm($content);

            $form = $event->getForm();
            if ($form->has('videoEditor')) {
                $form->remove('videoEditor');
            }
            $form->add('videoEditor', EntityType::class, [
                'label' => 'Monteur',
                'class' => User::class,
                'choice_label' => 'name',
                'required' => false,
                'placeholder' => $content->getVideoEditor() !== null ? false : '—',
                'query_builder' => fn ($repo) => $repo->createQueryBuilder('u')->orderBy('u.name', 'ASC'),
            ]);

            if ($form->has('videoCommunityManager')) {
                $form->remove('videoCommunityManager');
            }
            $form->add('videoCommunityManager', EntityType::class, [
                'label' => 'Community manager',
                'class' => User::class,
                'choices' => $this->userRepository->findCommunityManagersOrdered(),
                'choice_label' => 'name',
                'required' => false,
                'placeholder' => $content->getVideoCommunityManager() !== null ? false : '—',
            ]);
        });
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => Content::class,
        ]);
    }
}
