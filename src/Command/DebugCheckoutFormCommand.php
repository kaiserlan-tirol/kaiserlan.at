<?php

namespace App\Command;

use App\Form\CheckoutType;
use App\Service\SettingService;
use App\Service\ShopService;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Form\FormFactoryInterface;

#[AsCommand(
    name: 'debug:checkout-form',
    description: 'Debug checkout form to see which fields are created'
)]
class DebugCheckoutFormCommand extends Command
{
    public function __construct(
        private FormFactoryInterface $formFactory,
        private SettingService $settingService,
        private ShopService $shopService
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $output->writeln('Debugging checkout form creation...');
        
        // Get the current setting
        $perTicketAddons = (bool) $this->settingService->get('shop.per_ticket_addons', false);
        $output->writeln("Per-ticket addons setting: " . ($perTicketAddons ? 'true' : 'false'));
        
        $addons = $this->shopService->getAddons();
        $output->writeln("Available addons: " . count($addons));
        
        try {
            // Create form exactly like the controller does
            $form = $this->formFactory->create(CheckoutType::class, options: [
                'tickets' => true,
                'code' => false, // Assuming user is registered for simplicity
                'addons' => $addons,
                'per_ticket_addons' => $perTicketAddons,
                'max_ticket_count' => 5,
                'max_addon_count_callback' => fn() => null,
            ]);
            
            $output->writeln('✅ Form created successfully!');
            
            // Check all form fields
            $children = $form->all();
            $fieldNames = array_keys($children);
            
            $output->writeln('All form fields:');
            foreach ($fieldNames as $fieldName) {
                $output->writeln("  - {$fieldName}");
            }
            
            // Check specifically for ticket addon fields
            $ticketAddonFields = array_filter($fieldNames, fn($name) => str_starts_with($name, 'ticket_addons_'));
            
            if (empty($ticketAddonFields)) {
                $output->writeln('❌ No ticket_addons_* fields found!');
                
                // Check for legacy addon fields
                $legacyAddonFields = array_filter($fieldNames, fn($name) => str_starts_with($name, 'addon'));
                if (!empty($legacyAddonFields)) {
                    $output->writeln('Found legacy addon fields:');
                    foreach ($legacyAddonFields as $field) {
                        $output->writeln("  - {$field}");
                    }
                }
            } else {
                $output->writeln('✅ Found ticket addon fields:');
                foreach ($ticketAddonFields as $field) {
                    $output->writeln("  - {$field}");
                }
            }
            
            return Command::SUCCESS;
            
        } catch (\Exception $e) {
            $output->writeln("<error>Error creating form: {$e->getMessage()}</error>");
            return Command::FAILURE;
        }
    }
}
