<?php

namespace App\Controller\Admin;

use App\Entity\IncomingPayment;
use App\Service\IncomingPaymentService;
use App\Service\PaymentMatchingService;
use App\Service\PaymentProcessingService;
use App\Service\PayPalImportService;
use App\Service\UserService;
use App\Service\TicketService;
use Doctrine\ORM\EntityManagerInterface;
use Sensio\Bundle\FrameworkExtraBundle\Configuration\IsGranted;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Form\Extension\Core\Type\FileType;
use Symfony\Component\Form\Extension\Core\Type\MoneyType;
use Symfony\Component\Form\Extension\Core\Type\SubmitType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\Validator\Constraints\File;
use Symfony\Component\Validator\Constraints\GreaterThan;

#[IsGranted('ROLE_ADMIN_PAYMENT')]
#[Route(path: '/incoming-payment', name: 'incoming_payment')]
class IncomingPaymentController extends AbstractController
{
    private readonly IncomingPaymentService $incomingPaymentService;
    private readonly PaymentMatchingService $paymentMatchingService;
    private readonly PaymentProcessingService $paymentProcessingService;
    private readonly PayPalImportService $payPalImportService;
    private readonly UserService $userService;
    private readonly TicketService $ticketService;
    private readonly EntityManagerInterface $em;

    public function __construct(
        IncomingPaymentService $incomingPaymentService,
        PaymentMatchingService $paymentMatchingService,
        PaymentProcessingService $paymentProcessingService,
        PayPalImportService $payPalImportService,
        UserService $userService,
        TicketService $ticketService,
        EntityManagerInterface $em
    ) {
        $this->incomingPaymentService = $incomingPaymentService;
        $this->paymentMatchingService = $paymentMatchingService;
        $this->paymentProcessingService = $paymentProcessingService;
        $this->payPalImportService = $payPalImportService;
        $this->userService = $userService;
        $this->ticketService = $ticketService;
        $this->em = $em;
    }

    #[Route(path: '', name: '', methods: ['GET'])]
    public function index(Request $request): Response
    {
        $tab = $request->query->get('tab', 'pending');
        
        $payments = match($tab) {
            'pending' => $this->incomingPaymentService->getPendingPayments(),
            'unmatched' => $this->incomingPaymentService->getUnmatchedPayments(),
            'all' => $this->incomingPaymentService->getAllPayments(),
            default => $this->incomingPaymentService->getPendingPayments(),
        };

        // Get unique user UUIDs that need to be loaded
        $userUuids = [];
        foreach ($payments as $payment) {
            if ($payment->getMatchedUser()) {
                $userUuids[] = $payment->getMatchedUser();
            }
        }
        
        // Load all users at once
        $users = [];
        if (!empty($userUuids)) {
            $users = $this->userService->getUsers($userUuids, true); // true = associative array
        }
        
        // Load user objects for matched payments
        $paymentData = [];
        foreach ($payments as $payment) {
            $user = null;
            if ($payment->getMatchedUser()) {
                $user = $users[$payment->getMatchedUser()->toString()] ?? null;
            }
            $paymentData[] = [
                'payment' => $payment,
                'user' => $user
            ];
        }

        $stats = [
            'pending' => count($this->incomingPaymentService->getPendingPayments()),
            'unmatched' => count($this->incomingPaymentService->getUnmatchedPayments()),
            'processed' => count($this->incomingPaymentService->getPaymentsByStatus('processed')),
            'ignored' => count($this->incomingPaymentService->getPaymentsByStatus('ignored')),
        ];

        return $this->render('admin/incoming_payment/index.html.twig', [
            'paymentData' => $paymentData,
            'stats' => $stats,
            'currentTab' => $tab,
        ]);
    }

    #[Route(path: '/import', name: '_import', methods: ['GET', 'POST'])]
    public function import(Request $request): Response
    {
        $form = $this->createFormBuilder()
            ->add('source', ChoiceType::class, [
                'choices' => [
                    'PayPal' => 'paypal',
                    'Bank Transfer' => 'bank_transfer',
                    'Cash' => 'cash',
                    'Manual' => 'manual',
                ],
                'constraints' => [new NotBlank()],
            ])
            ->add('file', FileType::class, [
                'required' => false,
                'constraints' => [
                    new File([
                        'maxSize' => '10m',
                        'mimeTypes' => [
                            'text/csv',
                            'text/plain',
                            'application/csv',
                        ],
                    ])
                ],
            ])
            ->add('amount', MoneyType::class, [
                'currency' => 'EUR',
                'required' => false,
                'constraints' => [new GreaterThan(0)],
            ])
            ->add('description', TextType::class, [
                'required' => false,
            ])
            ->add('reference', TextType::class, [
                'required' => false,
            ])
            ->add('submit', SubmitType::class, ['label' => 'Importieren'])
            ->getForm();

        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $data = $form->getData();
            
            try {
                if ($data['file']) {
                    // Import from file
                    $imported = $this->payPalImportService->importFromFile($data['file']->getPathname(), $data['source']);
                    $this->addFlash('success', sprintf('%d Zahlungen importiert.', count($imported)));
                } else {
                    // Manual entry
                    $payment = $this->incomingPaymentService->createPayment(
                        amount: $data['amount'],
                        source: $data['source'],
                        description: $data['description'] ?? '',
                        reference: $data['reference'] ?? '',
                        metadata: []
                    );
                    $this->addFlash('success', 'Zahlung manuell erfasst.');
                }
                
                return $this->redirectToRoute('admin_incoming_payment');
            } catch (\Exception $e) {
                $this->addFlash('error', 'Fehler beim Import: ' . $e->getMessage());
            }
        }

