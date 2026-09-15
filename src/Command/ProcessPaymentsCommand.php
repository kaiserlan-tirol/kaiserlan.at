<?php

namespace App\Command;

use App\Entity\IncomingPayment;
use App\Service\IncomingPaymentService;
use App\Service\PaymentMatchingService;
use App\Service\PaymentNotificationService;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'app:process-payments',
    description: 'Automatically match unmatched payments and process matched payments (tickets first, then catering)'
)]
class ProcessPaymentsCommand extends Command
{
    public function __construct(
        private readonly IncomingPaymentService $incomingPaymentService,
        private readonly PaymentMatchingService $paymentMatchingService,
        private readonly PaymentNotificationService $paymentNotificationService,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Show what would be processed without actually processing')
            ->addOption('limit', 'l', InputOption::VALUE_OPTIONAL, 'Maximum number of payments to process', 50)
            ->addOption('match-only', null, InputOption::VALUE_NONE, 'Only attempt to match payments, do not process them')
            ->addOption('process-only', null, InputOption::VALUE_NONE, 'Only process already matched payments, do not attempt new matches')
            ->addOption('debug', 'd', InputOption::VALUE_NONE, 'Show debug information')
            ->setHelp('This command automatically matches unmatched payments to users and processes matched payments.

Processing order:
1. Match unmatched payments to users based on payment data
2. Process matched payments by applying them to orders:
   - First: Shop orders (tickets, addons) 
   - Then: Catering orders

Examples:
  # Process all unmatched/matched payments (match + process)
  php bin/console app:process-payments

  # Only match payments to users without processing
  php bin/console app:process-payments --match-only

  # Only process already matched payments
  php bin/console app:process-payments --process-only

  # Dry run to see what would happen
  php bin/console app:process-payments --dry-run --debug');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $dryRun = $input->getOption('dry-run');
        $debug = $input->getOption('debug');
        $limit = (int) $input->getOption('limit');
        $matchOnly = $input->getOption('match-only');
        $processOnly = $input->getOption('process-only');

        $io->title('Automatic Payment Processing');
        
        if ($dryRun) {
            $io->warning('DRY RUN MODE - No payments will be actually matched or processed');
        }

        if ($debug) {
            $io->info('DEBUG MODE ENABLED - Additional debug information will be shown');
        }

        $matchedCount = 0;
        $processedCount = 0;
        $skippedNoTicket = 0;
        $errors = 0;

        try {
            // Step 1: Match unmatched payments (unless process-only mode)
            if (!$processOnly) {
                $io->section('Step 1: Matching Unmatched Payments');
                
                $unmatchedPayments = $this->incomingPaymentService->getUnmatchedPayments(1, $limit);
                
                if (empty($unmatchedPayments)) {
                    $io->info('No unmatched payments found.');
                } else {
                    $io->info(sprintf('Found %d unmatched payments to process', count($unmatchedPayments)));
                    
                    $progressBar = $io->createProgressBar(count($unmatchedPayments));
                    $progressBar->start();

                    foreach ($unmatchedPayments as $payment) {
                        try {
                            if ($dryRun) {
                                // Show what would be matched
                                $suggestions = $this->paymentMatchingService->suggestMatches($payment, 3);
                                if (!empty($suggestions)) {
                                    $bestMatch = $suggestions[0];
                                    $io->text(sprintf(
                                        'Would match payment %.2f EUR from %s to user %s (confidence: %.1f%%)',
                                        $payment->getAmount(),
                                        $payment->getPayerName() ?? 'unknown',
                                        $bestMatch['user']->getEmail(),
                                        $bestMatch['score'] * 100
                                    ));
                                } else {
                                    $io->text(sprintf(
                                        'No good match found for payment %.2f EUR from %s',
                                        $payment->getAmount(),
                                        $payment->getPayerName() ?? 'unknown'
                                    ));
                                }
                            } else {
                                // Actually try to match
                                $matched = $this->paymentMatchingService->autoMatchPayment($payment);
                                if ($matched) {
                                    $matchedCount++;
                                    if ($debug) {
                                        $io->text(sprintf(
                                            'Matched payment %.2f EUR from %s (confidence: %s)',
                                            $payment->getAmount(),
                                            $payment->getPayerName() ?? 'unknown',
                                            $payment->getMatchConfidence()
                                        ));
                                    }
                                }
                            }
                        } catch (\Exception $e) {
                            $errors++;
                            $io->error(sprintf('Error matching payment ID %d: %s', $payment->getId(), $e->getMessage()));
                        }
                        
                        $progressBar->advance();
                    }
                    
                    $progressBar->finish();
                    $io->newLine(2);
                }
            }

            // Step 2: Process matched but unprocessed payments (unless match-only mode)
            if (!$matchOnly) {
                $io->section('Step 2: Processing Matched Payments');
                
                // Get matched but unprocessed payments
                $matchedPayments = $this->getMatchedUnprocessedPayments($limit);
                
                if (empty($matchedPayments)) {
                    $io->info('No matched unprocessed payments found.');
                } else {
                    $io->info(sprintf('Found %d matched payments to process (tickets first, then catering)', count($matchedPayments)));
                    
                    $progressBar = $io->createProgressBar(count($matchedPayments));
                    $progressBar->start();

                    foreach ($matchedPayments as $payment) {
                        try {
                            if ($dryRun) {
                                // Show what would be processed
                                $io->text(sprintf(
                                    'Would process payment %.2f EUR from %s for user %s',
                                    $payment->getAmount(),
                                    $payment->getPayerName() ?? 'unknown',
                                    $payment->getMatchedUser() ? 'matched user' : 'unknown'
                                ));
                            } else {
                                // Actually process the payment
                                $result = $this->incomingPaymentService->processPayment($payment);

                                if (!empty($result['skipped_without_ticket'])) {
                                    $skippedNoTicket++;
                                    $io->text(sprintf(
                                        'Skipped payment %.2f EUR from %s: matched user has no ticket',
                                        $payment->getAmount(),
                                        $payment->getPayerName() ?? 'unknown'
                                    ));
                                    $progressBar->advance();
                                    continue;
                                }

                                $processedCount++;
                                
                                if ($debug) {
                                    $io->text(sprintf(
                                        'Processed payment %.2f EUR: Shop orders: %.2f EUR, Catering orders: %.2f EUR, Credit: %.2f EUR',
                                        $payment->getAmount(),
                                        ($result['shop_orders']['amount_used'] ?? 0) / 100,
                                        ($result['catering_orders']['amount_used'] ?? 0) / 100,
                                        ($result['credit_added'] ?? 0) / 100
                                    ));
                                    
                                    if (!empty($result['processing_notes'])) {
                                        foreach ($result['processing_notes'] as $note) {
                                            $io->text('  - ' . $note);
                                        }
                                    }
                                }
                            }
                        } catch (\Exception $e) {
                            $errors++;
                            $io->error(sprintf('Error processing payment ID %d: %s', $payment->getId(), $e->getMessage()));
                        }
                        
                        $progressBar->advance();
                    }
                    
                    $progressBar->finish();
                    $io->newLine(2);
                }
            }

        } catch (\Exception $e) {
            $io->error('Error during payment processing: ' . $e->getMessage());
            return Command::FAILURE;
        }

        // Summary
        $io->success('Payment processing completed!');
        $io->table(['Metric', 'Count'], [
            ['Payments Matched', $matchedCount],
            ['Payments Processed', $processedCount],
            ['Skipped (no ticket)', $skippedNoTicket],
            ['Errors', $errors],
        ]);

        if ($processedCount > 0 && !$dryRun) {
            $io->note('Payments have been processed. Check the payment dashboard for details.');
        }

        $this->reportOpenCases($io, $dryRun);

        return Command::SUCCESS;
    }

    /**
     * Mail one digest over everything that stayed behind. This runs last on
     * purpose: it is the final step of auto-process-payments.sh, so the digest
     * covers the whole pipeline, including what the import already booked.
     */
    private function reportOpenCases(SymfonyStyle $io, bool $dryRun): void
    {
        $openCases = $this->paymentNotificationService->getOpenCases();

        if (empty($openCases)) {
            return;
        }

        $io->warning(sprintf('%d payment(s) need attention', count($openCases)));

        $recipient = $this->paymentNotificationService->getRecipient();
        if ($recipient === null) {
            $io->note(sprintf(
                'No notification address configured (setting "%s") - no mail sent.',
                PaymentNotificationService::SETTING_RECIPIENT
            ));

            return;
        }

        if ($dryRun) {
            $io->note(sprintf('Would send a notification to %s:', $recipient));
            $io->text($this->paymentNotificationService->buildBody($openCases));

            return;
        }

        if ($this->paymentNotificationService->notifyOpenCases() > 0) {
            $io->success(sprintf('Notification sent to %s', $recipient));
        } else {
            $io->error('Could not send the notification, see the log for details.');
        }
    }

    /**
     * Get payments that are matched to users but not yet processed
     */
    private function getMatchedUnprocessedPayments(int $limit): array
    {
        return $this->incomingPaymentService->getMatchedUnprocessedPayments(1, $limit);
    }
}
