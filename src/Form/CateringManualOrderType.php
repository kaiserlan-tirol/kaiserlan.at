<?php

namespace App\Form;

use App\Entity\CateringProduct;
use App\Entity\User;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\IntegerType;
use Symfony\Component\Form\Extension\Core\Type\SubmitType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints as Assert;

class CateringManualOrderType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        /** @var CateringProduct[] $products */
        $products = $options['products'] ?? [];

        // Add user selection field
        $builder->add('user', UserSelectType::class, [
            'label' => 'Benutzer auswählen',
            'attr' => [
                'class' => 'form-control',
                'placeholder' => 'Nach Benutzer suchen...'
            ]
        ]);

        // Add product quantity fields
        foreach ($products as $product) {
            $builder->add('product' . $product->getId(), IntegerType::class, [
                'label' => $product->getName(),
                'required' => false,
                'attr' => [
                    'min' => 0,
                    'max' => 20,
                    'data-price' => $product->getPrice(),
                    'data-product-id' => $product->getId(),
                    'class' => 'form-control product-quantity',
                ],
                'constraints' => [
                    new Assert\Range(['min' => 0, 'max' => 20])
                ],
                'data' => 0,
            ]);
        }

        $builder->add('submit', SubmitType::class, [
            'label' => 'Bestellung erfassen',
            'attr' => ['class' => 'btn btn-primary']
        ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'products' => [],
        ]);
    }
}
