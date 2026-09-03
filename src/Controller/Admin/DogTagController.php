<?php

namespace App\Controller\Admin;

use App\Entity\Setting;
use App\Entity\Ticket;
use App\Entity\User;
use App\Idm\IdmManager;
use App\Idm\IdmRepository;
use App\Repository\SettingRepository;
use App\Repository\TicketRepository;
use App\Service\HelperService;
use App\Service\SettingService;
use Doctrine\ORM\EntityManagerInterface;
use Sensio\Bundle\FrameworkExtraBundle\Configuration\IsGranted;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;

#[IsGranted('ROLE_ADMIN_PAYMENT')]
#[Route(path: '/dogtags', name: 'dogtags')]
class DogTagController extends AbstractController
{
    private readonly IdmManager $idmManager;
    private readonly IdmRepository $userRepo;
    private readonly TicketRepository $ticketRepo;
    private readonly SettingRepository $settingRepo;
    private readonly SettingService $settingService;
    private readonly HelperService $helperService;
    private readonly EntityManagerInterface $em;

    public function __construct(
        IdmManager $idmManager,
        TicketRepository $ticketRepo,
        SettingRepository $settingRepo,
        SettingService $settingService,
        HelperService $helperService,
        EntityManagerInterface $em
    ) {
        $this->idmManager = $idmManager;
        $this->userRepo = $idmManager->getRepository(User::class);
        $this->ticketRepo = $ticketRepo;
        $this->settingRepo = $settingRepo;
        $this->settingService = $settingService;
        $this->helperService = $helperService;
        $this->em = $em;
    }

    #[Route(path: '', name: '_index')]
    public function index(): Response
    {
        // Get all tickets
        $tickets = $this->ticketRepo->findAll();

        // Count unique users with tickets
        $uniqueUuids = [];
        foreach ($tickets as $ticket) {
            $redeemer = $ticket->getRedeemer();
            if ($redeemer) {
                $uniqueUuids[$redeemer->toString()] = $redeemer;
            }
        }

        // Get last export timestamp
        $lastExportSetting = $this->settingRepo->findByKey('dogtag_last_export');
        $lastExportDate = $lastExportSetting ? $lastExportSetting->getLastModified() : null;

        // Count new users since last export
        $newUsersSinceExport = 0;
        if ($lastExportDate) {
            $sinceDateTime = new \DateTimeImmutable($lastExportDate->format('Y-m-d H:i:s'));
            $newUniqueUuids = [];

            foreach ($tickets as $ticket) {
                $redeemedAt = $ticket->getRedeemedAt();
                $redeemer = $ticket->getRedeemer();

                if ($redeemer && $redeemedAt && $redeemedAt >= $sinceDateTime) {
                    $newUniqueUuids[$redeemer->toString()] = $redeemer;
                }
            }

            $newUsersSinceExport = count($newUniqueUuids);
        }

        return $this->render('admin/dogtag/index.html.twig', [
            'lastExportDate' => $lastExportDate,
            'totalUsers' => count($uniqueUuids),
            'newUsersSinceExport' => $newUsersSinceExport,
        ]);
    }


    #[Route(path: '/export-csv', name: '_export_csv')]
    public function exportCsv(Request $request): Response
    {
        $since = $request->query->get('since');

        return $this->generateCsvExport($since);
    }

    #[Route(path: '/set-exported', name: '_set_exported', methods: ['POST'])]
    public function setExported(Request $request): Response
    {
        // Get timestamp from form or use current time
        $timestampStr = $request->request->get('timestamp');

        if (!$timestampStr) {
            $this->addFlash('error', 'Kein Zeitstempel erhalten');
            return $this->redirectToRoute('admin_dogtags_index');
        }

        try {
            $timestamp = new \DateTime($timestampStr);
        } catch (\Exception $e) {
            $this->addFlash('error', 'Ungültiger Zeitstempel: ' . $timestampStr);
            return $this->redirectToRoute('admin_dogtags_index');
        }

        // Get or create the setting
        $setting = $this->settingRepo->findByKey('dogtag_last_export');

        if (!$setting) {
            $setting = new Setting('dogtag_last_export');
            $this->em->persist($setting);
        }

        // Flush first to trigger lifecycle callbacks
        $this->em->flush();

        // Now update the timestamp manually to bypass the PreUpdate callback
        $this->em->createQueryBuilder()
            ->update(Setting::class, 's')
            ->set('s.last_modified', ':timestamp')
            ->where('s.key = :key')
            ->setParameter('timestamp', $timestamp)
            ->setParameter('key', 'dogtag_last_export')
            ->getQuery()
            ->execute();

        $this->addFlash('success', 'Export-Zeitstempel gesetzt: ' . $timestamp->format('d.m.Y H:i'));

        return $this->redirectToRoute('admin_dogtags_index');
    }

