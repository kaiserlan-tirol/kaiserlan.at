<?php

namespace App\Controller\API;

use App\Entity\ShopOrderPosition;
use App\Entity\ShopOrderPositionTicket;
use App\Idm\IdmManager;
use App\Idm\IdmRepository;
use App\Repository\ShopOrderPositionRepository;
use App\Repository\ShopOrderRepository;
use App\Entity\User;
use App\Service\ShopService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;

#[Route(path: '/catering', name: 'api_catering')]
class CateringApiController extends AbstractController
{
    private readonly ShopOrderPositionRepository $orderPositionRepository;
    private readonly ShopOrderRepository $orderRepository;
    private readonly ShopService $shopService;
    private readonly IdmRepository $userRepo;

    public function __construct(
        ShopOrderPositionRepository $orderPositionRepository,
        ShopOrderRepository $orderRepository,
        ShopService $shopService,
        IdmManager $idmManager
    ) {
        $this->orderPositionRepository = $orderPositionRepository;
        $this->orderRepository = $orderRepository;
        $this->shopService = $shopService;
        $this->userRepo = $idmManager->getRepository(User::class);
    }
    
    /**
     * Get users with redeemed tickets and their addons
     * 
     * @return JsonResponse List of users with their redeemed tickets and addons
     */
    #[Route(path: '/users-with-tickets', name: '_users_with_tickets', methods: ['GET'])]
    public function getUsersWithTickets(): JsonResponse
    {
        // Get all redeemed ticket positions
        $redeemedTickets = $this->orderPositionRepository->findRedeemedTickets();
        
        // Group by user UUID
        $usersWithTickets = [];
        $processedUsers = [];
        
        foreach ($redeemedTickets as $position) {
            // Skip if not a valid position
            if (!$position instanceof ShopOrderPositionTicket || !$position->getTicket() || !$position->getTicket()->getRedeemer()) {
                continue;
            }
            
            $userUuid = $position->getTicket()->getRedeemer()->toString();
            
            // Skip if we already processed this user
            if (in_array($userUuid, $processedUsers)) {
                continue;
            }
            
            // Get user from IDM
            $user = $this->userRepo->findOneById($position->getTicket()->getRedeemer());
            if (!$user) {
                continue;
            }
            
            // Get all addons for this user
            $userAddons = $this->shopService->getUserAddons($user);
            
            // Format addons
            $formattedAddons = [];
            foreach ($userAddons as $addon) {
                $formattedAddons[] = [
                    'id' => $addon->getId(),
                    'label' => $addon->getName()
                ];
            }
            
            // Add to result
            $usersWithTickets[] = [
                'user' => $userUuid,
                'nickname' => $user->getNickname(),
                'firstname' => $user->getFirstname(),
                'surname' => $user->getSurname(),
                'addons' => $formattedAddons
            ];
            
            // Mark as processed
            $processedUsers[] = $userUuid;
        }
        
        return new JsonResponse($usersWithTickets);
    }
}
