<?php

namespace App\Controller\Admin;

use App\Entity\CateringOrder;
use App\Entity\CateringProduct;
use App\Entity\User;
use App\Exception\OrderLifecycleException;
use App\Form\CateringProductType;
use App\Form\CateringManualOrderType;
use App\Idm\IdmManager;
use App\Idm\IdmRepository;
use App\Repository\CateringOrderRepository;
use App\Service\CateringService;
use Ramsey\Uuid\Uuid;
use Sensio\Bundle\FrameworkExtraBundle\Configuration\IsGranted;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\Serializer\SerializerInterface;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;

#[IsGranted('ROLE_ADMIN_PAYMENT')]
#[Route(path: '/catering', name: 'catering')]
class CateringController extends AbstractController {
    private readonly CateringService $cateringService;
    private readonly CateringOrderRepository $orderRepository;
    private readonly SerializerInterface $serializer;
    private readonly IdmRepository $userRepo;
    private readonly LoggerInterface $logger;
    private readonly EntityManagerInterface $em;

    private const CSRF_TOKEN_PAYED = 'cateringToken';

    public function __construct(
        CateringService $cateringService, 
        CateringOrderRepository $orderRepository, 
        SerializerInterface $serializer, 
        IdmManager $idmManager,
        EntityManagerInterface $em,
        LoggerInterface $logger
    ) {
        $this->cateringService = $cateringService;
        $this->orderRepository = $orderRepository;
        $this->serializer = $serializer;
        $this->userRepo = $idmManager->getRepository(User::class);
        $this->em = $em;
        $this->logger = $logger;
    }

    #[Route(path: '', name: '', methods: ['GET'])]
    public function index(): Response 
    {
        $orders = $this->orderRepository->findAll();

        return $this->render('admin/catering/index.html.twig', [
            'orders' => $orders
        ]);
    }

    #[Route(path: '/order/{id}', name:'_edit', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function edit(Request $request, ?CateringOrder $order): Response
    {
        if (empty($order)) {
            throw $this->createNotFoundException('Order not found');
        }
        
        $token = $request->request->get('_token');
        if (!$this->isCsrfTokenValid(self::CSRF_TOKEN_PAYED, $token)) {
            throw $this->createAccessDeniedException('Invalid CSRF token presented');
        }

        $action = $request->request->get('action');
        try {
            switch ($action) {
                case 'cancel':
                    $this->cateringService->cancelOrder($order);
                    break;
                case 'paid':
                    $this->cateringService->setOrderPaid($order);
                    break;
                case 'undo':
                    $this->cateringService->setOrderPaidUndo($order);
                    break;
                case 'delete':
                    $this->cateringService->deleteOrder($order);
                    break;
                case 'refund':
                    $this->cateringService->refundOrder($order);
                    break;
                default:
                    $this->addFlash('error', 'Invalid action specified.');
                    return $this->redirectToRoute('admin_catering');
            }
        } catch (OrderLifecycleException $e) {
            $errorMessage = $e->getMessage() ? "Aktion konnte nicht durchgeführt werden: {$e->getMessage()}" : "Aktion konnte nicht durchgeführt werden. Diese Statusänderung ist nicht erlaubt.";
            $this->logger->warning($errorMessage, [
                'orderId' => $order->getId(),
                'action' => $action,
                'status' => $order->getStatus()->name
            ]);
            $this->addFlash('error', $errorMessage);
            return $this->redirectToRoute('admin_catering');
        }

        $this->addFlash('success', "Änderung an Order {$order->getId()} erfolgreich.");
        return $this->redirectToRoute('admin_catering');
    }

    #[Route(path: '/order/{id}', name: '_show', requirements: ['id' => '\d+'], methods: ['GET'])]
    public function show(Request $request, ?CateringOrder $order): Response
    {
        if (empty($order)) {
            throw $this->createNotFoundException('Order not found');
        }

        // Allow both AJAX and direct access to this route
        $template = $request->isXmlHttpRequest() 
            ? 'admin/catering/show.html.twig' 
            : 'admin/catering/show_full.html.twig';

        return $this->render($template, [
            'order' => $order,
            'csrf_token' => self::CSRF_TOKEN_PAYED
        ]);
    }

