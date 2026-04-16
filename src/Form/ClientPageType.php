<?php

namespace App\Form;

use App\Entity\ClientPage;
use App\Form\TodoItemType;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\CollectionType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

class ClientPageType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('importantInfo', TextareaType::class, [
                'label' => 'Informations importantes',
                'required' => false,
                'attr' => ['rows' => 8],
            ])
            ->add('ideas', TextareaType::class, [
                'label' => 'Idées',
                'required' => false,
                'attr' => ['rows' => 8],
            ])
            ->add('todoItems', CollectionType::class, [
                'label' => 'To-do',
                'entry_type' => TodoItemType::class,
                'entry_options' => ['label' => false],
                'allow_add' => true,
                'allow_delete' => true,
                'by_reference' => false,
            ])
        ;
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => ClientPage::class,
        ]);
    }
}
