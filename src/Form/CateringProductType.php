<?php

namespace App\Form;

use App\Entity\CateringProduct;
use App\Entity\ShopAddon;
use App\Service\ShopService;
use Symfony\Bridge\Doctrine\Form\Type\EntityType;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\Extension\Core\Type\FileType;
use Symfony\Component\Form\Extension\Core\Type\IntegerType;
use Symfony\Component\Form\Extension\Core\Type\MoneyType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints as Assert;

class CateringProductType extends AbstractType
{
    public function __construct(
        private readonly ShopService $shopService
    ) {
    }
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('name', TextType::class, ['label' => 'Name'])
            ->add('price', MoneyType::class, ['label' => 'Preis', 'divisor' => 100])
            ->add('active', CheckboxType::class, ['label' => 'Aktiv', 'required' => false])
            ->add('image', FileType::class, [
                'label' => 'Produktbild',
                'required' => false,
                'mapped' => false,
                'constraints' => [
                    new Assert\Image([
                        'maxSize' => '2M',
                        'mimeTypes' => [
                            'image/jpeg',
                            'image/png',
                        ],
                        'mimeTypesMessage' => 'Bitte lade ein gültiges Bild hoch (JPG, PNG)',
                    ])
                ],
                'help' => 'Wähle ein Bild aus (optional, max. 2MB, PNG oder JPG)',
            ])
            ->add('includedInAddons', EntityType::class, [
                'class' => ShopAddon::class,
                'choice_label' => 'name',
                'multiple' => true,
                'expanded' => true,
                'choices' => $this->shopService->getAddons(all: true),
                'label' => 'Enthalten in Addons',
                'help' => 'Wähle die Addons aus, bei denen dieses Produkt kostenlos ist',
                'required' => false,
                'by_reference' => false,
            ])
            ->add('productCode', TextType::class, ['label' => 'Produktcode', 'required' => false])
            ->add('sortIndex', IntegerType::class, ['label' => 'Sortierung', 'required' => false, 'attr' => ['min' => 1], 'constraints' => [new Assert\Positive()]])
            ->add('description', TextAreaType::class, ['label' => 'Beschreibung', 'required' => false])
        ;
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => CateringProduct::class,
            'csrf_protection' => false, // Temporarily disable to test
            'csrf_field_name' => '_token',
            'csrf_token_id' => 'cateringToken',
        ]);
    }
}
