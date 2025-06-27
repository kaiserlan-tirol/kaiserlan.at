<?php

namespace App\Controller\Site;

use App\Entity\CateringOrder;
use App\Entity\User;
use App\Exception\OrderLifecycleException;
use App\Form\CateringCheckoutType;
use App\Service\CateringService;
use App\Service\SettingService;
use Psr\Log\LoggerInterface;
use Sensio\Bundle\FrameworkExtraBundle\Configuration\IsGranted;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;

#[Route(path: '/catering', name: 'catering')]
class CateringController extends AbstractController
{
    private readonly CateringService $cateringService;
    private readonly SettingService $settingService;
    private readonly LoggerInterface $logger;

    public function __construct(
        CateringService $cateringService,
        SettingService $settingService,
        LoggerInterface $logger
    ) {
        $this->cateringService = $cateringService;
        $this->settingService = $settingService;
        $this->logger = $logger;
    }

    private const CSRF_TOKEN_CANCEL = 'cancelCateringOrder';
    private const CSRF_TOKEN_PAY = 'payCateringOrders';

    #[IsGranted('IS_AUTHENTICATED_REMEMBERED')]
    #[Route(path: '/order', name: '_order')]
    public function order(Request $request): Response
    {
        if (!$this->settingService->get('catering.enabled', false)) {
            $this->addFlash('warning', "Catering ist noch nicht verfügbar.");
            return $this->redirect('/');
        }

        /** @var User $user */
        $user = $this->getUser()->getUser();
        $products = $this->cateringService->getPaidProducts($user);
        $userHasFlatrate = $this->cateringService->userHasFlatrate($user);

        $form = $this->createForm(CateringCheckoutType::class, null, [
            'products' => $products,
        ]);

        $form->handleRequest($request);
        if ($form->isSubmitted() && $form->isValid()) {
            $data = $form->getData();

            $order = $this->cateringService->allocOrder($user);
            $hasItems = false;

            // add products to order
            foreach ($products as $product) {
                $cnt = $data['product' . $product->getId()] ?? 0;
                if ($cnt > 0) {
                    $this->cateringService->orderAddProduct($order, $product, $cnt);
                    $hasItems = true;
                }
            }

            if ($hasItems) {
                try {
                    $this->cateringService->placeOrder($order);
                    $this->addFlash('success', "Bestellung erfolgreich angelegt.");
                    return $this->redirectToRoute('catering_orders');
                } catch (OrderLifecycleException $e) {
                    $this->addFlash('error', "Bestellung konnte nicht angelegt werden.");
                }
            } else {
                $this->addFlash('warning', "Leere Bestellung kann nicht angelegt werden.");
            }
        }

        // show order dialog
        return $this->render('site/catering/order.html.twig', [
            'form' => $form->createView(),
            'products' => $products,
            'userHasFlatrate' => $userHasFlatrate,
        ]);
    }

    #[IsGranted('IS_AUTHENTICATED_REMEMBERED')]
    #[Route(path: '/orders', name: '_orders', methods: ['GET', 'POST'])]
    public function orders(Request $request): Response
    {
        /** @var User $user */
        $user = $this->getUser()->getUser();
        $orders = $this->cateringService->getOrderByUser($user);

        if ($request->getMethod() == 'POST') {
            $token = $request->request->get('_token');
            $action = $request->request->get('action');
            $id = $request->request->get('order-id');

            if (!$this->isCsrfTokenValid(self::CSRF_TOKEN_CANCEL, $token)) {
                throw $this->createAccessDeniedException('Invalid CSRF token presented');
            }
            // check if the order is by the user
            $order = array_filter($orders, function (CateringOrder $order) use ($id) { 
                return $order->getId() == $id; 
            });
            if (empty($order)) {
                throw $this->createAccessDeniedException('Invalid order specified.');
            }
            $order = array_pop($order);
            try {
                switch ($action) {
                    default:
                        $this->addFlash('error', "Invalid action specified.");
                        return $this->redirectToRoute('catering_orders');
                }
                // Success message would go here
            } catch (OrderLifecycleException $e) {
                $this->addFlash('error', "Bestellung #{$order->getId()} konnte nicht geändert werden.");
            }
        }

        // Calculate total sum of open orders
        $totalOpenOrders = 0;
        $hasOpenOrders = false;
        foreach ($orders as $order) {
            if ($order->isOpen()) {
                $totalOpenOrders += $order->calculateTotal();
                $hasOpenOrders = true;
            }
        }
        
        // Get user's credit balance
        $creditBalance = $this->cateringService->getUserCredit($user);
        
        // show orders with option to pay
        return $this->render('site/catering/orders.html.twig', [
            'orders' => $orders,
            'csrf_token_cancel' => self::CSRF_TOKEN_CANCEL,
            'csrf_token_pay' => self::CSRF_TOKEN_PAY,
            'total_open_orders' => $totalOpenOrders,
            'has_open_orders' => $hasOpenOrders,
            'credit_balance' => $creditBalance,
        ]);
    }