    #[Route(path: '/product', name: '_product', methods: ['GET'])]
    public function indexProducts(): Response
    {
        $products = $this->cateringService->getProducts(all: true);
        return $this->render('admin/catering/product.html.twig', [
            'products' => $products,
            'countProducts' => $this->cateringService->countOrderedProducts(),
            'countProductsPaid' => $this->cateringService->countOrderedProducts(null, true),
        ]);
    }

    #[Route(path: '/product/new', name:'_product_new', methods: ['GET', 'POST'])]
    public function newProduct(Request $request): Response
    {
        $product = $this->cateringService->allocProduct();
        $form = $this->createForm(CateringProductType::class, $product, [
            'action' => $this->generateUrl('admin_catering_product_new'),
            'method' => 'POST',
        ]);
        $form->handleRequest($request);
        if ($form->isSubmitted() && $form->isValid()) {
            $product = $form->getData();
            
            // Handle image upload if present
            $imageFile = $form->get('image')->getData();
            if ($imageFile) {
                // We're not using images in the Kassa frontend for now,
                // so we'll just set the image property to a placeholder
                $product->setImage('no-image.png');
            }
            
            $savedProduct = $this->cateringService->saveProduct($product);
            $this->addFlash('success', "Produkt wurde erfolgreich angelegt.");
            return $this->redirectToRoute('admin_catering_product');
        }
        
        $template = $request->isXmlHttpRequest() 
            ? 'admin/catering/show_product.modal.html.twig' 
            : 'admin/catering/show_product.html.twig';
            
        return $this->render($template, [
            'product' => $product,
            'form' => $form->createView(),
            'csrf_token' => self::CSRF_TOKEN_PAYED
        ]);
    }

    #[Route(path: '/product/{id}', name: '_product_edit', requirements: ['id' => '\d+'], methods: ['GET', 'POST'])]
    public function editProduct(Request $request, CateringProduct $product): Response
    {
        $form = $this->createForm(CateringProductType::class, $product, [
            'action' => $this->generateUrl('admin_catering_product_edit', ['id' => $product->getId()]),
            'method' => 'POST',
        ]);

        $form->handleRequest($request);
        if ($form->isSubmitted()) {
            if ($form->isValid()) {
                $formData = $form->getData();
                
                // Handle image upload if present
                $imageFile = $form->get('image')->getData();
                if ($imageFile) {
                    // We're not using images in the Kassa frontend for now,
                    // so we'll just set the image property to a placeholder
                    $formData->setImage('no-image.png');
                }
                
                $savedProduct = $this->cateringService->saveProduct($formData);
                
                $this->addFlash('success', "Änderung an Produkt {$savedProduct->getId()} erfolgreich.");
                return $this->redirectToRoute('admin_catering_product');
            } else {
                // Form has validation errors
                $errors = [];
                foreach ($form->getErrors(true) as $error) {
                    $errors[] = $error->getMessage();
                }
                $this->addFlash('error', 'Formular enthält Fehler: ' . implode(', ', $errors));
            }
        }

        $template = $request->isXmlHttpRequest() 
            ? 'admin/catering/show_product.modal.html.twig' 
            : 'admin/catering/show_product.html.twig';

        return $this->render($template, [
            'product' => $product,
            'form' => $form->createView(),
            'csrf_token' => self::CSRF_TOKEN_PAYED
        ]);
    }

    #[Route(path: '/product/{id}/toggle', name:'_product_toggle', requirements: ['id' => '\d+'], methods: ['GET'])]
    public function toggleProduct(CateringProduct $product): Response
    {
        // TODO change to post and add token
        $this->cateringService->toggleProductActivity($product);

        return $this->redirectToRoute('admin_catering_product');
    }

    #[Route(path: '/product/{id}/delete', name:'_product_delete', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function deleteProduct(Request $request, CateringProduct $product): Response
    {
        $token = $request->request->get('_token');
        if (!$this->isCsrfTokenValid(self::CSRF_TOKEN_PAYED, $token)) {
            throw $this->createAccessDeniedException('Invalid CSRF token presented');
        }
        $this->cateringService->deleteProduct($product);
        $this->addFlash('success', "Produkt {$product->getId()} wurde gelöscht.");

        return $this->redirectToRoute('admin_catering_product');
    }

