<?php

namespace App\Command;

use App\Service\SettingService;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(
    name: 'debug:setting',
    description: 'Check the value of a specific setting'
)]
class CheckSettingCommand extends Command
{
    public function __construct(
        private SettingService $settingService
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $output->writeln('Checking shop.per_ticket_addons setting...');
        
        $setting = $this->settingService->get('shop.per_ticket_addons', 'DEFAULT_NOT_SET');
        $output->writeln('Raw value: ' . var_export($setting, true));
        $output->writeln('Boolean cast: ' . (bool) $setting ? 'true' : 'false');
        
        // Also check related settings
        $maxTickets = $this->settingService->get('shop.max_tickets', 'DEFAULT_NOT_SET');
        $output->writeln('shop.max_tickets: ' . var_export($maxTickets, true));
        
        return Command::SUCCESS;
    }
}
