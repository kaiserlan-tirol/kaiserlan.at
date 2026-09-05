<?php

namespace App\Controller\Admin;

use App\Entity\User;
use App\Idm\IdmManager;
use App\Idm\IdmRepository;
use App\Service\PizzaService;
use Sensio\Bundle\FrameworkExtraBundle\Configuration\IsGranted;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Ramsey\Uuid\Uuid;
use Symfony\Component\Routing\Annotation\Route;

#[IsGranted('ROLE_ADMIN_CATERING_PIZZA')]
#[Route(path: '/catering/pizza', name: 'catering_pizza')]
class CateringPizzaController extends AbstractController
{
    private readonly PizzaService $pizzaService;
    private readonly IdmRepository $userRepo;

    public function __construct(PizzaService $pizzaService, IdmManager $idmManager)
    {
        $this->pizzaService = $pizzaService;
        $this->userRepo = $idmManager->getRepository(User::class);
    }

    #[Route(path: '', name: '', methods: ['GET'])]
    public function pizzaOrders(): Response
    {
        $overview = $this->pizzaService->getOrderOverview();

        // resolve all orderers with a single bulk request. Looking them up from the template instead
        // would cost one request per orderer, and one per row for uuids the idm does not know.
        $uuids = [];
        foreach ($overview['items'] as $item) {
            foreach (array_keys($item['orderers']) as $uuid) {
                $uuids[$uuid] = Uuid::fromString($uuid);
            }
        }
        $orderers = [];
        foreach ($this->userRepo->findById(array_values($uuids)) as $user) {
            $orderers[$user->getUuid()->toString()] = $user->getNickname();
        }

        return $this->render('admin/catering/pizza.html.twig', [
            'items' => $overview['items'],
            'orderers' => $orderers,
            'total' => $overview['total'],
            'deadline' => $this->pizzaService->getDeadline(),
            'ordering_open' => $this->pizzaService->isOrderingOpen(),
        ]);
    }
}
