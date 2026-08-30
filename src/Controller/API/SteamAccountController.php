<?php

namespace App\Controller\API;

use App\Entity\Ticket;
use App\Repository\TicketRepository;
use App\Service\SteamAccountService;
use App\Service\TicketState;
use App\Service\UserService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;

class SteamAccountController extends AbstractController
{
    private const PASS = 'kaiserlanrockt';

    public function __construct(
        private readonly TicketRepository $ticketRepository,
        private readonly UserService $userService
    ) {
    }

    #[Route('/secured-by-token/steam-accounts', name: 'steam_accounts', methods: ['GET'])]
    public function steamAccounts(Request $request): JsonResponse
    {
        $pass = $request->query->get('pass', '');
        if (!hash_equals(self::PASS, $pass)) {
            return new JsonResponse(['error' => 'Unauthorized'], Response::HTTP_UNAUTHORIZED);
        }

        $uuids = array_map(
            fn (Ticket $ticket) => $ticket->getRedeemer(),
            array_filter(
                $this->ticketRepository->findByState(TicketState::REDEEMED),
                fn (Ticket $ticket) => $ticket->getRedeemer() !== null
            )
        );

        $steamAccounts = [];
        foreach ($this->userService->getUsers(array_values($uuids)) as $user) {
            foreach (SteamAccountService::candidates($user->getSteamAccount()) as ['account' => $account]) {
                if (SteamAccountService::isSteamId64($account)) {
                    $steamAccounts[$account] ??= [
                        'gamer' => $user->getNickname(),
                        'steamProfile' => $account,
                    ];
                    break;
                }
            }
        }

        return new JsonResponse(array_values($steamAccounts));
    }
}