    #[Route(path: '/order/export', name:'_order_export', methods: ['GET'])]
    public function exportOrder(): Response
    {
        $csvData = [];

        $orders = $this->cateringService->getOrders();

        foreach ($orders as $o) {
            /** @var User $user */
            $user = $o['user'];
            /** @var CateringOrder $order */
            $order = $o['order'];
            $csvData[] = [
                'uuid' => $user->getUuid()->toString(),
                'nickname' => $user->getNickname(),
                'vorname' => $user->getFirstname(),
                'nachname' => $user->getSurname(),
                'date' => $order->getCreatedAt()->format('Y-m-d H:i:s'),
                'items' => $order->getTotalItems(),
                'amount' => $order->calculateTotal(),
                'status' => $order->getStatus()->name,
            ];
        }

        $response = new Response();
        $response->headers->set('Content-Type', 'text/csv');
        $response->headers->set('Content-Disposition', 'attachment; filename="catering_orders.csv"');

        $output = fopen('php://output', 'w');
        if (!empty($csvData)) {
            fputcsv($output, array_keys($csvData[0]));
            foreach ($csvData as $row) {
                fputcsv($output, $row);
            }
        }
        fclose($output);

        return $response;
    }

    #[Route(path: '/order/create', name: '_order_create', methods: ['GET', 'POST'])]
    public function createOrder(Request $request): Response
    {
        // Debug information
        $method = $request->getMethod();
        $this->logger->info('Request to createOrder', [
            'method' => $method,
            'path' => $request->getPathInfo(),
            'query' => $request->query->all(),
            'request' => $request->request->all(),
            'content_type' => $request->headers->get('Content-Type'),
            'is_ajax' => $request->isXmlHttpRequest(),
            'form_submitted' => $request->isMethod('POST')
        ]);
        
        // Method already captured in the debug log above
        
        $products = $this->cateringService->getProducts();
        
        $form = $this->createForm(\App\Form\CateringManualOrderType::class, null, [
            'products' => $products,
        ]);

        // Debug information
        $this->logger->info('Request received', [
            'method' => $request->getMethod(),
            'path' => $request->getPathInfo(),
            'content_type' => $request->headers->get('Content-Type'),
        ]);
        
        $form->handleRequest($request);
        if ($form->isSubmitted()) {
            $this->logger->info('Form submitted', [
                'valid' => $form->isValid(),
                'errors' => $this->getFormErrors($form)
            ]);
            
            if ($form->isValid()) {
                $data = $form->getData();
                $user = $data['user'];
            
                if (!$user) {
                    $this->addFlash('error', 'Bitte wählen Sie einen Benutzer aus.');
                    $template = $request->isXmlHttpRequest() 
                        ? 'admin/catering/create_order.modal.html.twig' 
                        : 'admin/catering/create_order.html.twig';
                    return $this->render($template, [
                        'form' => $form->createView(),
                        'products' => $products
                    ]);
                }
            
            // Handle guest user case
            $isGuest = false;
            if ($user instanceof User && $user->getUuid()->toString() === '00000000-0000-0000-0000-000000000000') {
                $isGuest = true;
            }

            $order = $this->cateringService->allocOrder($user);
            $hasItems = false;

            // Add products to order
            foreach ($products as $product) {
                $quantity = $data['product' . $product->getId()] ?? 0;
                if ($quantity > 0) {
                    // Use the service method to add product with proper pricing
                    // This automatically handles flatrate products (setting price to 0)
                    $this->cateringService->orderAddProduct($order, $product, $quantity);
                    $hasItems = true;
                }
            }

            if (!$hasItems) {
                $this->addFlash('error', 'Bitte wählen Sie mindestens ein Produkt aus.');
                $template = $request->isXmlHttpRequest() 
                    ? 'admin/catering/create_order.modal.html.twig' 
                    : 'admin/catering/create_order.html.twig';
                return $this->render($template, [
                    'form' => $form->createView(),
                    'products' => $products
                ]);
            }

            try {
                $this->cateringService->persistOrder($order);
                
                // Custom success message for guest orders
                if ($isGuest) {
                    $this->addFlash('success', "Gast-Bestellung wurde erfolgreich erstellt.");
                } else {
                    $this->addFlash('success', "Bestellung für {$user->getNickname()} wurde erfolgreich erstellt.");
                }
                
                return $this->redirectToRoute('admin_catering');
            } catch (\Exception $e) {
                $this->addFlash('error', 'Fehler beim Erstellen der Bestellung: ' . $e->getMessage());
            }
            }
        }

        $template = $request->isXmlHttpRequest() 
            ? 'admin/catering/create_order.modal.html.twig' 
            : 'admin/catering/create_order.html.twig';

        return $this->render($template, [
            'form' => $form->createView(),
            'products' => $products,
            'hideProductsInitially' => true
        ]);
    }
    
