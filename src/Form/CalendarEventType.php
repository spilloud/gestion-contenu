<?php

namespace App\Form;

use App\Entity\CalendarEvent;
use App\Entity\Client;
use Symfony\Bridge\Doctrine\Form\Type\EntityType;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\Extension\Core\Type\ColorType;
use Symfony\Component\Form\Extension\Core\Type\DateType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\Form\FormEvent;
use Symfony\Component\Form\FormEvents;
use Symfony\Component\OptionsResolver\OptionsResolver;
class CalendarEventType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('title', TextType::class, [
                'label' => 'Titre',
                'attr' => ['placeholder' => 'Ex. Paléo, Slow-up Schwyz, Pâques…'],
            ])
            ->add('startDate', DateType::class, [
                'label' => 'Date de début',
                'widget' => 'single_text',
            ])
            ->add('endDate', DateType::class, [
                'label' => 'Date de fin',
                'widget' => 'single_text',
                'help' => 'Pour un seul jour, indiquez la même date de début et de fin.',
            ])
            ->add('color', ColorType::class, [
                'label' => 'Couleur de la barre',
            ])
            ->add('forAllClients', CheckboxType::class, [
                'label' => 'Visible pour tous les clients',
                'mapped' => false,
                'required' => false,
                'help' => 'Coché : fêtes, événements nationaux, Paléo pour tout le monde. Décoché : réservé à un client (ex. Slow-up pour le Maréchal).',
            ])
            ->add('client', EntityType::class, [
                'label' => 'Client',
                'class' => Client::class,
                'choice_label' => 'name',
                'placeholder' => 'Choisir un client',
                'required' => false,
                'query_builder' => fn ($repo) => $repo->createQueryBuilder('c')
                    ->andWhere('c.isArchived = false')
                    ->orderBy('c.name', 'ASC'),
            ])
        ;

        $builder->addEventListener(FormEvents::PRE_SET_DATA, function (FormEvent $formEvent): void {
            $calendarEvent = $formEvent->getData();
            if (!$calendarEvent instanceof CalendarEvent) {
                return;
            }
            $formEvent->getForm()->get('forAllClients')->setData($calendarEvent->isGlobal());
        });

        $builder->addEventListener(FormEvents::SUBMIT, function (FormEvent $formEvent): void {
            $calendarEvent = $formEvent->getData();
            if (!$calendarEvent instanceof CalendarEvent) {
                return;
            }
            $form = $formEvent->getForm();
            if ($form->get('forAllClients')->getData()) {
                $calendarEvent->setClient(null);
            } elseif ($calendarEvent->getClient() === null) {
                $form->get('client')->addError(new \Symfony\Component\Form\FormError(
                    'Choisissez un client ou cochez « Visible pour tous les clients ».'
                ));
            }

            if ($calendarEvent->getStartDate() !== null
                && $calendarEvent->getEndDate() !== null
                && $calendarEvent->getEndDate() < $calendarEvent->getStartDate()
            ) {
                $form->get('endDate')->addError(new \Symfony\Component\Form\FormError(
                    'La date de fin doit être postérieure ou égale à la date de début.'
                ));
            }
        });
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => CalendarEvent::class,
        ]);
    }
}
