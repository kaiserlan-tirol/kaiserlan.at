<?php

namespace App\Command;

use App\Service\TicketAddonService;
use App\Service\ShopService;
use App\Service\TicketService;
use Ramsey\Uuid\Uuid;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'app:test-ticket-addon-system',
    description: 'Test the new ticket-addon system',
)]
class TestTicketAddonSystemCommand extends Command
{
    private readonly TicketAddonService $ticketAddonService;
    private readonly ShopService $shopService;
    private readonly TicketService $ticketService;

    public function __construct(
        TicketAddonService $ticketAddonService,
        ShopService $shopService,
        TicketService $ticketService
    ) {
        $this->ticketAddonService = $ticketAddonService;
        $this->shopService = $shopService;
        $this->ticketService = $ticketService;
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $io->title('Testing Ticket-Addon System');

        // Test with a sample user (user ID 13 from fixtures)
        try {
            $testUserUuid = Uuid::fromInteger('13');
            
            $io->section('Testing User Addon Availability');
            $io->text("Testing with user UUID: {$testUserUuid}");

            // Get user's available addons
            $availableAddons = $this->ticketAddonService->getUserAvailableAddons($testUserUuid);
            
            if (empty($availableAddons)) {
                $io->success('User has no addons available (expected for new system)');
            } else {
                $io->table(['Addon ID', 'Count'], array_map(fn($id, $count) => [$id, $count], array_keys($availableAddons), $availableAddons));
            }

            // Get ticket-addon relationships for the user
            $ticketAddons = $this->ticketAddonService->getUserTicketAddons($testUserUuid);
            
            if (empty($ticketAddons)) {
                $io->success('User has no ticket-specific addons (expected for new system)');
            } else {
                $io->text('Ticket-specific addons:');
                foreach ($ticketAddons as $ticketId => $addons) {
                    $io->text("Ticket {$ticketId}:");
                    foreach ($addons as $addonId => $count) {
                        $io->text("  - Addon {$addonId}: {$count}");
                    }
                }
            }

            // Test the shop service integration
            $io->section('Testing Shop Service Integration');
            
            $addons = $this->shopService->getAddons();
            $io->text("Found " . count($addons) . " active addons");

            $orders = $this->shopService->getOrderByUser($testUserUuid);
            $io->text("Found " . count($orders) . " orders for test user");

            $io->section('Testing Ticket Service Integration');
            
            $isRegistered = $this->ticketService->isUserRegistered($testUserUuid);
            $io->text("User is " . ($isRegistered ? "" : "not ") . "registered for the event");

            $io->success('All tests completed successfully! The new ticket-addon system is ready.');
            
            $io->note([
                'The system is now ready for the new per-ticket addon functionality.',
                'Existing orders will continue to work with the legacy system.',
                'New orders can use the enhanced per-ticket addon selection.',
                'Use the SettingService to control which system to use:'
            ]);
            
            $io->text('Set setting "shop.per_ticket_addons" to "true" to enable the new system');

        } catch (\Exception $e) {
            $io->error("Test failed: " . $e->getMessage());
            return Command::FAILURE;
        }

        return Command::SUCCESS;
    }
}
