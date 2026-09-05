<?php

namespace App\Controller\Site;

use App\Entity\ShopAddon;
use App\Entity\Ticket;
use App\Entity\User;
use App\Entity\CateringOrder;
use App\Repository\TicketRepository;
use App\Service\CateringService;
use App\Service\PizzaService;
use App\Service\SettingService;
use App\Service\ShopService;
use App\Service\TicketService;
use App\Repository\CateringProductRepository;
use App\Idm\IdmManager;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\Routing\Annotation\Route;
use Doctrine\ORM\EntityManagerInterface;
use Ramsey\Uuid\Uuid;

#[Route('/catering/kassa', name: 'catering_kassa_')]
class CateringKassaController extends AbstractController
{
    private readonly CateringService $cateringService;
    private readonly PizzaService $pizzaService;
    private readonly CateringProductRepository $productRepository;
    private readonly IdmManager $idmManager;
    private readonly EntityManagerInterface $entityManager;
    private readonly TicketRepository $ticketRepository;
    private readonly SettingService $settings;
    private readonly ShopService $shopService;
    private readonly TicketService $ticketService;

    public function __construct(
        CateringService $cateringService,
        PizzaService $pizzaService,
        CateringProductRepository $productRepository,
        IdmManager $idmManager,
        EntityManagerInterface $entityManager,
        TicketRepository $ticketRepository,
        SettingService $settings,
        ShopService $shopService,
        TicketService $ticketService
    ) {
        $this->cateringService = $cateringService;
        $this->pizzaService = $pizzaService;
        $this->productRepository = $productRepository;
        $this->idmManager = $idmManager;
        $this->entityManager = $entityManager;
        $this->ticketRepository = $ticketRepository;
        $this->settings = $settings;
        $this->shopService = $shopService;
        $this->ticketService = $ticketService;
    }

    #[Route('/', name: 'index', methods: ['GET', 'POST'])]
    public function index(Request $request): Response
    {
        if ($request->isMethod('POST')) {
            return $this->handleQrCodeInput($request);
        }
        
        return $this->render('site/catering/kassa/index.html.twig');
    }

    #[Route('/scan', name: 'scan', methods: ['GET', 'POST'])]
    public function scan(Request $request): Response
    {
        if ($request->isMethod('POST')) {
            return $this->handleQrCodeInput($request);
        }

        return $this->render('site/catering/kassa/scan.html.twig');
    }
    
    private function handleQrCodeInput(Request $request): Response
    {
        $qrCode = $request->request->get('qr_code');
        
        if (!$qrCode) {
            $this->addFlash('error', 'Kein QR-Code eingegeben.');
            return $this->redirectToRoute('catering_kassa_index');
        }

        // Use the same logic as the QR lookup endpoint
        try {
            $user = null;
            
            // Try direct UUID first
            try {
                $userId = Uuid::fromString($qrCode);
                $userRepo = $this->idmManager->getRepository(User::class);
                $user = $userRepo->findOneById($userId);
            } catch (\Exception $e) {
                // Try other formats
            }
            
            // Try UUID from URL
            if (!$user && preg_match('/[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}/i', $qrCode, $matches)) {
                try {
                    $userId = Uuid::fromString($matches[0]);
                    $userRepo = $this->idmManager->getRepository(User::class);
                    $user = $userRepo->findOneById($userId);
                } catch (\Exception $e) {
                    // Continue
                }
            }
            
            // Try to find by 4-character catering QR code
            if (!$user && strlen(trim($qrCode)) === 4) {
                // Normalize QR code to uppercase
                $normalizedQrCode = strtoupper(trim($qrCode));
                
                // Search for ticket by catering QR code
                $ticket = $this->ticketRepository->findOneBy(['cateringQrCode' => $normalizedQrCode]);
                
                if ($ticket && $ticket->getRedeemer()) {
                    $userRepo = $this->idmManager->getRepository(User::class);
                    $user = $userRepo->findOneById($ticket->getRedeemer());
                }
            }
            
            // Try fuzzy search by nickname
            if (!$user && strlen($qrCode) >= 3) {
                $userRepo = $this->idmManager->getRepository(User::class);
                $users = $userRepo->findFuzzy($qrCode);
                if (count($users) === 1) {
                    $user = $users[0];
                } elseif (count($users) > 1) {
                    $this->addFlash('error', 'Mehrere Benutzer gefunden. Bitte genauer eingeben.');
                    return $this->redirectToRoute('catering_kassa_index');
                }
            }
            
            if (!$user) {
                $this->addFlash('error', 'Benutzer nicht gefunden.');
                return $this->redirectToRoute('catering_kassa_index');
            }

            return $this->redirectToRoute('catering_kassa_products', ['userId' => $user->getUuid()]);
        } catch (\Exception $e) {
            $this->addFlash('error', 'Ungültiger QR-Code oder Eingabe.');
            return $this->redirectToRoute('catering_kassa_index');
        }
    }