    #[Route(path: '/user-products/{uuid}', name: '_user_products', methods: ['GET'])]
    public function getUserProducts(string $uuid): Response
    {
        try {
            // Special case for "guest" - a hardcoded string to indicate a guest user
            $isGuest = ($uuid === 'guest' || $uuid === 'gast');
            
            if (!$isGuest) {
                $user = $this->userRepo->findOneById(Uuid::fromString($uuid));
                if (!$user) {
                    return $this->json(['error' => 'User not found'], 404);
                }
            } else {
                // Create a temporary guest user
                $user = new User();
                $user->setUuid(Uuid::fromString('00000000-0000-0000-0000-000000000000'));
                $user->setNickname('Gast');
                $user->setEmail('guest@example.com');
            }
            
            $allProducts = $this->cateringService->getProducts();
            $userAddons = $isGuest ? [] : $this->cateringService->getUserAddons($user);
            $productData = [];
            
            foreach ($allProducts as $product) {
                // For guest users, nothing is included in flatrate
                $includedInFlatrate = $isGuest ? false : $product->isIncludedInAnyAddon($userAddons);
                $productData[] = [
                    'id' => $product->getId(),
                    'name' => $product->getName(),
                    'description' => $product->getDescription(),
                    'price' => $product->getPrice(),
                    'includedInFlatrate' => $includedInFlatrate,
                    'addons' => array_map(function($addon) {
                        return [
                            'id' => $addon->getId(),
                            'name' => $addon->getName()
                        ];
                    }, $product->getIncludedInAddons()->toArray())
                ];
            }
            
            $userData = $isGuest 
                ? ['uuid' => 'guest', 'nickname' => 'Gast'] 
                : ['uuid' => $user->getUuid(), 'nickname' => $user->getNickname()];
            
            return $this->json([
                'success' => true,
                'products' => $productData,
                'user' => $userData
            ]);
            
        } catch (\Exception $e) {
            return $this->json(['error' => $e->getMessage()], 500);
        }
    }

    #[Route(path: '/payments', name:'_payments', methods: ['GET'])]
    public function paymentsList(): Response
    {
        // Get all users with payment sent orders
        $paymentSentOrders = $this->orderRepository->findBy([
            'status' => \App\Entity\CateringOrderStatus::PaymentSent
        ], ['createdAt' => 'DESC']);
        
        // Group orders by user
        $ordersByUser = [];
        foreach ($paymentSentOrders as $order) {
            $userId = $order->getOrderer()->toString();
            if (!isset($ordersByUser[$userId])) {
                $user = $this->userRepo->findOneById($order->getOrderer());
                $ordersByUser[$userId] = [
                    'user' => $user,
                    'orders' => [],
                    'total' => 0
                ];
            }
            $ordersByUser[$userId]['orders'][] = $order;
            $ordersByUser[$userId]['total'] += $order->calculateTotal();
        }
        
        return $this->render('admin/catering/payments.html.twig', [
            'users_with_pending_payments' => $ordersByUser,
        ]);
    }
    
