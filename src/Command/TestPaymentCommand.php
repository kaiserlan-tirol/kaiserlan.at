<?php

namespace App\Command;

use App\Service\IncomingPaymentService;
use DateTimeImmutable;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'app:test-payment',
    description: 'Create a test incoming payment for development and testing',
)]
class TestPaymentCommand extends Command
{
    private readonly IncomingPaymentService $incomingPaymentService;

    public function __construct(IncomingPaymentService $incomingPaymentService)
    {
        $this->incomingPaymentService = $incomingPaymentService;
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('amount', InputArgument::OPTIONAL, 'Payment amount in EUR', '25.00')
            ->addOption('source', null, InputOption::VALUE_OPTIONAL, 'Payment source', 'paypal')
            ->addOption('reference', null, InputOption::VALUE_OPTIONAL, 'Payment reference')
            ->addOption('payer-email', null, InputOption::VALUE_OPTIONAL, 'Payer email address')
            ->addOption('payer-name', null, InputOption::VALUE_OPTIONAL, 'Payer name')
            ->addOption('payer-account', null, InputOption::VALUE_OPTIONAL, 'Payer account/IBAN')
            ->addOption('description', null, InputOption::VALUE_OPTIONAL, 'Payment description')
        ;
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $amount = (float) $input->getArgument('amount');
        $source = $input->getOption('source');
        $reference = $input->getOption('reference');
        $payerEmail = $input->getOption('payer-email');
        $payerName = $input->getOption('payer-name');
        $payerAccount = $input->getOption('payer-account');
        $description = $input->getOption('description');

        $metadata = [
            'externalId' => uniqid('test_payment_'),
        ];

        if ($reference) {
            $metadata['reference'] = $reference;
        }

        if ($payerEmail) {
            $metadata['payerEmail'] = $payerEmail;
        }

        if ($payerName) {
            $metadata['payerName'] = $payerName;
        }

        if ($payerAccount) {
            $metadata['payerAccount'] = $payerAccount;
        }

        if ($description) {
            $metadata['description'] = $description;
        }

        try {
            $payment = $this->incomingPaymentService->createIncomingPayment(
                $amount,
                'EUR',
                new DateTimeImmutable(),
                $source,
                $metadata
            );

            $io->success(sprintf(
                'Test payment created successfully! Payment ID: %d',
                $payment->getId()
            ));

            $io->table(['Property', 'Value'], [
                ['ID', $payment->getId()],
                ['Amount', $payment->getAmount() . ' EUR'],
                ['Source', $payment->getSource()],
                ['Status', $payment->getStatus()],
                ['Reference', $payment->getReference() ?: 'None'],
                ['Payer Email', $payment->getPayerEmail() ?: 'None'],
                ['Payer Name', $payment->getPayerName() ?: 'None'],
                ['Matched User', $payment->getMatchedUser() ? $payment->getMatchedUser()->toString() : 'None'],
                ['Match Confidence', $payment->getMatchConfidence() ?: 'None'],
                ['Processing Notes', $payment->getProcessingNotes() ?: 'None'],
            ]);

            return Command::SUCCESS;

        } catch (\Exception $e) {
            $io->error('Failed to create test payment: ' . $e->getMessage());
            return Command::FAILURE;
        }
    }
}