        return $this->render('admin/incoming_payment/import.html.twig', [
            'form' => $form->createView(),
        ]);
    }

    #[Route(path: '/{id}', name: '_show', methods: ['GET'])]
    public function show(IncomingPayment $payment): Response
    {
        $suggestedMatches = $this->paymentMatchingService->findPotentialMatches($payment);
        
        // Load the matched user if exists
        $matchedUser = null;
        if ($payment->getMatchedUser()) {
            $users = $this->userService->getUsers([$payment->getMatchedUser()]);
            $matchedUser = $users[0] ?? null;
        }
        
        // Get all users for the "show all users" option
        $allUsers = $this->paymentMatchingService->getAllUsers();
        
        // Check which users have tickets for filtering
        $usersWithTickets = [];
        foreach ($suggestedMatches as $match) {
            $hasTicket = $this->ticketService->isUserRegistered($match['user']->getUuid());
            $usersWithTickets[$match['user']->getUuid()->toString()] = $hasTicket;
        }
        
        // Also check ticket status for all users
        foreach ($allUsers as $user) {
            if (!isset($usersWithTickets[$user->getUuid()->toString()])) {
                $hasTicket = $this->ticketService->isUserRegistered($user->getUuid());
                $usersWithTickets[$user->getUuid()->toString()] = $hasTicket;
            }
        }
        
        return $this->render('admin/incoming_payment/show.html.twig', [
            'payment' => $payment,
            'matchedUser' => $matchedUser,
            'suggestedMatches' => $suggestedMatches,
            'allUsers' => $allUsers,
            'usersWithTickets' => $usersWithTickets,
        ]);
    }

    #[Route(path: '/{id}/process', name: '_process', methods: ['POST'])]
    public function process(IncomingPayment $payment): Response
    {
        try {
            $this->paymentProcessingService->processPayment($payment);
            $this->addFlash('success', 'Zahlung erfolgreich verarbeitet.');
        } catch (\Exception $e) {
            $this->addFlash('error', 'Fehler bei der Verarbeitung: ' . $e->getMessage());
        }
        
        return $this->redirectToRoute('admin_incoming_payment_show', ['id' => $payment->getId()]);
    }

    #[Route(path: '/{id}/ignore', name: '_ignore', methods: ['POST'])]
    public function ignore(IncomingPayment $payment): Response
    {
        try {
            $this->incomingPaymentService->ignorePayment($payment);
            $this->addFlash('success', 'Zahlung ignoriert.');
        } catch (\Exception $e) {
            $this->addFlash('error', 'Fehler: ' . $e->getMessage());
        }
        
        return $this->redirectToRoute('admin_incoming_payment_show', ['id' => $payment->getId()]);
    }

    #[Route(path: '/{id}/auto-match', name: '_auto_match', methods: ['POST'])]
    public function autoMatch(IncomingPayment $payment): Response
    {
        try {
            $matched = $this->paymentMatchingService->autoMatchPayment($payment);
            
            if ($matched) {
                $this->addFlash('success', 'Zahlung automatisch zugeordnet.');
            } else {
                $this->addFlash('warning', 'Keine automatische Zuordnung möglich.');
            }
        } catch (\Exception $e) {
            $this->addFlash('error', 'Fehler bei der automatischen Zuordnung: ' . $e->getMessage());
        }
        
        return $this->redirectToRoute('admin_incoming_payment_show', ['id' => $payment->getId()]);
    }

    #[Route(path: '/{id}/assign-user', name: '_assign_user', methods: ['POST'])]
    public function assignUser(IncomingPayment $payment, Request $request): Response
    {
        $userId = $request->request->get('user');
        $confidence = (float) $request->request->get('confidence', 0.5);

        if (!$userId) {
            $this->addFlash('error', 'Kein Benutzer ausgewählt.');
            return $this->redirectToRoute('admin_incoming_payment_show', ['id' => $payment->getId()]);
        }

        try {
            $user = $this->userService->getUserById($userId);
            if (!$user) {
                $this->addFlash('error', 'Benutzer nicht gefunden.');
                return $this->redirectToRoute('admin_incoming_payment_show', ['id' => $payment->getId()]);
            }

            $this->paymentMatchingService->matchPaymentToUser(
                $payment,
                $user,
                $confidence,
                true // isManual
            );

            $this->addFlash('success', 'Zahlung erfolgreich zugeordnet.');
        } catch (\Exception $e) {
            $this->addFlash('error', 'Fehler bei der Zuordnung: ' . $e->getMessage());
        }

        return $this->redirectToRoute('admin_incoming_payment_show', ['id' => $payment->getId()]);
    }
}
