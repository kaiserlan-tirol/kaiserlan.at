<?php

namespace App\Tests\Unit\Service;

use App\Entity\IncomingPayment;
use App\Entity\User;
use App\Idm\IdmRepository;
use App\Repository\ShopOrderRepository;
use App\Service\CateringService;
use App\Service\PaymentProcessingService;
use App\Service\ShopService;
use App\Service\TransactionService;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Ramsey\Uuid\Uuid;

class PaymentProcessingServiceTest extends TestCase
{
    /**
     * The catering service books the leftover as credit itself. The payment
     * processing must not book that same leftover a second time.
     */
    public function testLeftoverIsCreditedOnlyOnce(): void
    {
        $user = new User();
        $user->setUuid(Uuid::uuid4());

        $payment = $this->createMock(IncomingPayment::class);
        $payment->method('getId')->willReturn(31);
        $payment->method('getMatchedUser')->willReturn($user->getUuid());
        $payment->method('getAmountInCents')->willReturn(7450);

        $userRepo = $this->createMock(IdmRepository::class);
        $userRepo->method('findOneById')->willReturn($user);

        $transactionService = $this->createMock(TransactionService::class);
        $transactionService->method('isDuplicatePayment')->willReturn(false);

        $shopService = $this->createMock(ShopService::class);
        $shopService->method('getOrderByUser')->willReturn([]);

        $cateringService = $this->createMock(CateringService::class);
        // No open catering orders: the whole amount is booked as credit here.
        $cateringService->expects($this->once())
            ->method('processPayment')
            ->willReturn([
                'orders_processed' => 0,
                'amount_used' => 0,
                'amount_credited' => 7450,
            ]);
        // ...so no second credit booking may happen.
        $cateringService->expects($this->never())->method('addUserCredit');

        $service = $this->buildService($cateringService, $shopService, $userRepo, $transactionService);

        $result = $service->processPayment($payment);

        $this->assertSame(7450, $result['credit_added']);
        $this->assertSame(7450, $result['total_amount']);
    }

    /**
     * A shop order must reduce the available amount exactly once.
     */
    public function testShopOrderAmountIsDeductedOnlyOnce(): void
    {
        $user = new User();
        $user->setUuid(Uuid::uuid4());

        $payment = $this->createMock(IncomingPayment::class);
        $payment->method('getId')->willReturn(32);
        $payment->method('getMatchedUser')->willReturn($user->getUuid());
        $payment->method('getAmountInCents')->willReturn(5000);

        $userRepo = $this->createMock(IdmRepository::class);
        $userRepo->method('findOneById')->willReturn($user);

        $transactionService = $this->createMock(TransactionService::class);
        $transactionService->method('isDuplicatePayment')->willReturn(false);

        $order = $this->createMock(\App\Entity\ShopOrder::class);
        $order->method('calculateTotal')->willReturn(2000);
        $order->method('getCreatedAt')->willReturn(new \DateTimeImmutable());
        $order->method('getId')->willReturn(7);

        $shopService = $this->createMock(ShopService::class);
        $shopService->method('getOrderByUser')->willReturn([$order]);

        $cateringService = $this->createMock(CateringService::class);
        // 5000 - 2000 shop order = 3000 must reach the catering step.
        $cateringService->expects($this->once())
            ->method('processPayment')
            ->with($user, 3000, $this->anything())
            ->willReturn([
                'orders_processed' => 0,
                'amount_used' => 0,
                'amount_credited' => 3000,
            ]);

        $service = $this->buildService($cateringService, $shopService, $userRepo, $transactionService);

        $result = $service->processPayment($payment);

        $this->assertSame(2000, $result['shop_amount_used']);
        $this->assertSame(3000, $result['credit_added']);
    }

    /**
     * IdmManager is final and cannot be mocked, so the service is built without
     * its constructor and the collaborators are injected directly.
     */
    private function buildService(
        CateringService $cateringService,
        ShopService $shopService,
        IdmRepository $userRepo,
        TransactionService $transactionService
    ): PaymentProcessingService {
        $reflection = new \ReflectionClass(PaymentProcessingService::class);
        $service = $reflection->newInstanceWithoutConstructor();

        foreach ([
            'cateringService' => $cateringService,
            'shopService' => $shopService,
            'userRepo' => $userRepo,
            'shopOrderRepository' => $this->createMock(ShopOrderRepository::class),
            'transactionService' => $transactionService,
            'logger' => new NullLogger(),
        ] as $name => $value) {
            $property = $reflection->getProperty($name);
            $property->setAccessible(true);
            $property->setValue($service, $value);
        }

        return $service;
    }
}
