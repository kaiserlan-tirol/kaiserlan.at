<?php

namespace App\Controller\API;

use App\Entity\ShopOrderPosition;
use App\Entity\ShopOrderPositionTicket;
use App\Entity\CateringOrderStatus;
use App\Entity\CateringProduct;
use App\Entity\Ticket;
use App\Idm\IdmManager;
use App\Idm\IdmRepository;
use App\Repository\CateringProductRepository;
use App\Repository\ShopOrderPositionRepository;
use App\Repository\ShopOrderRepository;
use App\Repository\TicketRepository;
use App\Entity\User;
use App\Service\CateringService;
use App\Service\ShopService;
use App\Service\TicketService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;
use Doctrine\ORM\EntityManagerInterface;
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
    private readonly EntityManagerInterface $entityManager;
    private readonly TicketRepository $ticketRepository;

    public function __construct(
        ShopOrderPositionRepository $orderPositionRepository,
        ShopOrderRepository $orderRepository,
        CateringProductRepository $productRepository,
        ShopService $shopService,
        CateringService $cateringService,
        IdmManager $idmManager,
        EntityManagerInterface $entityManager,
        TicketRepository $ticketRepository
    ) {
        $this->orderPositionRepository = $orderPositionRepository;
        $this->orderRepository = $orderRepository;
        $this->productRepository = $productRepository;
        $this->shopService = $shopService;
        $this->cateringService = $cateringService;
        $this->userRepo = $idmManager->getRepository(User::class);
        $this->entityManager = $entityManager;
        $this->ticketRepository = $ticketRepository;
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
            
            // Get ticket info including catering QR code
            $ticket = $position->getTicket();
            $cateringQrCode = $ticket->getCateringQrCode();
            
            // Add to result
            $usersWithTickets[] = [
                'user' => $userUuid,
                'nickname' => $user->getNickname(),
                'firstname' => $user->getFirstname(),
                'surname' => $user->getSurname(),
                'addons' => $formattedAddons,
                'cateringQrCode' => $cateringQrCode
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
    
    /**
     * Generate QR code labels for manual entry
     * 
     * @param Request $request The request with ?count= query parameter
     * @return Response HTML page with QR code labels ready for printing
     */
    #[Route(path: '/generate-labels', name: '_generate_labels', methods: ['GET'])]
    public function generateLabels(Request $request): Response
    {
        // Get count from query parameter, default to 10, max 100
        $count = (int) $request->query->get('count', 10);
        $count = max(1, min(100, $count)); // Ensure count is between 1 and 100
        
        // Generate labels with random QR codes directly (not tied to tickets)
        $labels = [];
        $existingCodes = $this->ticketRepository->findAllCateringQrCodes();
        
        for ($i = 0; $i < $count; $i++) {
            // Generate a unique catering QR code
            $cateringCode = $this->createUniqueQrCode($existingCodes);
            $existingCodes[] = $cateringCode; // Add to our tracking array
            
            // Add the catering QR code to labels
            $labels[] = [
                'id' => $cateringCode,
                'fullCode' => 'QR-' . $cateringCode  // Just a placeholder, no longer linked to tickets
            ];
        }
        
        return $this->render('api/catering/labels.html.twig', [
            'labels' => $labels,
            'count' => $count
        ]);
    }
    
    /**
     * Create a unique 4-character catering QR code
     */
    private function createUniqueQrCode(array $existingCodes): string
    {
        // Use only uppercase letters and numbers that aren't easily confused
        $chars = '23456789ABCDEFGHJKLMNPQRSTUVWXYZ'; // removed 0,1,I,O to avoid confusion
        
        do {
            $cateringCode = '';
            for ($i = 0; $i < 4; $i++) {
                $cateringCode .= $chars[mt_rand(0, strlen($chars) - 1)];
            }
        } while (in_array($cateringCode, $existingCodes));
        
        return $cateringCode;
    }
}
