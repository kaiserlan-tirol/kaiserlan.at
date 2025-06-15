<?php

namespace App\Controller\Admin;

use App\Entity\ShopAddon;
use App\Entity\Ticket;
use App\Exception\TicketLivecycleException;
use App\Form\UserSelectType;
use App\Service\ShopService;
use App\Service\TicketService;
use App\Service\TicketState;
use App\Service\UserService;
use Ramsey\Uuid\UuidInterface;

use Sensio\Bundle\FrameworkExtraBundle\Configuration\IsGranted;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Form\Extension\Core\Type\HiddenType;
use Symfony\Component\Form\Extension\Core\Type\SubmitType;
use Symfony\Component\Form\FormInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;

#[IsGranted('ROLE_ADMIN_PAYMENT')]
#[Route(path: '/payment', name: 'payment')]
class PaymentController extends AbstractController
{
    private readonly TicketService $ticketService;
    private readonly UserService $userService;
    private readonly ShopService $shopService;

    public function __construct(TicketService $ticketService,
                                UserService   $userService,
                                ShopService   $shopService)
    {
        $this->ticketService = $ticketService;
        $this->userService = $userService;
        $this->shopService = $shopService;
        // $this->userRepo = $manager->getRepository(User::class);
    }

    private function createTicketCreateForm(string $action = "", bool $forceUser = false): FormInterface
    {
        $form = $this->createFormBuilder();
        $form->add('action', HiddenType::class, ['data' => $action]);
        $form->add('user', UserSelectType::class, ['required' => $forceUser]);
        return $form->getForm();
    }

    private function createTicketModificationForm(Ticket $ticket): FormInterface
    {
        $form = $this->createFormBuilder()
            ->setAction($this->generateUrl('admin_payment_update', ['id' => $ticket->getId()]));
        $can_delete_ticket = empty($ticket->getShopOrderPosition());
        switch ($ticket->getState()) {
            case TicketState::NEW:
                $form->add('user', UserSelectType::class, ['required' => false]);
                $form->add('assign', SubmitType::class);
                if ($can_delete_ticket) $form->add('delete', SubmitType::class);
                break;
            case TicketState::REDEEMED:
                $form->add('unassign', SubmitType::class);
                $form->add('punch', SubmitType::class);
                if ($can_delete_ticket) $form->add('delete', SubmitType::class);
                break;
            case TicketState::PUNCHED:
                $form->add('unpunch', SubmitType::class);
                $form->add('unassign', SubmitType::class);
                if ($can_delete_ticket) $form->add('delete', SubmitType::class);
                break;
        }
        return $form->getForm();
    }

    #[Route(path: '', name: '', methods: ['GET'])]
    public function index(Request $request): Response
    {
        $addonFilter = $request->query->get('addon');
        $addonFilterId = $addonFilter ? (int)$addonFilter : null;
        
        $tickets = $this->ticketService->queryTickets(addonFilter: $addonFilterId);
        
        $uuids = array_map(fn (Ticket $t) => $t->getRedeemer(), $tickets);
        $uuids = array_filter($uuids, fn (?UuidInterface $uuid) => !empty($uuid));
        $users = $this->userService->getUsers($uuids, assoc: true);
        
        // Get all available addons for the filter dropdown
        $addons = $this->shopService->getAddons(all: true);
/*
        $gamers = $this->gamerService->getGamers();
        $printDogTags = intval($request->query->get('dogtags')) === 1;
        if ($printDogTags) {

            $dogtagGamers = array_map(fn ($g) => [
              'id' => $g['user']->getId(),
              'uuid' => $g['user']->getUuid()->toString(),
              'paid' => $g['status']->hasPaid(),
              'registered' => $g['status']->getRegistered(),
              'nickname' => $g['user']->getNickname(),
            ], $gamers);

            usort($dogtagGamers, function ($a, $b) {
              return $b['registered'] <=> $a['registered'];
            });

            $dogtagGamers = array_reverse($dogtagGamers);

            return $this->render('admin/payment/dogtags.html.twig', [
                'gamers' => $dogtagGamers,
            ]);
        }
*/

        return $this->render('admin/payment/index.html.twig', [
            'tickets' => $tickets,
            'users' => $users,
            'addons' => $addons,
            'selectedAddon' => $addonFilterId,
            'form_add' => $this->createTicketCreateForm("add", true)->createView(),
            'form_new' => $this->createTicketCreateForm("new", false)->createView(),
        ]);
    }

