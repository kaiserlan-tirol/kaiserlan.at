<?php

namespace App\Service;

use App\Entity\IncomingPayment;
use App\Entity\User;
use App\Idm\IdmManager;
use App\Idm\IdmRepository;
use App\Repository\TicketRepository;
use Psr\Log\LoggerInterface;

class PaymentMatchingService
{
    private readonly IdmRepository $userRepo;
    private readonly LoggerInterface $logger;
    private readonly TicketRepository $ticketRepository;

    public function __construct(
        IdmManager $idmManager,
        TicketRepository $ticketRepository,
        LoggerInterface $logger
    ) {
        $this->userRepo = $idmManager->getRepository(User::class);
        $this->ticketRepository = $ticketRepository;
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
        
        $bestMatch = $this->preferTicketHolder($matches);
        
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

    /**
     * Several accounts can reach the same score - typically an old and a current
     * account of the same person. Prefer the one that actually holds a ticket.
     */
    private function preferTicketHolder(array $sortedMatches): array
    {
        $topScore = $sortedMatches[0]['score'];
        $tied = array_filter($sortedMatches, fn($m) => abs($m['score'] - $topScore) < 0.0001);

        if (count($tied) < 2) {
            return $sortedMatches[0];
        }

        foreach ($tied as $match) {
            if ($this->ticketRepository->findOneByRedeemer($match['user']->getUuid())) {
                return $match;
            }
        }

        return $sortedMatches[0];
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

        // Email matching (highest priority) - only for non-PayPal sources
        if ($payment->getPayerEmail() && $payment->getSource() !== IncomingPayment::SOURCE_PAYPAL) {
            // Exact email match
            if (strtolower($payment->getPayerEmail()) === strtolower($user->getEmail())) {
                $score += 0.8;
                $reasons[] = 'E-Mail exakte Übereinstimmung';
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

        // Payer name matching with fuzzy logic
        if ($payment->getPayerName()) {
            $payerName = strtolower($payment->getPayerName());
            $userFirstName = strtolower($user->getFirstname() ?? '');
            $userSurname = strtolower($user->getSurname() ?? '');
            $userNickname = strtolower($user->getNickname() ?? '');
            
            $firstNameMatch = $userFirstName && strpos($payerName, $userFirstName) !== false;
            $surnameMatch = $userSurname && strpos($payerName, $userSurname) !== false;
            
            // Full name match (first + surname) should be very high confidence
            if ($firstNameMatch && $surnameMatch) {
                $score += 0.8;  // High score for complete name match
                $reasons[] = 'Vollständiger Name im Zahlernamen (Vor- und Nachname)';
            } else {
                // Individual name matches (only if not both matched above)
                if ($firstNameMatch) {
                    $score += 0.3;
                    $reasons[] = 'Vorname im Zahlernamen';
                }
                
                if ($surnameMatch) {
                    $score += 0.3;
                    $reasons[] = 'Nachname im Zahlernamen';
                }
            }
            
            if ($userNickname && strpos($payerName, $userNickname) !== false) {
                $score += 0.4;
                $reasons[] = 'Nickname im Zahlernamen';
            }
            
            // Fuzzy matching for full name with typos (high confidence)
            $fullUserName = trim($userFirstName . ' ' . $userSurname);
            if (strlen($fullUserName) > 3) {
                $fullNameSimilarity = $this->calculateStringSimilarity($fullUserName, $payerName);
                if ($fullNameSimilarity >= 0.85) {
                    $score += 0.75;  // High score for fuzzy full name match
                    $reasons[] = sprintf('Vollständiger Name sehr ähnlich (%.0f%%)', $fullNameSimilarity * 100);
                } elseif ($fullNameSimilarity >= 0.7) {
                    $score += 0.5;   // Medium-high score for moderate similarity
                    $reasons[] = sprintf('Vollständiger Name ähnlich (%.0f%%)', $fullNameSimilarity * 100);
                }
            }
            
            // Individual name fuzzy matching (only if full name didn't match well)
            if ($score < 0.7) {
                if ($userFirstName) {
                    $similarity = $this->calculateStringSimilarity($userFirstName, $payerName);
                    if ($similarity >= 0.8) {
                        $score += 0.25;
                        $reasons[] = sprintf('Vorname ähnlich (%.0f%%)', $similarity * 100);
                    }
                }
                
                if ($userSurname) {
                    $similarity = $this->calculateStringSimilarity($userSurname, $payerName);
                    if ($similarity >= 0.8) {
                        $score += 0.25;
                        $reasons[] = sprintf('Nachname ähnlich (%.0f%%)', $similarity * 100);
                    }
                }
            }
            
            if ($userNickname) {
                $similarity = $this->calculateStringSimilarity($userNickname, $payerName);
                if ($similarity >= 0.8) {
                    $score += 0.35;
                    $reasons[] = sprintf('Nickname ähnlich (%.0f%%)', $similarity * 100);
                }
            }
            
            // Try to match individual words in payer name
            $payerWords = preg_split('/\s+/', $payerName);
            foreach ($payerWords as $word) {
                if (strlen($word) < 3) continue; // Skip short words
                
                if ($userFirstName && $this->calculateStringSimilarity($word, $userFirstName) >= 0.85) {
                    $score += 0.2;
                    $reasons[] = 'Wort im Namen ähnlich Vorname';
                    break;
                }
                
                if ($userSurname && $this->calculateStringSimilarity($word, $userSurname) >= 0.85) {
                    $score += 0.2;
                    $reasons[] = 'Wort im Namen ähnlich Nachname';
                    break;
                }
                
                if ($userNickname && $this->calculateStringSimilarity($word, $userNickname) >= 0.85) {
                    $score += 0.3;
                    $reasons[] = 'Wort im Namen ähnlich Nickname';
                    break;
                }
            }
        }

        return $score > 0 ? ['total' => $score, 'reasons' => $reasons] : ['total' => 0, 'reasons' => []];
    }

    public function learnFromMatch(IncomingPayment $payment, User $user): void
    {
        // Store payment details in user profile for future matching
        // PayPal email learning removed since payer email is not reliable from PayPal notifications
        if ($payment->getPayerEmail() && $payment->getSource() === IncomingPayment::SOURCE_PAYPAL) {
            if (!$user->hasPaypalEmail($payment->getPayerEmail())) {
                $user->addPaypalEmail($payment->getPayerEmail());
                $this->logger->info('Added PayPal email to user profile', [
                    'user_id' => $user->getUuid()->toString(),
                    'paypal_email' => $payment->getPayerEmail()
                ]);
            }
        }

        // Mark payment details as verified if this is a manual match
        if ($payment->getMatchConfidence() === IncomingPayment::CONFIDENCE_MANUAL) {
            if (method_exists($user, 'setPaymentDetailsVerifiedAt')) {
                $user->setPaymentDetailsVerifiedAt(new \DateTime());
            }
        }
        
        $this->logger->info('Payment matched to user', [
            'payment_id' => $payment->getId(),
            'user_id' => $user->getUuid()->toString(),
            'payer_name' => $payment->getPayerName(),
            'source' => $payment->getSource()
        ]);
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

    /**
     * Calculate string similarity using Levenshtein distance
     * Returns a value between 0 and 1, where 1 is identical
     */
    private function calculateStringSimilarity(string $str1, string $str2): float
    {
        if (empty($str1) || empty($str2)) {
            return 0.0;
        }
        
        // Normalize strings
        $str1 = strtolower(trim($str1));
        $str2 = strtolower(trim($str2));
        
        if ($str1 === $str2) {
            return 1.0;
        }
        
        $maxLen = max(strlen($str1), strlen($str2));
        if ($maxLen === 0) {
            return 1.0;
        }
        
        $distance = levenshtein($str1, $str2);
        
        // Calculate similarity as percentage
        $similarity = 1 - ($distance / $maxLen);
        
        return max(0, $similarity);
    }
}