    #[Route(path: '/menu', name: '_menu')]
    public function menu(): Response
    {
        // This route is public, check if user is logged in
        $user = $this->getUser() ? $this->getUser()->getUser() : null;
        $isLoggedIn = ($user !== null);
        
        $products = $this->cateringService->getProducts();
        
        // Handle both logged-in and anonymous users
        if ($isLoggedIn) {
            $flatProducts = $this->cateringService->getFlatrateProducts($user);
            $paidProducts = $this->cateringService->getPaidProducts($user);
            $userHasFlatrate = $this->cateringService->userHasFlatrate($user);
        } else {
            // For anonymous users, we only need to show all products
            $flatProducts = [];
            $paidProducts = $products;
            $userHasFlatrate = false;
        }

        return $this->render('site/catering/menu.html.twig', [
            'products' => $products,
            'flatProducts' => $flatProducts,
            'paidProducts' => $paidProducts,
            'userHasFlatrate' => $userHasFlatrate,
            'isLoggedIn' => $isLoggedIn,
        ]);
    }

    #[IsGranted('IS_AUTHENTICATED_REMEMBERED')]
    #[Route(path: '/pay', name: '_pay', methods: ['POST'])]
    public function pay(Request $request): Response
    {
        $token = $request->request->get('_token');
        if (!$this->isCsrfTokenValid(self::CSRF_TOKEN_PAY, $token)) {
            throw $this->createAccessDeniedException('Invalid CSRF token presented');
        }
        
        /** @var User $user */
        $user = $this->getUser()->getUser();
        $orders = $this->cateringService->getOrderByUser($user);
        
        // Only process open orders
        $openOrders = array_filter($orders, function(CateringOrder $order) {
            return $order->isOpen();
        });
        
        if (count($openOrders) === 0) {
            $this->addFlash('info', "Keine offenen Bestellungen vorhanden.");
            return $this->redirectToRoute('catering_orders');
        }
        
        // Calculate total amount
        $totalAmount = 0;
        foreach ($openOrders as $order) {
            $totalAmount += $order->calculateTotal();
        }
        
        // Mark all open orders as paid
        try {
            foreach ($openOrders as $order) {
                $this->cateringService->setOrderPaid($order);
                $this->logger->info('Order marked as paid', [
                    'order_id' => $order->getId(),
                    'user_id' => $user->getUuid()->toString(),
                    'amount' => $order->calculateTotal(),
                ]);
            }
            
            $formattedAmount = number_format($totalAmount / 100, 2, ',', '.') . ' €';
            $this->addFlash('success', count($openOrders) . " Bestellung(en) im Wert von {$formattedAmount} wurden als bezahlt markiert.");
        } catch (\Exception $e) {
            $this->logger->error('Error marking orders as paid', [
                'error' => $e->getMessage(),
                'user_id' => $user->getUuid()->toString(),
            ]);
            $this->addFlash('error', "Fehler beim Bezahlen der Bestellungen: " . $e->getMessage());
        }
        
        return $this->redirectToRoute('catering_orders');
    }
    
    #[IsGranted('IS_AUTHENTICATED_REMEMBERED')]
    #[Route(path: '/payment-sent', name: '_payment_sent', methods: ['POST'])]
    public function markPaymentSent(Request $request): Response
    {
        $token = $request->request->get('_token');
        if (!$this->isCsrfTokenValid(self::CSRF_TOKEN_PAY, $token)) {
            throw $this->createAccessDeniedException('Invalid CSRF token presented');
        }
        
        /** @var User $user */
        $user = $this->getUser()->getUser();
        $orders = $this->cateringService->getOrderByUser($user);
        
        // Only process open orders
        $openOrders = array_filter($orders, function(CateringOrder $order) {
            return $order->isOpen();
        });
        
        if (count($openOrders) === 0) {
            $this->addFlash('info', "Keine offenen Bestellungen vorhanden.");
            return $this->redirectToRoute('catering_orders');
        }
        
        // Calculate total amount
        $totalAmount = 0;
        foreach ($openOrders as $order) {
            $totalAmount += $order->calculateTotal();
        }
        
        // Mark all open orders as payment sent
        try {
            $this->cateringService->markOrdersAsPaymentSent($openOrders);
            
            $formattedAmount = number_format($totalAmount / 100, 2, ',', '.') . ' €';
            $this->addFlash('success', count($openOrders) . " Bestellung(en) im Wert von {$formattedAmount} wurden als \"Zahlung gesendet\" markiert.");
            $this->logger->info('Orders marked as payment sent', [
                'user_id' => $user->getUuid()->toString(),
                'order_count' => count($openOrders),
                'total_amount' => $totalAmount,
            ]);
        } catch (\Exception $e) {
            $this->logger->error('Error marking orders as payment sent', [
                'error' => $e->getMessage(),
                'user_id' => $user->getUuid()->toString(),
            ]);
            $this->addFlash('error', "Fehler beim Markieren der Bestellungen: " . $e->getMessage());
        }
        
        return $this->redirectToRoute('catering_orders');
    }
    
    #[IsGranted('IS_AUTHENTICATED_REMEMBERED')]
    #[Route(path: '/credit', name: '_credit')]
    public function userCredit(): Response
    {
        /** @var User $user */
        $user = $this->getUser()->getUser();
        
        // Get user credit balance
        $creditBalance = $this->cateringService->getUserCredit($user);
        
        // Get transaction history
        $transactions = $this->cateringService->getUserTransactionHistory($user);
        
        return $this->render('site/catering/credit.html.twig', [
            'credit_balance' => $creditBalance,
            'transactions' => $transactions,
        ]);
    }
}
