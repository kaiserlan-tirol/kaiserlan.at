<?php

namespace App\Service;

use App\Entity\IncomingPayment;
use App\Repository\IncomingPaymentRepository;
use Psr\Log\LoggerInterface;

/**
 * Reports incoming payments that could not be booked and are waiting for a
 * decision. Without this they only sit in the admin list and nobody notices.
 */
class PaymentNotificationService
{
    public const SETTING_RECIPIENT = 'payment.notification.email';

    public function __construct(
        private readonly IncomingPaymentRepository $paymentRepository,
        private readonly SettingService $settingService,
        private readonly EmailService $emailService,
        private readonly LoggerInterface $logger,
    ) {
    }

    /**
     * @return IncomingPayment[]
     */
    public function getOpenCases(): array
    {
        return $this->paymentRepository->findNeedingAttention();
    }

    public function getRecipient(): ?string
    {
        $recipient = trim((string) $this->settingService->get(self::SETTING_RECIPIENT, ''));

        return $recipient === '' ? null : $recipient;
    }

    /**
     * Send one digest covering every open case.
     *
     * @return int number of cases reported; 0 if there was nothing to report
     *             or no recipient is configured
     */
    public function notifyOpenCases(): int
    {
        $cases = $this->getOpenCases();
        if (empty($cases)) {
            return 0;
        }

        $recipient = $this->getRecipient();
        if ($recipient === null) {
            $this->logger->info('Payments need attention but no notification address is configured', [
                'open_cases' => count($cases),
            ]);

            return 0;
        }

        $subject = sprintf('%d Zahlung(en) konnten nicht verarbeitet werden', count($cases));

        if (!$this->emailService->sendNotification($recipient, $subject, $this->buildBody($cases))) {
            $this->logger->error('Could not send payment notification', ['recipient' => $recipient]);

            return 0;
        }

        $this->logger->info('Payment notification sent', [
            'recipient' => $recipient,
            'open_cases' => count($cases),
        ]);

        return count($cases);
    }

    /**
     * @param IncomingPayment[] $cases
     */
    public function buildBody(array $cases): string
    {
        $lines = [
            'Die folgenden Zahlungen konnten nicht automatisch verbucht werden',
            'und warten auf eine Entscheidung:',
            '',
        ];

        foreach ($cases as $payment) {
            $lines[] = sprintf(
                '#%d  %s EUR  %s',
                $payment->getId(),
                $payment->getAmount(),
                $payment->getPayerName() ?? 'unbekannter Zahler'
            );
            $lines[] = '   Eingegangen: ' . ($payment->getCreatedAt()?->format('d.m.Y H:i') ?? 'unbekannt');
            $lines[] = '   Verwendungszweck: ' . ($payment->getReference() ?: '(leer)');
            $lines[] = '   Grund: ' . $this->describeReason($payment);

            if ($payment->getProcessingNotes()) {
                $lines[] = '   Hinweis: ' . $payment->getProcessingNotes();
            }

            $lines[] = '';
        }

        $lines[] = 'Bearbeiten unter /admin/incoming-payment';
        $lines[] = '';
        $lines[] = 'Zahlungen, die dauerhaft nicht verbucht werden sollen, dort auf';
        $lines[] = '"Ignorieren" setzen - dann tauchen sie hier nicht mehr auf.';

        return implode("\n", $lines);
    }

    private function describeReason(IncomingPayment $payment): string
    {
        if ($payment->getMatchedUser() === null) {
            return 'Kein passender Benutzer gefunden';
        }

        if ($payment->getStatus() === IncomingPayment::STATUS_PENDING) {
            return sprintf(
                'Zuordnung nur mit Treffersicherheit "%s" - bitte bestätigen',
                $payment->getMatchConfidence() ?? 'unbekannt'
            );
        }

        return 'Zugeordnet, aber nicht verbuchbar';
    }
}
