<?php

namespace App\Command;

use App\Entity\Ticket;
use App\Exception\SteamApiException;
use App\Idm\Exception\PersistException;
use App\Idm\IdmManager;
use App\Repository\TicketRepository;
use App\Service\SteamAccountService;
use App\Service\TicketState;
use App\Service\UserService;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'app:resolve-steam-accounts',
    description: 'Resolves the steam accounts of all ticket holders to SteamID64 (dry run unless --write is given)'
)]
class ResolveSteamAccountsCommand extends Command
{
    public function __construct(
        private readonly TicketRepository $ticketRepository,
        private readonly UserService $userService,
        private readonly SteamAccountService $steamAccountService,
        private readonly IdmManager $idmManager
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('write', null, InputOption::VALUE_NONE, 'Write the resolved SteamID64 back into the user profiles')
            ->addOption('include-unsure', null, InputOption::VALUE_NONE, 'Also write rows resolved from a plain name, which may hit a stranger profile');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $write = $input->getOption('write');
        $includeUnsure = $input->getOption('include-unsure');

        if (!$this->steamAccountService->isConfigured()) {
            $io->error('STEAM_API_KEY ist nicht gesetzt (.env.local).');

            return Command::FAILURE;
        }

        $uuids = array_map(
            fn (Ticket $ticket) => $ticket->getRedeemer(),
            array_filter(
                $this->ticketRepository->findByState(TicketState::REDEEMED),
                fn (Ticket $ticket) => $ticket->getRedeemer() !== null
            )
        );

        $rows = [];
        try {
            foreach ($this->userService->getUsers(array_values($uuids)) as $user) {
                $raw = trim((string) $user->getSteamAccount());
                if ($raw === '') {
                    continue;
                }

                $resolved = [];
                foreach (SteamAccountService::candidates($raw) as ['account' => $candidate, 'fromUrl' => $fromUrl]) {
                    $steamId = $this->steamAccountService->resolveSteamId64($candidate);
                    if ($steamId !== null) {
                        $resolved[$steamId] = $fromUrl || SteamAccountService::isSteamId64($candidate);
                    }
                }

                $rows[] = ['user' => $user, 'raw' => $raw, 'resolved' => $resolved];
            }

            $allSteamIds = [];
            foreach ($rows as $row) {
                $allSteamIds = array_merge($allSteamIds, array_keys($row['resolved']));
            }
            $summaries = $this->steamAccountService->fetchPlayerSummaries($allSteamIds);
        } catch (SteamApiException $e) {
            $io->error($e->getMessage());
            $io->note('Nichts geschrieben. Solange die Steam API nicht erreichbar ist, waere jeder Account faelschlich "offen".');

            return Command::FAILURE;
        }

        $table = [];
        $writable = [];
        $unsure = 0;
        foreach ($rows as $row) {
            $steamIds = array_map('strval', array_keys($row['resolved']));
            $status = match (true) {
                count($steamIds) === 0 => 'offen',
                count($steamIds) > 1 => 'mehrdeutig',
                $steamIds[0] === $row['raw'] => 'unveraendert',
                $row['resolved'][$steamIds[0]] => 'sicher',
                default => 'pruefen',
            };

            if ($status === 'sicher' || ($status === 'pruefen' && $includeUnsure)) {
                $writable[] = [$row['user'], $steamIds[0]];
            }
            if ($status === 'pruefen') {
                ++$unsure;
            }

            $table[] = [
                $row['user']->getNickname(),
                $row['raw'],
                implode(', ', $steamIds),
                implode(', ', array_map(fn (string $id) => $summaries[$id]['personaname'] ?? '? (Profil nicht gefunden)', $steamIds)),
                implode(', ', array_map(fn (string $id) => $summaries[$id]['profileurl'] ?? '', $steamIds)),
                $status,
            ];
        }

        $io->table(['Nickname', 'Eingabe', 'SteamID64', 'Steam-Name', 'Profil', 'Status'], $table);

        if ($unsure > 0) {
            $io->warning(sprintf(
                '%d Eintrag/Eintraege wurden aus einem Klarnamen aufgeloest (Status "pruefen") und koennen ein fremdes Profil treffen. Steam-Name pruefen! Diese werden nur mit --include-unsure geschrieben.',
                $unsure
            ));
        }

        if (!$write) {
            $io->note(sprintf('Dry run: %d Profil(e) waeren aenderbar. Mit --write schreiben.', count($writable)));

            return Command::SUCCESS;
        }

        foreach ($writable as [$user, $steamId]) {
            $user->setSteamAccount($steamId);
            $this->idmManager->persist($user);
        }

        try {
            $this->idmManager->flush();
        } catch (PersistException $e) {
            $io->error('Speichern fehlgeschlagen: '.$e->getMessage());

            return Command::FAILURE;
        }

        $io->success(sprintf('%d Profil(e) aktualisiert.', count($writable)));

        return Command::SUCCESS;
    }
}
