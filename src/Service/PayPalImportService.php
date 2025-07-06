<?php

namespace App\Service;

use App\Entity\IncomingPayment;
use DateTimeImmutable;
use Psr\Log\LoggerInterface;

class PayPalImportService
{
    private readonly IncomingPaymentService $paymentService;
    private readonly LoggerInterface $logger;

    public function __construct(
        IncomingPaymentService $paymentService,
        LoggerInterface $logger
    ) {
        $this->paymentService = $paymentService;
        $this->logger = $logger;
    }

    /**
     * Import payments from PayPal email parsing (your future cronjob)
     */
    public function importFromEmailParsing(): array
    {
        // This is where your cronjob would parse PayPal emails
        // For now, this is a placeholder that you can implement later
        
        $this->logger->info('PayPal email parsing not yet implemented');
        
        return [
            'processed' => 0,
            'imported' => 0,
            'duplicates' => 0,
            'errors' => []
        ];
    }

    /**
     * Parse a single PayPal email and create payment record
     */
    public function parsePayPalEmail(string $emailContent): ?array
    {
        // Example parsing logic - you'll need to adapt this to your actual PayPal email format
        
        $paymentData = null;
        
        // Try to extract payment information from email
        if ($this->isPayPalPaymentEmail($emailContent)) {
            $paymentData = $this->extractPaymentData($emailContent);
        }
        
        if ($paymentData) {
            try {
                $payment = $this->paymentService->createIncomingPayment(
                    amount: $paymentData['amount'],
                    currency: $paymentData['currency'] ?? 'EUR',
                    timestamp: $paymentData['timestamp'],
                    source: IncomingPayment::SOURCE_PAYPAL,
                    metadata: [
                        'externalId' => $paymentData['transaction_id'],
                        'payerEmail' => $paymentData['payer_email'],
                        'payerName' => $paymentData['payer_name'] ?? null,
                        'reference' => $paymentData['note'] ?? null,
                        'rawEmail' => $emailContent,
                        'paypalTxnId' => $paymentData['transaction_id']
                    ]
                );
                
                return [
                    'success' => true,
                    'payment_id' => $payment->getId(),
                    'amount' => $paymentData['amount']
                ];
            } catch (\Exception $e) {
                $this->logger->error('Failed to create payment from PayPal email', [
                    'error' => $e->getMessage(),
                    'payment_data' => $paymentData
                ]);
                
                return [
                    'success' => false,
                    'error' => $e->getMessage()
                ];
            }
        }
        
        return null;
    }

    /**
     * Check if email is a PayPal payment notification
     */
    private function isPayPalPaymentEmail(string $emailContent): bool
    {
        // Look for PayPal payment indicators
        $indicators = [
            'You\'ve received a payment',
            'Sie haben eine Zahlung erhalten',
            'paypal.com',
            'PayPal',
            'transaction ID',
            'Transaktions-ID'
        ];
        
        foreach ($indicators as $indicator) {
            if (stripos($emailContent, $indicator) !== false) {
                return true;
            }
        }
        
        return false;
    }

    /**
     * Extract payment data from PayPal email content
     */
    private function extractPaymentData(string $emailContent): ?array
    {
        // This is a basic example - you'll need to adapt this to your actual email format
        $data = [];
        
        // Extract amount - look for patterns like "€12.50" or "12,50 EUR"
        if (preg_match('/€(\d+[.,]\d{2})/', $emailContent, $matches)) {
            $data['amount'] = (float) str_replace(',', '.', $matches[1]);
            $data['currency'] = 'EUR';
        } elseif (preg_match('/(\d+[.,]\d{2})\s*EUR/', $emailContent, $matches)) {
            $data['amount'] = (float) str_replace(',', '.', $matches[1]);
            $data['currency'] = 'EUR';
        }
        
        // Extract transaction ID
        if (preg_match('/(?:transaction\s*ID|Transaktions-ID):\s*([A-Z0-9]+)/i', $emailContent, $matches)) {
            $data['transaction_id'] = $matches[1];
        }
        
        // Extract payer email
        if (preg_match('/([a-zA-Z0-9._%+-]+@[a-zA-Z0-9.-]+\.[a-zA-Z]{2,})/', $emailContent, $matches)) {
            $data['payer_email'] = $matches[1];
        }
        
        // Extract note/message
        if (preg_match('/(?:Note|Nachricht|Message):\s*(.+?)(?:\n|$)/i', $emailContent, $matches)) {
            $data['note'] = trim($matches[1]);
        }
        
        // Extract date - this is tricky and depends on email format
        $data['timestamp'] = new DateTimeImmutable(); // Fallback to now
        
        // Return data only if we have essential fields
        if (isset($data['amount']) && isset($data['transaction_id'])) {
            return $data;
        }
        
        return null;
    }

    /**
     * Manual import interface for testing
     */
    public function importFromManualData(array $paymentData): array
    {
        $results = [
            'imported' => 0,
            'duplicates' => 0,
            'errors' => []
        ];
        
        foreach ($paymentData as $data) {
            try {
                $payment = $this->paymentService->createIncomingPayment(
                    amount: $data['amount'],
                    currency: $data['currency'] ?? 'EUR',
                    timestamp: $data['timestamp'] ?? new DateTimeImmutable(),
                    source: IncomingPayment::SOURCE_PAYPAL,
                    metadata: [
                        'externalId' => $data['transaction_id'] ?? null,
                        'payerEmail' => $data['payer_email'] ?? null,
                        'payerName' => $data['payer_name'] ?? null,
                        'reference' => $data['note'] ?? null,
                        'paypalTxnId' => $data['transaction_id'] ?? null
                    ]
                );
                
                $results['imported']++;
                
            } catch (\Exception $e) {
                if (strpos($e->getMessage(), 'Duplicate') !== false) {
                    $results['duplicates']++;
                } else {
                    $results['errors'][] = $e->getMessage();
                }
            }
        }
        
        return $results;
    }
}
