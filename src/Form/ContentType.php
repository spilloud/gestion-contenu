<?php

namespace App\Form;

use App\Entity\Client;
use App\Entity\Content;
use App\Entity\Format;
use App\Entity\Status;
use App\Entity\User;
use App\Repository\FormatRepository;
use App\Repository\StatusRepository;
use App\Repository\UserRepository;
use App\Service\ContentFormatHelper;
use App\Service\VideoAssigneeResolver;
use App\Service\VideoMontageDueOnResolver;
use App\Workflow\ContentWorkflowRegistry;
use Symfony\Bridge\Doctrine\Form\Type\EntityType;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\DateType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\Extension\Core\Type\UrlType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\Form\FormEvent;
use Symfony\Component\Form\FormEvents;
use Symfony\Component\OptionsResolver\OptionsResolver;

class ContentType extends AbstractType
{
    public function __construct(
        private readonly StatusRepository $statusRepository,
        private readonly FormatRepository $formatRepository,
        private readonly UserRepository $userRepository,
        private readonly ContentFormatHelper $contentFormatHelper,
        private readonly VideoAssigneeResolver $videoAssigneeResolver,
        private readonly VideoMontageDueOnResolver $montageDueOnResolver,
    ) {
    }

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
                'required' => false,
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
                'query_builder' => fn ($repo) => $repo->createQueryBuilder('f')
                    ->orderBy('f.sortOrder', 'ASC')
                    ->addOrderBy('f.name', 'ASC'),
                'help' => 'Pour les vidéos : la date ici correspond à la date de publication. Le dérush sert ensuite à lancer le montage (Asana).',
            ])
            ->add('status', EntityType::class, [
                'label' => 'Statut',
                'class' => Status::class,
                'choice_label' => 'name',
                'choices' => [],
                'help' => 'Par défaut : début de parcours. Si le contenu existe déjà (hors Lucy), choisissez « Prêt à publier » (post) ou « Prête à programmer » (vidéo).',
            ])
            ->add('notes', TextareaType::class, [
                'label' => 'Notes (interne)',
                'required' => false,
            ])
            ->add('videoRushesUrl', UrlType::class, [
                'label' => 'Lien KDrive source',
                'required' => false,
                'attr' => ['placeholder' => 'https://...'],
            ])
            ->add('videoFinalUrl', UrlType::class, [
                'label' => 'Lien KDrive final',
                'required' => false,
                'attr' => ['placeholder' => 'https://...'],
            ])
            ->add('videoCaption', TextareaType::class, [
                'label' => 'Texte / légende du post',
                'required' => false,
            ])
            ->add('asanaMontageDueOn', DateType::class, [
                'label' => 'Date de montage souhaitée',
                'widget' => 'single_text',
                'required' => false,
                'attr' => [
                    'style' => 'max-width: 320px; padding: 0.9rem 0.8rem; font-size: 1.05rem; min-height: 52px;',
                    'class' => 'js-montage-due-input',
                ],
                'help' => 'Échéance de la tâche Asana pour le monteur (par défaut : publication − 3 jours).',
            ]);

        $builder->addEventListener(FormEvents::PRE_SUBMIT, function (FormEvent $event): void {
            $raw = $event->getData();
            if (!is_array($raw)) {
                return;
            }

            $format = null;
            $formatId = $raw['format'] ?? null;
            if ($formatId !== null && $formatId !== '') {
                $format = $this->formatRepository->find($formatId);
            }

            $isVideo = $this->contentFormatHelper->isVideoFormat($format instanceof Format ? $format : null);
            $workflow = $isVideo ? Status::WORKFLOW_VIDEO : Status::WORKFLOW_STANDARD;

            $currentStatus = null;
            $statusId = $raw['status'] ?? null;
            if ($statusId !== null && $statusId !== '') {
                $currentStatus = $this->statusRepository->find($statusId);
            }

            $form = $event->getForm();
            if ($form->has('status')) {
                $form->remove('status');
            }
            $form->add('status', EntityType::class, [
                'label' => 'Statut',
                'class' => Status::class,
                'choice_label' => 'name',
                'choices' => $this->statusRepository->findSelectableForWorkflow($workflow, $currentStatus),
                'help' => $isVideo
                    ? 'Contenu déjà produit hors Lucy : choisissez « Prête à programmer » (ou « Programmée » / « Publiée »).'
                    : 'Contenu déjà produit hors Lucy : choisissez « Prêt à publier » (ou « Publiée »).',
            ]);
        });

        $builder->addEventListener(FormEvents::SUBMIT, function (FormEvent $event): void {
            $content = $event->getData();
            if (!$content instanceof Content) {
                return;
            }

            if (!$this->contentFormatHelper->isVideoContent($content)) {
                $content->setAsanaMontageDueOn(null);

                return;
            }

            $statusName = $content->getStatus()?->getName() ?? '';
            if (ContentWorkflowRegistry::isDirectEntryStatusName($statusName, true)) {
                $content->setAsanaMontageDueOn(null);

                return;
            }

            if ($content->getAsanaMontageDueOn() === null && $content->getScheduledDate() !== null) {
                $content->setAsanaMontageDueOn(
                    $this->montageDueOnResolver->defaultFromPublication($content->getScheduledDate()),
                );
            }
        });

        $builder->addEventListener(FormEvents::PRE_SET_DATA, function (FormEvent $event): void {
            $content = $event->getData();
            if (!$content instanceof Content) {
                return;
            }

            $form = $event->getForm();
            $workflow = $this->contentFormatHelper->workflowForContent($content);
            $isNew = $content->getId() === null;
            if ($form->has('status')) {
                $form->remove('status');
            }
            $form->add('status', EntityType::class, [
                'label' => 'Statut',
                'class' => Status::class,
                'choice_label' => 'name',
                'choices' => $this->statusRepository->findSelectableForWorkflow(
                    $workflow,
                    $content->getStatus(),
                ),
                'help' => $isNew
                    ? ($this->contentFormatHelper->isVideoContent($content)
                        ? 'Contenu déjà produit hors Lucy : choisissez « Prête à programmer » (ou « Programmée » / « Publiée »).'
                        : 'Contenu déjà produit hors Lucy : choisissez « Prêt à publier » (ou « Publiée »).')
                    : 'Préférez les boutons d\'avancement ; le menu sert aux corrections.',
            ]);

            if ($this->contentFormatHelper->isVideoContent($content)) {
                if ($content->getAsanaMontageDueOn() === null && $content->getScheduledDate() !== null) {
                    if ($form->has('asanaMontageDueOn')) {
                        $form->get('asanaMontageDueOn')->setData(
                            $this->montageDueOnResolver->defaultFromPublication($content->getScheduledDate()),
                        );
                    }
                }

                return;
            }

            $form = $event->getForm();
            if ($form->has('asanaMontageDueOn')) {
                $form->remove('asanaMontageDueOn');
            }

            $this->videoAssigneeResolver->applyClientTeamDefaultsForForm($content);

            if ($content->getId() === null && $content->getStatus() === null) {
                $initial = $this->statusRepository->findOneByName('Brouillon (idée)');
                if ($initial !== null) {
                    $content->setStatus($initial);
                }
            }

            $form = $event->getForm();
            if ($form->has('videoEditor')) {
                $form->remove('videoEditor');
            }
            $form->add('videoEditor', EntityType::class, [
                'label' => 'Médiamaticien',
                'class' => User::class,
                'choice_label' => 'name',
                'required' => false,
                'placeholder' => $content->getVideoEditor() !== null ? false : '—',
                'choices' => $this->userRepository->findEditorsOrdered($content->getVideoEditor()),
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
