<?php

namespace App\Controller\Admin;

use App\Service\PizzaService;
use Sensio\Bundle\FrameworkExtraBundle\Configuration\IsGranted;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;

#[IsGranted('ROLE_ADMIN_CATERING_PIZZA')]
#[Route(path: '/catering/pizza', name: 'catering_pizza')]
class CateringPizzaController extends AbstractController
{
    private readonly PizzaService $pizzaService;

    public function __construct(PizzaService $pizzaService)
    {
        $this->pizzaService = $pizzaService;
    }

    #[Route(path: '', name: '', methods: ['GET'])]
    public function pizzaOrders(): Response
    {
        $overview = $this->pizzaService->getOrderOverview();

        return $this->render('admin/catering/pizza.html.twig', [
            'items' => $overview['items'],
            'total' => $overview['total'],
            'deadline' => $this->pizzaService->getDeadline(),
            'ordering_open' => $this->pizzaService->isOrderingOpen(),
        ]);
    }
}
