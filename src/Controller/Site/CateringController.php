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

#[IsGranted('IS_AUTHENTICATED_REMEMBERED')]
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
                    case 'cancel':
                        $this->cateringService->cancelOrder($order);
                        break;
                    default:
                        $this->addFlash('error', "Invalid action specified.");
                        return $this->redirectToRoute('catering_orders');
                }
                $this->addFlash('success', "Bestellung #{$order->getId()} wurde storniert.");
            } catch (OrderLifecycleException $e) {
                $this->addFlash('error', "Bestellung #{$order->getId()} konnte nicht geändert werden.");
            }
        }

        // show orders with option to cancel
        return $this->render('site/catering/orders.html.twig', [
            'orders' => $orders,
            'csrf_token_cancel' => self::CSRF_TOKEN_CANCEL,
        ]);
    }

    #[Route(path: '/menu', name: '_menu')]
    public function menu(): Response
    {
        /** @var User $user */
        $user = $this->getUser()->getUser();
        
        $products = $this->cateringService->getProducts();
        $flatProducts = $this->cateringService->getFlatrateProducts($user);
        $paidProducts = $this->cateringService->getPaidProducts($user);

        return $this->render('site/catering/menu.html.twig', [
            'products' => $products,
            'flatProducts' => $flatProducts,
            'paidProducts' => $paidProducts,
            'userHasFlatrate' => $this->cateringService->userHasFlatrate($user),
        ]);
    }
}