    #[Route('/products/{userId}', name: 'products')]
    public function products(string $userId): Response
    {
        try {
            // Log the incoming userId for debugging
            // error_log('Products method called with userId: ' . $userId);
            
            // Cleanup the userId in case it was URL-encoded or has extra characters
            $userId = trim($userId);
            
            // Basic validation before attempting UUID conversion
            if (!$userId) {
                $this->addFlash('error', 'Ungültige Benutzer-ID: Leere ID');
                return $this->redirectToRoute('catering_kassa_scan');
            }
            
            // Ensure the UUID is correctly formatted
            try {
                // Handle both URL-encoded and raw UUIDs
                $decodedUserId = urldecode($userId);
                $userUuid = Uuid::fromString($decodedUserId);
            } catch (\InvalidArgumentException $e) {
                $this->addFlash('error', 'Ungültige UUID: ' . $e->getMessage());
                error_log('Invalid UUID error: ' . $e->getMessage() . ' for userId: ' . $userId);
                return $this->redirectToRoute('catering_kassa_scan');
            }
            
            $userRepo = $this->idmManager->getRepository(User::class);
            $user = $userRepo->findOneById($userUuid);
            
            if (!$user) {
                $this->addFlash('error', 'Benutzer mit ID ' . $userId . ' nicht gefunden.');
                return $this->redirectToRoute('catering_kassa_scan');
            }

            // Get available products
            $products = $this->productRepository->findBy(['active' => true], ['sortIndex' => 'ASC', 'name' => 'ASC']);
            
            // Get user's current credit
            $currentCredit = $this->cateringService->getUserCredit($user);
            
            // Get user's addons and check what products are included
            $userAddons = $this->cateringService->getUserAddons($user);
            $userHasFlatrate = $this->cateringService->userHasFlatrate($user);
            
            // Get the Foodflat addon
            $allAddons = $this->shopService->getAddons(false);
            $foodflatAddon = null;
            foreach ($allAddons as $addon) {
                if ($addon->getName() === 'Foodflat') {
                    $foodflatAddon = $addon;
                    break;
                }
            }
            
            return $this->render('site/catering/kassa/products.html.twig', [
                'user' => $user,
                'products' => $products,
                'user_addons' => $userAddons,
                'user_has_foodflat' => $userHasFlatrate,
                'foodflat_addon' => $foodflatAddon,
                'current_credit' => $currentCredit,
            ]);
        } catch (\Exception $e) {
            // Add more detailed error message with exception information
            $errorMessage = 'Fehler: ' . $e->getMessage();
            $this->addFlash('error', $errorMessage);
            
            // Log the error for debugging
            error_log('Catering Kassa error in products method: ' . $e->getMessage() . ' - Trace: ' . $e->getTraceAsString());
            
            return $this->redirectToRoute('catering_kassa_scan');
        }
    }

    #[Route('/pizza/{userId}', name: 'pizza', methods: ['GET', 'POST'])]
    public function pizza(Request $request, string $userId): Response
    {
        try {
            $userUuid = Uuid::fromString(urldecode(trim($userId)));
        } catch (\InvalidArgumentException) {
            $this->addFlash('error', 'Ungültige Benutzer-ID.');

            return $this->redirectToRoute('catering_kassa_scan');
        }

        $userRepo = $this->idmManager->getRepository(User::class);
        $user = $userRepo->findOneById($userUuid);
        if (!$user) {
            $this->addFlash('error', 'Benutzer nicht gefunden.');

            return $this->redirectToRoute('catering_kassa_scan');
        }

        if (!$this->pizzaService->isOrderingOpen()) {
            $this->addFlash('warning', 'Pizzabestellung ist derzeit nicht möglich.');

            return $this->redirectToRoute('catering_kassa_products', ['userId' => $userId]);
        }

        if ($request->isMethod('POST')) {
            try {
                $this->pizzaService->bookOrder($user, $request->request->all('cart'));
                $this->addFlash('success', 'Pizzabestellung wurde gespeichert.');
            } catch (BadRequestHttpException $e) {
                $this->addFlash('error', $e->getMessage());
            }

            return $this->redirectToRoute('catering_kassa_products', ['userId' => $userId]);
        }

        $pizzas = $this->pizzaService->getPizzas();
        $selection = $this->pizzaService->getSelectionByIndex($user);

        return $this->render('site/catering/kassa/pizza.html.twig', [
            'user' => $user,
            'pizzas' => $pizzas,
            'selection' => $selection,
            'action' => $this->generateUrl('catering_kassa_pizza', ['userId' => $userId]),
            'deadline' => $this->pizzaService->getDeadline(),
            'current_credit' => $this->cateringService->getUserCredit($user),
        ]);
    }

