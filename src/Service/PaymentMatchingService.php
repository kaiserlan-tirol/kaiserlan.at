<?php

namespace App\Service;

use App\Entity\IncomingPayment;
use App\Entity\User;
use App\Idm\IdmManager;
use App\Idm\IdmRepository;
use Psr\Log\LoggerInterface;

class PaymentMatchingService
{
    private readonly IdmRepository $userRepo;
    private readonly LoggerInterface $logger;

    public function __construct(
        IdmManager $idmManager,
        LoggerInterface $logger
    ) {
        $this->userRepo = $idmManager->getRepository(User::class);
        $this->logger = $logger;
    }

    public function autoMatchPayment(IncomingPayment $payment): bool
    {
        $matches = $this->findPotentialMatches($payment);
        
        if (empty($matches)) {
            $this->logger->debug('No potential matches found for payment', [
                'payment_id' => $payment->getId(),
                'payer_email' => $payment->getPayerEmail(),
                'reference' => $payment->getReference()
            ]);
            return false;
        }

        // Sort by confidence score (highest first)
        usort($matches, fn($a, $b) => $b['score'] <=> $a['score']);
        
        $bestMatch = $matches[0];
        
        // Only auto-match if confidence is high enough
        if ($bestMatch['score'] >= 0.9) {
            $payment->setMatchedUser($bestMatch['user']->getUuid());
            $payment->setMatchConfidence(IncomingPayment::CONFIDENCE_HIGH);
            $payment->setStatus(IncomingPayment::STATUS_MATCHED);
            
            $this->logger->info('Payment automatically matched', [
                'payment_id' => $payment->getId(),
                'user_id' => $bestMatch['user']->getUuid()->toString(),
                'confidence_score' => $bestMatch['score'],
                'match_reasons' => $bestMatch['reasons']
            ]);
            
            return true;
        } elseif ($bestMatch['score'] >= 0.5) {
            $payment->setMatchedUser($bestMatch['user']->getUuid());
            $payment->setMatchConfidence(IncomingPayment::CONFIDENCE_MEDIUM);
            // Keep status as PENDING for admin review
            
            $this->logger->info('Payment matched with medium confidence', [
                'payment_id' => $payment->getId(),
                'user_id' => $bestMatch['user']->getUuid()->toString(),
                'confidence_score' => $bestMatch['score'],
                'match_reasons' => $bestMatch['reasons']
            ]);
            
            return true;
        }

        return false;
    }

    public function findPotentialMatches(IncomingPayment $payment): array
    {
        $matches = [];
        $allUsers = $this->userRepo->findAll();

        foreach ($allUsers as $user) {
            $score = $this->calculateMatchScore($payment, $user);
            if ($score['total'] > 0) {
                $matches[] = [
                    'user' => $user,
                    'score' => $score['total'],
                    'reasons' => $score['reasons']
                ];
            }
        }

        return $matches;
    }

