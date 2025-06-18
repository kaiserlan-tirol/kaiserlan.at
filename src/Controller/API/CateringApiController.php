<?php

namespace App\Controller\API;

use App\Entity\ShopOrderPosition;
use App\Entity\ShopOrderPositionTicket;
use App\Entity\CateringOrderStatus;
use App\Entity\CateringProduct;
use App\Idm\IdmManager;
use App\Idm\IdmRepository;
use App\Repository\CateringProductRepository;
use App\Repository\ShopOrderPositionRepository;
use App\Repository\ShopOrderRepository;
use App\Entity\User;
use App\Service\CateringService;
use App\Service\ShopService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;
use Ramsey\Uuid\Uuid;

#[Route(path: '/catering', name: 'api_catering')]
class CateringApiController extends AbstractController
{
    private readonly ShopOrderPositionRepository $orderPositionRepository;
    private readonly ShopOrderRepository $orderRepository;
    private readonly CateringProductRepository $productRepository;
    private readonly ShopService $shopService;
    private readonly CateringService $cateringService;
    private readonly IdmRepository $userRepo;

    public function __construct(
        ShopOrderPositionRepository $orderPositionRepository,
        ShopOrderRepository $orderRepository,
        CateringProductRepository $productRepository,
        ShopService $shopService,
        CateringService $cateringService,
        IdmManager $idmManager
    ) {
        $this->orderPositionRepository = $orderPositionRepository;
        $this->orderRepository = $orderRepository;
        $this->productRepository = $productRepository;
        $this->shopService = $shopService;
        $this->cateringService = $cateringService;
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
    
    /**
     * Create a new catering order
     * 
     * @param Request $request The request containing order data
     * @return JsonResponse The response with the created order ID
     */
    #[Route(path: '/order', name: '_create_order', methods: ['POST'])]
    public function createOrder(Request $request): JsonResponse
    {
        // Parse request body
        $data = json_decode($request->getContent(), true);
        
        if (!$data || !isset($data['userId']) || !isset($data['order']) || !is_array($data['order'])) {
            return new JsonResponse(['error' => 'Invalid request format'], Response::HTTP_BAD_REQUEST);
        }
        
        // Validate user ID
        $userId = $data['userId'];
        if (!Uuid::isValid($userId)) {
            return new JsonResponse(['error' => 'Invalid user ID format'], Response::HTTP_BAD_REQUEST);
        }
        
        // Check if order should be marked as paid (default is false)
        $isPaid = isset($data['paid']) && $data['paid'] === true;
        
        // Get user
        $userUuid = Uuid::fromString($userId);
        $user = $this->userRepo->findOneById($userUuid);
        
        if (!$user) {
            return new JsonResponse(['error' => 'User not found'], Response::HTTP_NOT_FOUND);
        }
        
        // Create an order
        $order = $this->cateringService->allocOrder($user);
        
        // Process the order items
        foreach ($data['order'] as $item) {
            if (!isset($item['product']) || !isset($item['amount']) || (int)$item['amount'] <= 0) {
                continue; // Skip invalid items
            }
            
            // Find product by code
            $product = $this->cateringService->getProductByCode($item['product']);
            
            if (!$product) {
                continue; // Skip if product not found
            }
            
            // Add product to order
            $this->cateringService->orderAddProduct($order, $product, (int)$item['amount']);
        }
        
        // If the order is empty, return an error
        if ($order->isEmpty()) {
            return new JsonResponse(['error' => 'No valid products in order'], Response::HTTP_BAD_REQUEST);
        }
        
        try {
            // Place and persist the order
            $this->cateringService->placeOrder($order);
            
            // Set order as paid if flag is true
            if ($isPaid) {
                $this->cateringService->setOrderPaid($order);
            }
            
            $this->cateringService->persistOrder($order);
            
            // Return success with order ID
            return new JsonResponse([
                'success' => true,
                'orderId' => $order->getId(),
                'total' => $order->calculateTotal(),
                'status' => $order->getStatus()->name,
                'paid' => $order->getStatus() === CateringOrderStatus::Paid
            ]);
            
        } catch (\Exception $e) {
            return new JsonResponse(['error' => 'Failed to process order: ' . $e->getMessage()], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }
    
    /**
     * Get all active products
     * 
     * @return JsonResponse List of all active products
     */
    #[Route(path: '/products', name: '_products', methods: ['GET'])]
    public function getProducts(): JsonResponse
    {
        // Get all active products
        $activeProducts = $this->productRepository->findActive();
        
        // Format products for the response
        $formattedProducts = [];
        foreach ($activeProducts as $product) {
            $formattedProducts[] = [
                'id' => $product->getId(),
                'name' => $product->getName(),
                'description' => $product->getDescription(),
                'price' => $product->getPrice(),
                'productCode' => $product->getProductCode(),
                'sortIndex' => $product->getSortIndex(),
                'includedInAddons' => array_map(function($addon) {
                    return [
                        'id' => $addon->getId(),
                        'name' => $addon->getName()
                    ];
                }, $product->getIncludedInAddons()->toArray())
            ];
        }
        
        return new JsonResponse($formattedProducts);
    }
}