    #[Route(path: '/process-payment/{userId}', name:'_process_payment', methods: ['GET', 'POST'])]
    public function processPayment(Request $request, string $userId): Response
    {
        $user = $this->userRepo->findOneById(Uuid::fromString($userId));
        
        if (!$user) {
            $this->addFlash('error', 'Benutzer nicht gefunden.');
            return $this->redirectToRoute('admin_catering_payments');
        }
        
        // Get user's payment sent orders
        $paymentSentOrders = $this->orderRepository->findBy([
            'orderer' => $user->getUuid(),
            'status' => \App\Entity\CateringOrderStatus::PaymentSent
        ], ['createdAt' => 'ASC']);
        
        // Also get any open orders
        $openOrders = $this->orderRepository->findBy([
            'orderer' => $user->getUuid(),
            'status' => \App\Entity\CateringOrderStatus::Created
        ], ['createdAt' => 'ASC']);
        
        // Calculate totals
        $totalPaymentSent = 0;
        foreach ($paymentSentOrders as $order) {
            $totalPaymentSent += $order->calculateTotal();
        }
        
        $totalOpenOrders = 0;
        foreach ($openOrders as $order) {
            $totalOpenOrders += $order->calculateTotal();
        }
        
        // Get current credit balance
        $currentCredit = $this->cateringService->getUserCredit($user);
        
        if ($request->isMethod('POST')) {
            $token = $request->request->get('_token');
            if (!$this->isCsrfTokenValid(self::CSRF_TOKEN_PAYED, $token)) {
                throw $this->createAccessDeniedException('Invalid CSRF token presented');
            }
            
            $amount = (int)($request->request->get('payment_amount') * 100); // Convert to cents
            $note = $request->request->get('payment_note');
            
            if ($amount <= 0) {
                $this->addFlash('error', 'Der Zahlungsbetrag muss größer als 0 sein.');
                return $this->redirectToRoute('admin_catering_process_payment', ['userId' => $userId]);
            }
            
            try {
                // Process payment
                $result = $this->cateringService->processPayment($user, $amount, $note);
                
                $this->addFlash('success', sprintf(
                    'Zahlung über %.2f € wurde verarbeitet. %d Bestellung(en) wurden bezahlt und %.2f € wurden dem Guthaben gutgeschrieben.',
                    $amount / 100,
                    $result['orders_processed'],
                    $result['amount_credited'] / 100
                ));
                
                return $this->redirectToRoute('admin_catering_payments');
            } catch (\Exception $e) {
                $this->addFlash('error', 'Fehler bei der Verarbeitung der Zahlung: ' . $e->getMessage());
            }
        }
        
        return $this->render('admin/catering/process_payment.html.twig', [
            'user' => $user,
            'payment_sent_orders' => $paymentSentOrders,
            'open_orders' => $openOrders,
            'total_payment_sent' => $totalPaymentSent,
            'total_open_orders' => $totalOpenOrders,
            'current_credit' => $currentCredit,
            'csrf_token' => self::CSRF_TOKEN_PAYED
        ]);
    }
    
    #[Route(path: '/credit-management', name:'_credit_management', methods: ['GET'])]
    public function creditManagement(): Response
    {
        // Get all users with credit
        $usersWithCredit = $this->em->getRepository(\App\Entity\UserCateringCredit::class)->findAll();
        
        // Get all users who have placed catering orders
        $qb = $this->orderRepository->createQueryBuilder('o')
            ->select('DISTINCT o.orderer')
            ->getQuery();
        
        $orderUserIds = $qb->getResult();
        
        // Create a map of user UUIDs to credit amounts
        $creditMap = [];
        foreach ($usersWithCredit as $credit) {
            $creditMap[$credit->getUser()->toString()] = $credit->getAmount();
        }
        
        // Format user data for all users
        $userData = [];
        
        // First add users with credit
        foreach ($usersWithCredit as $credit) {
            $user = $this->userRepo->findOneById($credit->getUser());
            if ($user) {
                $userData[] = [
                    'user' => $user,
                    'credit' => $credit->getAmount(),
                    'has_ordered' => true,
                ];
            }
        }
        
        // Then add users who placed orders but don't have credit yet
        foreach ($orderUserIds as $uuidArray) {
            $uuid = $uuidArray['orderer'];
            $uuidString = $uuid->toString();
            
            // Skip users already in the list (with credit)
            if (isset($creditMap[$uuidString])) {
                continue;
            }
            
            $user = $this->userRepo->findOneById($uuid);
            if ($user) {
                $userData[] = [
                    'user' => $user,
                    'credit' => 0,
                    'has_ordered' => true,
                ];
            }
        }
        
        // Sort by credit amount (highest first)
        usort($userData, function($a, $b) {
            return $b['credit'] - $a['credit'];
        });
        
        return $this->render('admin/catering/credit_management.html.twig', [
            'users_with_credit' => $userData,
        ]);
    }
    