    private function generateCsvExport(?string $sinceDate): Response
    {
        // Clear any output buffers to prevent debug output from appearing in CSV
        while (ob_get_level()) {
            ob_end_clean();
        }

        // Get all tickets
        $tickets = $this->ticketRepo->findAll();

        // Filter tickets by redemption date if needed
        if ($sinceDate) {
            $sinceDateTime = new \DateTimeImmutable($sinceDate);
            $tickets = array_filter($tickets, function($ticket) use ($sinceDateTime) {
                $redeemedAt = $ticket->getRedeemedAt();
                return $redeemedAt && $redeemedAt >= $sinceDateTime;
            });
        }

        // Extract unique user UUIDs from tickets (only redeemed tickets have a redeemer UUID)
        $uniqueUuids = [];
        foreach ($tickets as $ticket) {
            $redeemer = $ticket->getRedeemer();
            if ($redeemer) {
                $uniqueUuids[$redeemer->toString()] = $redeemer;
            }
        }

        // Fetch only users with tickets using bulk request
        $users = [];
        if (!empty($uniqueUuids)) {
            $users = $this->idmManager->bulk(User::class, array_values($uniqueUuids));
        }

        // Sort users by nickname length (descending), then by nickname
        usort($users, function($a, $b) {
            $nicknameA = $a->getNickname() ?? '';
            $nicknameB = $b->getNickname() ?? '';
            $lengthA = mb_strlen($nicknameA);
            $lengthB = mb_strlen($nicknameB);

            if ($lengthA === $lengthB) {
                return strcasecmp($nicknameA, $nicknameB);
            }

            return $lengthB <=> $lengthA;
        });

        // Generate CSV content
        $csv = [];

        $partyNameSetting = $this->settingRepo->findByKey('lan.party.number');
        $partyNumber = $partyNameSetting ? $partyNameSetting->getText() : '';

        // Format party dates
        $partyDate = '';
        $partyStart = $this->settingService->get('lan.party.start');
        $partyEnd = $this->settingService->get('lan.party.end');
        if ($partyStart) {
            $partyDate = $this->helperService->toShortDate($partyStart, 'd.m.');
            if ($partyEnd) {
                $partyDate .= ' - ' . $this->helperService->toShortDate($partyEnd, 'd.m.Y');
            }
        }

        // No header row

        foreach ($users as $user) {
            // Get clan tags
            $clanTags = [];
            $clans = $user->getClans();
            if ($clans) {
                foreach ($clans as $clan) {
                    $clanTags[] = $clan->getClantag();
                }
            }

            $csv[] = [
                $user->getNickname() ?? '',
                implode(', ', $clanTags),
                $user->getFirstname() ?? '',
                $user->getSurname() ?? '',
                $partyNumber,
                $partyDate,
            ];
        }

        // Create response
        $response = new Response();
        $response->headers->set('Content-Type', 'text/csv; charset=utf-8');

        $filename = 'dogtags-' . date('Y-m-d');
        if ($sinceDate) {
            $filename .= '-since-' . $sinceDate;
        }
        $filename .= '.csv';

        $response->headers->set('Content-Disposition', 'attachment; filename="' . $filename . '"');

        // Add BOM for Excel compatibility
        $content = "\xEF\xBB\xBF";

        // Generate CSV content
        $handle = fopen('php://temp', 'r+');
        foreach ($csv as $row) {
            fputcsv($handle, $row);
        }
        rewind($handle);
        $content .= stream_get_contents($handle);
        fclose($handle);

        $response->setContent($content);

        return $response;
    }
}