    private function calculateMatchScore(IncomingPayment $payment, User $user): array
    {
        $score = 0;
        $reasons = [];

        // Email matching (highest priority)
        if ($payment->getPayerEmail()) {
            // Exact email match
            if (strtolower($payment->getPayerEmail()) === strtolower($user->getEmail())) {
                $score += 0.8;
                $reasons[] = 'E-Mail exakte Übereinstimmung';
            }
            
            // PayPal email match
            foreach ($user->getPaypalEmails() as $paypalEmail) {
                if (strtolower($payment->getPayerEmail()) === strtolower($paypalEmail)) {
                    $score += 0.7;
                    $reasons[] = 'PayPal E-Mail Übereinstimmung';
                    break;
                }
            }
        }

        // Reference/nickname matching
        if ($payment->getReference()) {
            $reference = strtolower($payment->getReference());
            $nickname = strtolower($user->getNickname() ?? '');
            
            // Exact nickname match in reference
            if ($nickname && strpos($reference, $nickname) !== false) {
                $score += 0.5;
                $reasons[] = 'Nickname in Verwendungszweck';
            }
            
            // Name matching in reference
            $firstName = strtolower($user->getFirstname() ?? '');
            $surname = strtolower($user->getSurname() ?? '');
            
            if ($firstName && strpos($reference, $firstName) !== false) {
                $score += 0.2;
                $reasons[] = 'Vorname in Verwendungszweck';
            }
            
            if ($surname && strpos($reference, $surname) !== false) {
                $score += 0.2;
                $reasons[] = 'Nachname in Verwendungszweck';
            }

            // "Catering" keyword
            if (strpos($reference, 'catering') !== false) {
                $score += 0.1;
                $reasons[] = 'Catering Schlüsselwort';
            }
            
            // Order ID patterns
            if (preg_match('/(?:order|bestellung|#)\s*(\d+)/i', $reference)) {
                $score += 0.2;
                $reasons[] = 'Bestell-ID Muster in Verwendungszweck';
            }
            
            // Catering order patterns
            if (preg_match('/(?:catering|food|essen)\s*(\d+)/i', $reference)) {
                $score += 0.15;
                $reasons[] = 'Catering Bestell-Muster in Verwendungszweck';
            }
        }

        // Payer name matching
        if ($payment->getPayerName()) {
            $payerName = strtolower($payment->getPayerName());
            $userFirstName = strtolower($user->getFirstname() ?? '');
            $userSurname = strtolower($user->getSurname() ?? '');
            
            if ($userFirstName && strpos($payerName, $userFirstName) !== false) {
                $score += 0.3;
                $reasons[] = 'Vorname im Zahlernamen';
            }
            
            if ($userSurname && strpos($payerName, $userSurname) !== false) {
                $score += 0.3;
                $reasons[] = 'Nachname im Zahlernamen';
            }
        }

        // IBAN matching (for bank transfers)
        if ($payment->getSource() === IncomingPayment::SOURCE_BANK && $payment->getPayerAccount()) {
            foreach ($user->getIbanNumbers() as $iban) {
                if ($payment->getPayerAccount() === $iban) {
                    $score += 0.6;
                    $reasons[] = 'IBAN Übereinstimmung';
                    break;
                }
            }
        }

        return $score > 0 ? ['total' => min($score, 1.0), 'reasons' => $reasons] : ['total' => 0, 'reasons' => []];
    }

    public function learnFromMatch(IncomingPayment $payment, User $user): void
    {
        // Store payment details in user profile for future matching
        
        if ($payment->getPayerEmail() && $payment->getSource() === IncomingPayment::SOURCE_PAYPAL) {
            if (!$user->hasPaypalEmail($payment->getPayerEmail())) {
                $user->addPaypalEmail($payment->getPayerEmail());
                $this->logger->info('Added PayPal email to user profile', [
                    'user_id' => $user->getUuid()->toString(),
                    'paypal_email' => $payment->getPayerEmail()
                ]);
            }
        }

        if ($payment->getPayerAccount() && $payment->getSource() === IncomingPayment::SOURCE_BANK) {
            if (!$user->hasIbanNumber($payment->getPayerAccount())) {
                $user->addIbanNumber($payment->getPayerAccount());
                $this->logger->info('Added IBAN to user profile', [
                    'user_id' => $user->getUuid()->toString(),
                    'iban' => substr($payment->getPayerAccount(), 0, 8) . '****' // Log only first 8 chars for privacy
                ]);
            }
        }

        // Mark payment details as verified if this is a manual match
        if ($payment->getMatchConfidence() === IncomingPayment::CONFIDENCE_MANUAL) {
            if (method_exists($user, 'setPaymentDetailsVerifiedAt')) {
                $user->setPaymentDetailsVerifiedAt(new \DateTime());
            }
        }
    }

    public function suggestMatches(IncomingPayment $payment, int $limit = 5): array
    {
        $matches = $this->findPotentialMatches($payment);
        
        // Sort by confidence score (highest first)
        usort($matches, fn($a, $b) => $b['score'] <=> $a['score']);
        
        return array_slice($matches, 0, $limit);
    }

    /**
     * Get all users as array
     * @return User[]
     */
    public function getAllUsers(): array
    {
        $allUsers = $this->userRepo->findAll();
        $users = [];
        
        foreach ($allUsers as $user) {
            $users[] = $user;
        }
        
        return $users;
    }
}
