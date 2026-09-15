<?php

namespace App\Tests\Unit\Service;

use App\Entity\IncomingPayment;
use App\Repository\IncomingPaymentRepository;
use App\Service\EmailService;
use App\Service\PaymentNotificationService;
use App\Service\SettingService;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Ramsey\Uuid\Uuid;

class PaymentNotificationServiceTest extends TestCase
{
    public function testNothingIsSentWhenNoCasesAreOpen(): void
    {
        $emailService = $this->createMock(EmailService::class);
        $emailService->expects($this->never())->method('sendNotification');

        $service = $this->buildService([], 'admin@example.org', $emailService);

        $this->assertSame(0, $service->notifyOpenCases());
    }

    /**
     * An unconfigured address must not cost the run an error - it only means
     * nobody asked to be told.
     */
    public function testNothingIsSentWithoutRecipient(): void
    {
        $emailService = $this->createMock(EmailService::class);
        $emailService->expects($this->never())->method('sendNotification');

        $service = $this->buildService([$this->unmatchedPayment()], '  ', $emailService);

        $this->assertSame(0, $service->notifyOpenCases());
    }

    public function testOneDigestCoversEveryOpenCase(): void
    {
        $cases = [$this->unmatchedPayment(), $this->mediumConfidencePayment()];

        $emailService = $this->createMock(EmailService::class);
        $emailService->expects($this->once())
            ->method('sendNotification')
            ->with(
                'admin@example.org',
                $this->stringContains('2 Zahlung(en)'),
                $this->anything()
            )
            ->willReturn(true);

        $service = $this->buildService($cases, 'admin@example.org', $emailService);

        $this->assertSame(2, $service->notifyOpenCases());
    }

    public function testBodyNamesTheReasonPerCase(): void
    {
        $service = $this->buildService([], null, $this->createMock(EmailService::class));

        $body = $service->buildBody([
            $this->unmatchedPayment(),
            $this->mediumConfidencePayment(),
            $this->matchedButUnbookablePayment(),
        ]);

        $this->assertStringContainsString('Kein passender Benutzer gefunden', $body);
        $this->assertStringContainsString('Treffersicherheit "medium"', $body);
        $this->assertStringContainsString('Zugeordnet, aber nicht verbuchbar', $body);
        $this->assertStringContainsString('kein Ticket', $body);
    }

    private function unmatchedPayment(): IncomingPayment
    {
        $payment = new IncomingPayment();
        $payment->setAmount('30.00')
            ->setPayerName('Christian Kogler')
            ->setStatus(IncomingPayment::STATUS_PENDING);

        return $payment;
    }

    private function mediumConfidencePayment(): IncomingPayment
    {
        $payment = new IncomingPayment();
        $payment->setAmount('24.00')
            ->setPayerName('Akos Tantu')
            ->setStatus(IncomingPayment::STATUS_PENDING)
            ->setMatchedUser(Uuid::uuid4())
            ->setMatchConfidence(IncomingPayment::CONFIDENCE_MEDIUM);

        return $payment;
    }

    private function matchedButUnbookablePayment(): IncomingPayment
    {
        $payment = new IncomingPayment();
        $payment->setAmount('27.00')
            ->setPayerName('Josef Astlinger')
            ->setStatus(IncomingPayment::STATUS_MATCHED)
            ->setMatchedUser(Uuid::uuid4())
            ->setProcessingNotes('Nicht verarbeitet - der zugeordnete Benutzer hat kein Ticket');

        return $payment;
    }

    /**
     * @param IncomingPayment[] $openCases
     */
    private function buildService(
        array $openCases,
        ?string $recipient,
        EmailService $emailService
    ): PaymentNotificationService {
        $repository = $this->createMock(IncomingPaymentRepository::class);
        $repository->method('findNeedingAttention')->willReturn($openCases);

        $settingService = $this->createMock(SettingService::class);
        $settingService->method('get')->willReturn($recipient);

        return new PaymentNotificationService(
            $repository,
            $settingService,
            $emailService,
            new NullLogger()
        );
    }
}