    #[Route(path: '', name: '_add', methods: ['POST'])]
    public function add(Request $request): Response
    {
        $form = $this->createTicketCreateForm();
        $form->handleRequest($request);
        if ($form->isSubmitted() && $form->isValid()) {
            switch ($form->getData()['action']) {
                case 'add':
                    $user = $form->getData()['user'];
                    if (empty($user)) {
                        $this->addFlash('error', 'Ungültigen User ausgewählt.');
                    } elseif ($this->ticketService->isUserRegistered($user)) {
                        $this->addFlash('warning', "User {$user->getNickname()} ist schon registriert.");
                    } else {
                        try {
                            $ticket = $this->ticketService->registerUser($user);
                            $this->addFlash('success', "User {$user->getNickname()} wurde zur Veranstaltung mit Ticket {$ticket->getCode()} registriert.");
                        } catch (TicketLivecycleException) {
                            $this->addFlash('error', "User {$user->getNickname()}  konnte nicht registriert werden.");
                        }
                    }
                    break;
                case 'new':
                    try {
                        $ticket = $this->ticketService->createTicket();
                        $this->addFlash('success', "Ticket {$ticket->getCode()} wurde angelegt.");
                    } catch (TicketLivecycleException) {
                        $this->addFlash('error', "Ticket konnte nicht angelegt werden.");
                    }
                    break;
                default:
                    $this->addFlash('error', "Ungültige Aktion.");
                    break;
            }
        }
        return $this->redirectToRoute('admin_payment');
    }

    private static function clickedIfExists(FormInterface $form, string $field): bool
    {
        return $form->has($field) ? $form->get($field)->isClicked() : false;
    }

    #[Route(path: '/{id}', name: '_update', methods: ['POST'])]
    public function update(Request $request, Ticket $ticket): Response
    {
        $form = $this->createTicketModificationForm($ticket);
        $form->handleRequest($request);
        $id = $ticket->getId();
        $error = "";
        if ($form->isSubmitted() && $form->isValid()) {
            try {
                switch (true) {
                    case self::clickedIfExists($form, 'assign'):
                        $user = $form->get('user')->getData();
                        if (empty($user)) {
                            $error = "Keinen User ausgewählt.";
                        } elseif ($this->ticketService->isUserRegistered($user)) {
                            $error = "User {$user->getNickname()} ist schon registriert.";
                        } else {
                            $this->ticketService->redeemTicket($ticket, $user);
                        }
                        break;
                    case self::clickedIfExists($form, 'unassign'):
                        $this->ticketService->unassignTicket($ticket);
                        break;
                    case self::clickedIfExists($form, 'punch'):
                        $this->ticketService->punchTicket($ticket);
                        break;
                    case self::clickedIfExists($form, 'unpunch'):
                        $this->ticketService->unpunchTicket($ticket);
                        break;
                    case self::clickedIfExists($form, 'delete'):
                        $this->ticketService->deleteTicket($ticket);
                        break;
                        /*
                  case self::clickedIfExists($form, 'pay_toastflat'):
                    $this->ticketService->gamerPayToastflat($user);
                    break;
                  case self::clickedIfExists($form, 'unpay_toastflat'):
                    $this->gamerService->gamerUnPayToastflat($user);
                    break;
                        */
                    default:
                        $this->addFlash('error', "Aktion konnte nicht durchgeführt werden");
                        return $this->redirectToRoute('admin_payment');
                }
            } catch (TicketLivecycleException $exception) {
                $this->addFlash('error', "Aktion konnte nicht durchgeführt werden ({$exception->getMessage()}).");
                return $this->redirectToRoute('admin_payment');
            }
            if (!empty($error)) {
                $this->addFlash('error', $error);
            } else {
                $this->addFlash('success', "Änderung an Ticket #{$id} erfolgreich.");
            }
        }

        return $this->redirectToRoute('admin_payment');
    }

    #[Route(path: '/{id}', name: '_show', methods: ['GET'])]
    public function show(Request $request, Ticket $ticket): Response
    {
        $form = $this->createTicketModificationForm($ticket);
        $user = $this->ticketService->userByTicket($ticket);

        return $this->render('admin/payment/show.html.twig', [
            'user' => $user,
            'ticket' => $ticket,
            'form' => $form->createView(),
        ]);
    }
}
