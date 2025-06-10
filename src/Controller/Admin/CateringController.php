<?php

namespace App\Controller\Admin;

use App\Entity\CateringOrder;
use App\Entity\CateringProduct;
use App\Entity\User;
use App\Exception\OrderLifecycleException;
use App\Form\CateringProductType;
use App\Repository\CateringOrderRepository;
use App\Service\CateringService;
use Sensio\Bundle\FrameworkExtraBundle\Configuration\IsGranted;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\Serializer\SerializerInterface;

#[IsGranted('ROLE_ADMIN_PAYMENT')]
#[Route(path: '/catering', name: 'catering')]
class CateringController extends AbstractController
{
    private readonly CateringService $cateringService;
    private readonly CateringOrderRepository $orderRepository;
    private readonly SerializerInterface $serializer;

    private const CSRF_TOKEN_PAYED = 'cateringToken';

    public function __construct(CateringService $cateringService, CateringOrderRepository $orderRepository, SerializerInterface $serializer)
    {
        $this->cateringService = $cateringService;
        $this->orderRepository = $orderRepository;
        $this->serializer = $serializer;
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
            $this->addFlash('error', "Aktion konnte nicht durchgeführt werden ({$e->getMessage()}).");
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

        if (!$request->isXmlHttpRequest()) {
            throw $this->createNotFoundException();
        }

        return $this->render('admin/catering/show.html.twig', [
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
            $this->cateringService->saveProduct($form->getData());
            $this->addFlash('success', "Produkt wurde erfolgreich angelegt.");
            return $this->redirectToRoute('admin_catering_product');
        }
        
        $template = $request->isXmlHttpRequest() 
            ? 'admin/catering/show_product.modal.html.twig' 
            : 'admin/catering/show_product.html.twig';
            
        return $this->render($template, [
            'product' => $product,
            'form' => $form->createView()
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
        if ($form->isSubmitted() && $form->isValid()) {
            $this->cateringService->saveProduct($form->getData());
            $this->addFlash('success', "Änderung an Produkt {$product->getId()} erfolgreich.");
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
}
