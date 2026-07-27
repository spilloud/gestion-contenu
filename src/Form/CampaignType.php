<?php

namespace App\Form;

use App\Entity\Campaign;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\DateType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints\Callback;
use Symfony\Component\Validator\Constraints\NotBlank;
use Symfony\Component\Validator\Context\ExecutionContextInterface;

class CampaignType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('name', TextType::class, [
                'label' => 'Nom de la campagne',
                'constraints' => [new NotBlank()],
                'attr' => ['placeholder' => 'Ex. Campagne printemps Pro Suisse'],
            ])
            ->add('startsOn', DateType::class, [
                'label' => 'Date de début',
                'widget' => 'single_text',
                'input' => 'datetime_immutable',
                'constraints' => [new NotBlank()],
            ])
            ->add('endsOn', DateType::class, [
                'label' => 'Date de fin',
                'widget' => 'single_text',
                'input' => 'datetime_immutable',
                'constraints' => [new NotBlank()],
            ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => Campaign::class,
            'constraints' => [
                new Callback(static function (Campaign $campaign, ExecutionContextInterface $context): void {
                    if ($campaign->getStartsOn() !== null
                        && $campaign->getEndsOn() !== null
                        && $campaign->getEndsOn() < $campaign->getStartsOn()
                    ) {
                        $context->buildViolation('La date de fin doit être postérieure ou égale à la date de début.')
                            ->atPath('endsOn')
                            ->addViolation();
                    }
                }),
            ],
        ]);
    }
}