    #[Route(path: '/adjust-credit/{userId}', name:'_adjust_credit', methods: ['GET', 'POST'])]
    public function adjustCredit(Request $request, string $userId): Response
    {
        $user = $this->userRepo->findOneById(Uuid::fromString($userId));
        
        if (!$user) {
            $this->addFlash('error', 'Benutzer nicht gefunden.');
            return $this->redirectToRoute('admin_catering_credit_management');
        }
        
        // Get current credit balance
        $currentCredit = $this->cateringService->getUserCredit($user);
        
        // Get transaction history
        $transactions = $this->cateringService->getUserTransactionHistory($user);
        
        if ($request->isMethod('POST')) {
            $token = $request->request->get('_token');
            if (!$this->isCsrfTokenValid(self::CSRF_TOKEN_PAYED, $token)) {
                throw $this->createAccessDeniedException('Invalid CSRF token presented');
            }
            
            $adjustmentAmount = (int)($request->request->get('adjustment_amount') * 100); // Convert to cents
            $note = $request->request->get('adjustment_note') ?: 'Manuelle Guthabenanpassung';
            
            if ($adjustmentAmount == 0) {
                $this->addFlash('error', 'Der Anpassungsbetrag darf nicht 0 sein.');
                return $this->redirectToRoute('admin_catering_adjust_credit', ['userId' => $userId]);
            }
            
            try {
                if ($adjustmentAmount > 0) {
                    // Add credit
                    $this->cateringService->addUserCredit($user, $adjustmentAmount, $note);
                    $this->addFlash('success', sprintf('%.2f € wurden dem Guthaben hinzugefügt.', $adjustmentAmount / 100));
                } else {
                    // Deduct credit (always succeeds as negative balances are allowed)
                    $this->cateringService->deductUserCredit($user, abs($adjustmentAmount), null, $note);
                    $this->addFlash('success', sprintf('%.2f € wurden vom Guthaben abgezogen.', abs($adjustmentAmount) / 100));
                }
                
                // Apply credit to open orders if requested
                if ($request->request->get('apply_to_orders')) {
                    $result = $this->cateringService->applyUserCreditToOrders($user);
                    if ($result['orders_processed'] > 0) {
                        $this->addFlash('success', sprintf(
                            '%d Bestellung(en) im Wert von %.2f € wurden mit dem Guthaben bezahlt.',
                            $result['orders_processed'],
                            $result['amount_used'] / 100
                        ));
                    }
                }
                
                return $this->redirectToRoute('admin_catering_credit_management');
            } catch (\Exception $e) {
                $this->addFlash('error', 'Fehler bei der Anpassung des Guthabens: ' . $e->getMessage());
            }
        }
        
        return $this->render('admin/catering/adjust_credit.html.twig', [
            'user' => $user,
            'current_credit' => $currentCredit,
            'transactions' => $transactions,
            'csrf_token' => self::CSRF_TOKEN_PAYED
        ]);
    }