    #[Route('/payment/{userId}', name: 'payment')]
    public function payment(string $userId): Response
    {
        try {
            $userUuid = Uuid::fromString($userId);
            $userRepo = $this->idmManager->getRepository(User::class);
            $user = $userRepo->findOneById($userUuid);
            
            if (!$user) {
                $this->addFlash('error', 'Benutzer nicht gefunden.');
                return $this->redirectToRoute('catering_kassa_index');
            }

            // Get user's current credit and transaction history
            $currentCredit = $this->cateringService->getUserCredit($user);
            $transactions = $this->cateringService->getUserTransactionHistory($user);
            
            // Get user's ticket and addons
            $ticket = $this->ticketService->getTicketUser($user);
            $userAddons = $ticket ? $this->shopService->getUserAddons($user) : [];
            $allAddons = $this->shopService->getAddons();
            
            return $this->render('site/catering/kassa/payment.html.twig', [
                'user' => $user,
                'current_credit' => $currentCredit,
                'transactions' => $transactions,
                'ticket' => $ticket,
                'userAddons' => $userAddons,
                'allAddons' => $allAddons,
            ]);
        } catch (\Exception $e) {
            $this->addFlash('error', 'Ungültiger Benutzer.');
            return $this->redirectToRoute('catering_kassa_index');
        }
    }

    #[Route('/book-addon/{userId}/{addonId}', name: 'book_addon', methods: ['POST'])]
    public function bookAddon(string $userId, int $addonId): Response
    {
        try {
            $userUuid = Uuid::fromString($userId);
            $userRepo = $this->idmManager->getRepository(User::class);
            $user = $userRepo->findOneById($userUuid);
            
            if (!$user) {
                return $this->json(['success' => false, 'message' => 'Benutzer nicht gefunden.']);
            }

            $ticket = $this->ticketService->getTicketUser($user);
            if (!$ticket) {
                return $this->json(['success' => false, 'message' => 'Kein Ticket gefunden.']);
            }

            $addon = $this->entityManager->getRepository(ShopAddon::class)->find($addonId);
            if (!$addon) {
                return $this->json(['success' => false, 'message' => 'Addon nicht gefunden.']);
            }

            $this->shopService->addAddonToTicketWithCateringBalance($ticket, $addon);
            
            return $this->json([
                'success' => true, 
                'message' => sprintf('"%s" erfolgreich gebucht!', $addon->getName())
            ]);
        } catch (\Exception $e) {
            return $this->json([
                'success' => false, 
                'message' => sprintf('Fehler: %s', $e->getMessage())
            ]);
        }
    }

