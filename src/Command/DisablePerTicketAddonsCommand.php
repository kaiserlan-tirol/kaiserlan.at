<?php

namespace App\Command;

use App\Service\SettingService;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'app:disable-per-ticket-addons',
    description: 'Disable the per-ticket addon system and revert to legacy global addon system'
)]
class DisablePerTicketAddonsCommand extends Command
{
    public function __construct(
        private readonly SettingService $settingService
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $result = $this->settingService->set('shop.per_ticket_addons', false);
        
        if ($result) {
            $io->success('Per-ticket addon system has been disabled!');
            $io->text('The shop will now use the legacy global addon selection system.');
            $io->note('Users will be able to select addons globally for their entire order, not per individual ticket.');
        } else {
            $io->error('Failed to disable the per-ticket addon system.');
            return Command::FAILURE;
        }

        return Command::SUCCESS;
    }
}