    #[Route(path: '/financial/{userId}', name:'_financial', methods: ['GET', 'POST'])]
    public function userFinancial(Request $request, string $userId): Response
    {
        $user = $this->userRepo->findOneById(Uuid::fromString($userId));
        
        if (!$user) {
            $this->addFlash('error', 'Benutzer nicht gefunden.');
            return $this->redirectToRoute('admin_catering_payments');
        }
        
        // Get user's payment sent orders
        $paymentSentOrders = $this->orderRepository->findBy([
            'orderer' => $user->getUuid(),
            'status' => \App\Entity\CateringOrderStatus::PaymentSent
        ], ['createdAt' => 'ASC']);
        
        // Also get any open orders
        $openOrders = $this->orderRepository->findBy([
            'orderer' => $user->getUuid(),
            'status' => \App\Entity\CateringOrderStatus::Created
        ], ['createdAt' => 'ASC']);
        
        // Calculate totals
        $totalPaymentSent = 0;
        foreach ($paymentSentOrders as $order) {
            $totalPaymentSent += $order->calculateTotal();
        }
        
        $totalOpenOrders = 0;
        foreach ($openOrders as $order) {
            $totalOpenOrders += $order->calculateTotal();
        }
        
        // Get current credit balance and transaction history
        $currentCredit = $this->cateringService->getUserCredit($user);
        $transactions = $this->cateringService->getUserTransactionHistory($user);
        
        if ($request->isMethod('POST')) {
            $token = $request->request->get('_token');
            if (!$this->isCsrfTokenValid(self::CSRF_TOKEN_PAYED, $token)) {
                throw $this->createAccessDeniedException('Invalid CSRF token presented');
            }
            
            $transactionType = $request->request->get('transaction_type');
            $amount = (int)($request->request->get('amount') * 100); // Convert to cents
            $note = $request->request->get('note');
            $autoApply = $request->request->has('auto_apply');
            
            if ($amount <= 0) {
                $this->addFlash('error', 'Der Betrag muss größer als 0 sein.');
                return $this->redirectToRoute('admin_catering_financial', ['userId' => $userId]);
            }
            
            try {
                switch ($transactionType) {
                    case 'payment':
                        // Process payment
                        $result = $this->cateringService->processPayment($user, $amount, $note);
                        
                        $this->addFlash('success', sprintf(
                            'Zahlung über %.2f € wurde verarbeitet. %d Bestellung(en) wurden bezahlt und %.2f € wurden dem Guthaben gutgeschrieben.',
                            $amount / 100,
                            $result['orders_processed'],
                            $result['amount_credited'] / 100
                        ));
                        break;
                        
                    case 'credit_deduct':
                        // Deduct credit - pass null as order and the note as fourth parameter
                        $this->cateringService->deductUserCredit($user, $amount, null, $note ?: 'Manuelle Guthabenanpassung');
                        $this->addFlash('success', sprintf('%.2f € wurden vom Guthaben abgezogen.', $amount / 100));
                        break;
                        
                    default:
                        $this->addFlash('error', 'Ungültiger Transaktionstyp.');
                        break;
                }
                
                // Redirect to the same page to see updates
                return $this->redirectToRoute('admin_catering_financial', ['userId' => $userId]);
                
            } catch (\Exception $e) {
                $this->addFlash('error', 'Fehler bei der Verarbeitung: ' . $e->getMessage());
            }
        }
        
        return $this->render('admin/catering/user_financial.html.twig', [
            'user' => $user,
            'payment_sent_orders' => $paymentSentOrders,
            'open_orders' => $openOrders,
            'total_payment_sent' => $totalPaymentSent,
            'total_open_orders' => $totalOpenOrders,
            'current_credit' => $currentCredit,
            'transactions' => $transactions,
            'csrf_token' => self::CSRF_TOKEN_PAYED
        ]);
    }

    /**
     * Helper method to get form errors as an array for debugging
     */
    private function getFormErrors($form): array
    {
        $errors = [];
        foreach ($form->getErrors(true) as $error) {
            $errors[] = $error->getMessage();
        }
        return $errors;
    }

    /**
     * Search for users to add credit
     */
    #[Route(path: '/search-users', name:'_search_users', methods: ['GET'])]
    public function searchUsers(Request $request): Response
    {
        $query = $request->query->get('q');
        
        // Return empty array for empty or too short queries
        if (!$query || strlen($query) < 2) {
            return $this->json([]);
        }
        
        // Search for users using IdmRepository's findFuzzy method
        $usersCollection = $this->userRepo->findFuzzy($query);
        
        // Convert to array and limit results
        $users = [];
        $count = 0;
        foreach ($usersCollection as $user) {
            if ($count >= 10) break; // Limit to 10 results
            $users[] = $user;
            $count++;
        }
        
        // Format data for JSON response
        $result = [];
        foreach ($users as $user) {
            $result[] = [
                'uuid' => $user->getUuid()->toString(),
                'nickname' => $user->getNickname(),
                'email' => $user->getEmail(),
            ];
        }
        
        return $this->json($result);
    }
}