    #[Route('/checkout/{userId}', name: 'checkout', methods: ['POST'])]
    public function checkout(Request $request, string $userId): Response
    {
        try {
            $userUuid = Uuid::fromString($userId);
            $userRepo = $this->idmManager->getRepository(User::class);
            $user = $userRepo->findOneById($userUuid);
            
            if (!$user) {
                return $this->json(['success' => false, 'message' => 'Benutzer nicht gefunden.']);
            }

            $cartItems = $request->request->all('cart');
            
            if (empty($cartItems)) {
                return $this->json(['success' => false, 'message' => 'Warenkorb ist leer.']);
            }

            // Get user's addons to check for included products
            $userAddons = $this->cateringService->getUserAddons($user);

            // Create the catering order
            $order = $this->cateringService->allocOrder($user);
            $hasItems = false;
            $totalCost = 0;
            
            foreach ($cartItems as $productId => $quantity) {
                if ($quantity <= 0) continue;
                
                $product = $this->productRepository->find($productId);
                if (!$product || !$product->isActive()) {
                    return $this->json(['success' => false, 'message' => 'Ungültiges Produkt: ' . $productId]);
                }
                
                // Add product to order (this handles addon pricing automatically)
                $position = $this->cateringService->orderAddProduct($order, $product, $quantity);
                $totalCost += $position->getTotalPrice();
                $hasItems = true;
            }

            if (!$hasItems) {
                return $this->json(['success' => false, 'message' => 'Warenkorb ist leer.']);
            }

            // Check if user has enough credit for the calculated total
            $allowNegative = $this->settings->get("catering.allow_negative_credit") ?? false;
            $userCredit = $this->cateringService->getUserCredit($user);
            if (!$allowNegative && $userCredit < $totalCost) {
                return $this->json([
                    'success' => false, 
                    'message' => 'Nicht genügend Guthaben. Benötigt: ' . number_format($totalCost/100, 2) . '€, Verfügbar: ' . number_format($userCredit/100, 2) . '€'
                ]);
            }
            

            // Place the order (this will create the order or mark it as paid if it's free)
            $this->cateringService->placeOrder($order);
            
            // If there's a cost, deduct from user credit and mark as paid
            if ($totalCost > 0) {
                // Deduct credit (always succeeds, allowing negative balance)
                $this->cateringService->deductUserCredit($user, $totalCost, $order);
                
                // Mark order as paid
                $this->cateringService->setOrderPaid($order);
            }
            // Free orders (totalCost = 0) are already marked as Paid by placeOrder
            
            return $this->json([
                'success' => true, 
                'message' => 'Bestellung #' . $order->getId() . ' erfolgreich! Betrag: ' . number_format($totalCost/100, 2) . '€',
                'redirect' => $this->generateUrl('catering_kassa_confirmation', [
                    'userId' => $user->getUuid(), 
                    'orderId' => $order->getId()
                ]),
                'orderId' => $order->getId(),
                'total' => $totalCost
            ]);

        } catch (\Exception $e) {
            return $this->json(['success' => false, 'message' => 'Fehler: ' . $e->getMessage()]);
        }
    }

    #[Route('/qr-lookup', name: 'qr_lookup', methods: ['POST'])]
    public function qrLookup(Request $request): Response
    {
        $qrCode = $request->request->get('qr_code');
        
        if (!$qrCode) {
            return $this->json(['success' => false, 'message' => 'Kein QR-Code empfangen.']);
        }

        try {
            // Try different QR code formats
            $user = null;
            
            // Format 1: Direct UUID
            try {
                $userId = Uuid::fromString($qrCode);
                $userRepo = $this->idmManager->getRepository(User::class);
                $user = $userRepo->findOneById($userId);
            } catch (\Exception $e) {
                // Try other formats if UUID parsing fails
            }
            
            // Format 2: URL with UUID (e.g., https://kaiserlan.at/user/123e4567-e89b-12d3-a456-426614174000)
            if (!$user && preg_match('/[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}/i', $qrCode, $matches)) {
                try {
                    $userId = Uuid::fromString($matches[0]);
                    $userRepo = $this->idmManager->getRepository(User::class);
                    $user = $userRepo->findOneById($userId);
                } catch (\Exception $e) {
                    // Continue to next format
                }
            }
            
            // Format 3: Try to find by catering QR code
            if (!$user) {
                $normalizedQrCode = strtoupper(trim($qrCode));
                
                $ticket = $this->ticketRepository->findOneBy(['cateringQrCode' => $normalizedQrCode]);
                
                if ($ticket && $ticket->getRedeemer()) {
                    $userRepo = $this->idmManager->getRepository(User::class);
                    $user = $userRepo->findOneById($ticket->getRedeemer());
                }
            }
            
            // Format 4: Try to find by nickname if it's a simple string
            if (!$user && ctype_alnum($qrCode)) {
                $userRepo = $this->idmManager->getRepository(User::class);
                $users = $userRepo->findFuzzy($qrCode);
                if (count($users) === 1) {
                    $user = $users[0];
                }
            }
            
            if (!$user) {
                return $this->json(['success' => false, 'message' => 'Benutzer nicht gefunden. Bitte QR-Code erneut scannen.']);
            }
            
            // Get the UUID as string explicitly to ensure it's formatted correctly
            $userUuidString = $user->getUuid()->toString();
            
            // Generate URL with strict parameter checking
            $redirectUrl = $this->generateUrl(
                'catering_kassa_products', 
                ['userId' => $userUuidString]
            );
            
            // Log successful user lookup
            // error_log('QR Lookup success: Found user ' . $user->getNickname() . ' with UUID ' . $userUuidString);
            // error_log('Generated redirect URL: ' . $redirectUrl);
            
            return $this->json([
                'success' => true,
                'user' => [
                    'uuid' => $userUuidString,
                    'nickname' => $user->getNickname(),
                    'name' => $user->getFirstname() . ' ' . $user->getSurname()
                ],
                'redirect' => $redirectUrl
            ]);
            
        } catch (\Exception $e) {
            // Log error for debugging
            error_log('QR Lookup error: ' . $e->getMessage() . ' - Trace: ' . $e->getTraceAsString());
            
            return $this->json([
                'success' => false, 
                'message' => 'Fehler beim Verarbeiten des QR-Codes: ' . $e->getMessage(),
                'debugInfo' => [
                    'qrType' => gettype($qrCode),
                    'qrLength' => strlen($qrCode),
                    'error' => $e->getMessage()
                ]
            ]);
        }
    }

