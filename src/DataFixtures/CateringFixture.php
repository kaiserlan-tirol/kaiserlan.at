<?php

namespace App\DataFixtures;

use App\Entity\CateringOrder;
use App\Entity\CateringOrderHistory;
use App\Entity\CateringOrderHistoryAction;
use App\Entity\CateringOrderPosition;
use App\Entity\CateringOrderStatus;
use App\Entity\CateringProduct;
use DateTimeImmutable;
use Doctrine\Bundle\FixturesBundle\Fixture;
use Doctrine\Persistence\ObjectManager;
use Ramsey\Uuid\Uuid;

class CateringFixture extends Fixture
{
    public function load(ObjectManager $manager): void
    {
        // Create sample products
        $product1 = (new CateringProduct())
            ->setName('Cola 0.5L')
            ->setDescription('Erfrischende Cola')
            ->setPrice(250) // 2.50 EUR
            ->setActive(true)
            ->setIncludedInFlat(false)
            ->setProductCode('COLA05')
            ->setSortIndex(1);

        $product2 = (new CateringProduct())
            ->setName('Pizza Margherita')
            ->setDescription('Klassische Pizza mit Tomaten und Mozzarella')
            ->setPrice(850) // 8.50 EUR
            ->setActive(true)
            ->setIncludedInFlat(false)
            ->setProductCode('PIZZA01')
            ->setSortIndex(2);

        $product3 = (new CateringProduct())
            ->setName('Kaffee')
            ->setDescription('Heißer Kaffee')
            ->setPrice(0) // Free
            ->setActive(true)
            ->setIncludedInFlat(true)
            ->setProductCode('COFFEE')
            ->setSortIndex(3);

        $product4 = (new CateringProduct())
            ->setName('Energy Drink')
            ->setDescription('Red Bull oder ähnlich')
            ->setPrice(300) // 3.00 EUR
            ->setActive(true)
            ->setIncludedInFlat(false)
            ->setProductCode('ENERGY')
            ->setSortIndex(4);

        $product5 = (new CateringProduct())
            ->setName('Wasser 0.5L')
            ->setDescription('Stilles Wasser')
            ->setPrice(0) // Free
            ->setActive(true)
            ->setIncludedInFlat(true)
            ->setProductCode('WATER05')
            ->setSortIndex(5);

        $product6 = (new CateringProduct())
            ->setName('Hamburger')
            ->setDescription('Klassischer Hamburger mit Pommes')
            ->setPrice(950) // 9.50 EUR
            ->setActive(false) // Inactive example
            ->setIncludedInFlat(false)
            ->setProductCode('BURGER01')
            ->setSortIndex(6);

        $manager->persist($product1);
        $manager->persist($product2);
        $manager->persist($product3);
        $manager->persist($product4);
        $manager->persist($product5);
        $manager->persist($product6);

        // Create sample users (using existing UUIDs from ShopFixture)
        $user13 = Uuid::fromInteger(strval(13));
        $user14 = Uuid::fromInteger(strval(14));
        $user18 = Uuid::fromInteger(strval(18));

        $orders = [];

        // Order 1: Paid order
        $order1 = (new CateringOrder())
            ->setCreatedAt(new DateTimeImmutable('2024-07-20 14:30'))
            ->setOrderer($user13)
            ->setStatus(CateringOrderStatus::Paid);

        $position1 = (new CateringOrderPosition())
            ->fillWithProduct($product1)
            ->setQuantity(2);
        $position2 = (new CateringOrderPosition())
            ->fillWithProduct($product2)
            ->setQuantity(1);
        $position3 = (new CateringOrderPosition())
            ->fillWithProduct($product3)
            ->setQuantity(3);

        $order1->addCateringOrderPosition($position1)
            ->addCateringOrderPosition($position2)
            ->addCateringOrderPosition($position3)
            ->addCateringOrderHistory(
                (new CateringOrderHistory())
                    ->setAction(CateringOrderHistoryAction::OrderCreated)
                    ->setLoggedAt(new DateTimeImmutable('2024-07-20 14:30'))
                    ->setText('Order created')
            )
            ->addCateringOrderHistory(
                (new CateringOrderHistory())
                    ->setAction(CateringOrderHistoryAction::PaymentSuccessful)
                    ->setLoggedAt(new DateTimeImmutable('2024-07-20 15:15'))
                    ->setText('Paid in cash')
            );

        // Order 2: Open order
        $order2 = (new CateringOrder())
            ->setCreatedAt(new DateTimeImmutable('2024-07-21 12:15'))
            ->setOrderer($user14)
            ->setStatus(CateringOrderStatus::Created);

        $position4 = (new CateringOrderPosition())
            ->fillWithProduct($product4)
            ->setQuantity(1);
        $position5 = (new CateringOrderPosition())
            ->fillWithProduct($product5)
            ->setQuantity(2);

        $order2->addCateringOrderPosition($position4)
            ->addCateringOrderPosition($position5)
            ->addCateringOrderHistory(
                (new CateringOrderHistory())
                    ->setAction(CateringOrderHistoryAction::OrderCreated)
                    ->setLoggedAt(new DateTimeImmutable('2024-07-21 12:15'))
                    ->setText('Order created')
            );

        // Order 3: Canceled order
        $order3 = (new CateringOrder())
            ->setCreatedAt(new DateTimeImmutable('2024-07-21 16:45'))
            ->setOrderer($user18)
            ->setStatus(CateringOrderStatus::Canceled);

        $position6 = (new CateringOrderPosition())
            ->fillWithProduct($product1)
            ->setQuantity(3);

        $order3->addCateringOrderPosition($position6)
            ->addCateringOrderHistory(
                (new CateringOrderHistory())
                    ->setAction(CateringOrderHistoryAction::OrderCreated)
                    ->setLoggedAt(new DateTimeImmutable('2024-07-21 16:45'))
                    ->setText('Order created')
            )
            ->addCateringOrderHistory(
                (new CateringOrderHistory())
                    ->setAction(CateringOrderHistoryAction::OrderCanceled)
                    ->setLoggedAt(new DateTimeImmutable('2024-07-21 16:50'))
                    ->setText('Canceled by user')
            );

        $orders[] = $order1;
        $orders[] = $order2;
        $orders[] = $order3;

        foreach ($orders as $i => $order) {
            $manager->persist($order);
            $this->setReference('catering-order-' . $i, $order);
        }

        $manager->flush();
    }
}
