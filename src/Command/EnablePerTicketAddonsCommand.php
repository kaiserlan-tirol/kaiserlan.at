<?php

namespace App\Command;

use App\Service\SettingService;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'app:enable-per-ticket-addons',
    description: 'Enable the per-ticket addon system'
)]
class EnablePerTicketAddonsCommand extends Command
{
    public function __construct(
        private readonly SettingService $settingService
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $result = $this->settingService->set('shop.per_ticket_addons', true);
        
        if ($result) {
            $io->success('Per-ticket addon system has been enabled!');
            $io->text('The shop will now use the new per-ticket addon selection system.');
        } else {
            $io->error('Failed to enable the per-ticket addon system.');
            return Command::FAILURE;
        }

        return Command::SUCCESS;
    }
}
