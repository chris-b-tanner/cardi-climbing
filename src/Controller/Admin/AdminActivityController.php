<?php

namespace App\Controller\Admin;

use App\Repository\NoteRepository;
use App\Repository\UserRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/** A GitHub-style activity grid: one row per team/admin member, one column per day over the last three weeks, each cell shaded by how many notes that person added that day. Bulk email audit notes are left out — see NoteRepository::findActivitySince(). */
#[Route('/admin/activity')]
#[IsGranted('ROLE_TEAM')]
class AdminActivityController extends AbstractController
{
    private const DAYS = 21;

    #[Route('', name: 'app_admin_activity')]
    public function index(NoteRepository $noteRepository, UserRepository $userRepository): Response
    {
        $today = new \DateTimeImmutable('today');
        $start = $today->modify('-' . (self::DAYS - 1) . ' days');

        $days = [];
        for ($i = 0; $i < self::DAYS; $i++) {
            $day = $start->modify("+$i days");
            $days[$day->format('Y-m-d')] = $day;
        }

        // [userId][Y-m-d] => count
        $counts = [];
        foreach ($noteRepository->findActivitySince($start) as $row) {
            $key = $row['createdAt']->setTimezone($today->getTimezone())->format('Y-m-d');
            if (isset($days[$key])) {
                $counts[$row['addedById']][$key] = ($counts[$row['addedById']][$key] ?? 0) + 1;
            }
        }

        $max = 0;
        $rows = [];
        foreach ($userRepository->findTeam('firstName') as $user) {
            $cells = [];
            foreach (array_keys($days) as $key) {
                $cells[$key] = $counts[$user->getId()][$key] ?? 0;
            }
            $max = max($max, ...array_values($cells));
            $rows[] = ['user' => $user, 'cells' => $cells, 'total' => array_sum($cells)];
        }

        // Busiest first, then by name (findTeam's order is kept for ties — usort is stable).
        usort($rows, static fn (array $a, array $b) => $b['total'] <=> $a['total']);

        $dayTotals = [];
        foreach (array_keys($days) as $key) {
            $dayTotals[$key] = array_sum(array_map(static fn (array $r) => $r['cells'][$key], $rows));
        }

        return $this->render('admin/activity/index.html.twig', [
            'days'      => $days,
            'rows'      => $rows,
            'dayTotals' => $dayTotals,
            'max'       => $max,
            'today'     => $today->format('Y-m-d'),
        ]);
    }
}