    #[Route('/session-check', name: 'session_check', methods: ['GET'])]
    public function sessionCheck(): Response
    {
        // Simple endpoint to check if session is still active
        // In a real kiosk setup, you might want to implement session timeouts
        return $this->json(['active' => true, 'timestamp' => time()]);
    }

    #[Route('/confirmation/{userId}/{orderId}', name: 'confirmation')]
    public function confirmation(string $userId, int $orderId): Response
    {
        try {
            $userUuid = Uuid::fromString($userId);
            $userRepo = $this->idmManager->getRepository(User::class);
            $user = $userRepo->findOneById($userUuid);
            
            if (!$user) {
                $this->addFlash('error', 'Benutzer nicht gefunden.');
                return $this->redirectToRoute('catering_kassa_index');
            }

            // Get the order
            $order = $this->entityManager->getRepository(CateringOrder::class)->find($orderId);
            if (!$order) {
                $this->addFlash('error', 'Bestellung nicht gefunden.');
                return $this->redirectToRoute('catering_kassa_index');
            }

            // Get user's current credit after the order
            $currentCredit = $this->cateringService->getUserCredit($user);
            $total = $order->getTotalPrice();
            
            return $this->render('site/catering/kassa/confirmation.html.twig', [
                'user' => $user,
                'current_credit' => $currentCredit,
                'orderId' => $orderId,
                'total' => $total
            ]);
        } catch (\Exception $e) {
            $this->addFlash('error', 'Fehler: ' . $e->getMessage());
            return $this->redirectToRoute('catering_kassa_index');
        }
    }

    #[Route('/manifest.webmanifest', name: 'manifest', methods: ['GET'])]
    public function manifest(): JsonResponse
    {
        $response = new JsonResponse([
            'name' => 'KaiserLAN Kassa',
            'short_name' => 'Kassa',
            'description' => 'Kassa Terminal für Catering-Bestellungen',
            'lang' => 'de',
            'start_url' => $this->generateUrl('catering_kassa_index'),
            'scope' => $this->generateUrl('catering_kassa_index'),
            'display' => 'fullscreen',
            'background_color' => '#007be6',
            'theme_color' => '#007be6',
            'icons' => [
                [
                    'src' => '/kassa-icon-192.png',
                    'sizes' => '192x192',
                    'type' => 'image/png',
                    'purpose' => 'any maskable',
                ],
                [
                    'src' => '/kassa-icon-512.png',
                    'sizes' => '512x512',
                    'type' => 'image/png',
                    'purpose' => 'any maskable',
                ],
            ],
        ]);
        $response->headers->set('Content-Type', 'application/manifest+json');

        return $response;
    }

    #[Route('/sw.js', name: 'sw', methods: ['GET'])]
    public function serviceWorker(): Response
    {
        $response = $this->render('site/catering/kassa/sw.js.twig');
        $response->headers->set('Content-Type', 'application/javascript; charset=UTF-8');
        $response->headers->set('Cache-Control', 'no-cache');

        return $response;
    }
}
