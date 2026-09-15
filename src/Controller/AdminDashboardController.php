<?php

namespace App\Controller;

use App\Repository\UserRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/** For now, just a membership growth chart to motivate the outreach team — more to come. */
#[Route('/admin/dashboard')]
#[IsGranted('ROLE_TEAM')]
class AdminDashboardController extends AbstractController
{
    private const DAYS = 42; // 6 weeks

    // SVG chart geometry — computed here rather than in Twig so the template just places
    // ready-made coordinates, no arithmetic in the view.
    private const CHART_WIDTH   = 700;
    private const CHART_HEIGHT  = 220;
    private const PAD_LEFT      = 36;
    private const PAD_RIGHT     = 12;
    private const PAD_TOP       = 16;
    private const PAD_BOTTOM    = 28;

    #[Route('', name: 'app_admin_dashboard')]
    public function index(UserRepository $userRepository): Response
    {
        $joinDates = $userRepository->findOptedInCreatedDates();

        $today = new \DateTimeImmutable('today');
        $daily = [];

        for ($i = self::DAYS - 1; $i >= 0; $i--) {
            $day = $today->modify("-{$i} days");
            $endOfDay = $day->setTime(23, 59, 59);

            $count = 0;
            foreach ($joinDates as $joinedAt) {
                if ($joinedAt <= $endOfDay) {
                    $count++;
                }
            }

            $daily[] = ['date' => $day, 'count' => $count];
        }

        return $this->render('admin/dashboard/index.html.twig', [
            'chart'        => $this->buildChart($daily),
            'totalOptedIn' => count($joinDates),
            'sixWeeksAgoCount' => $daily[0]['count'],
        ]);
    }

    /**
     * @param array<int, array{date: \DateTimeImmutable, count: int}> $daily
     * @return array{width: int, height: int, polyline: string, points: array, yAxis: array, xAxis: array}
     */
    private function buildChart(array $daily): array
    {
        $counts   = array_column($daily, 'count');
        $minCount = min($counts);
        $maxCount = max($counts);
        $range    = max($maxCount - $minCount, 1); // avoid division by zero on a flat line

        $innerWidth  = self::CHART_WIDTH - self::PAD_LEFT - self::PAD_RIGHT;
        $innerHeight = self::CHART_HEIGHT - self::PAD_TOP - self::PAD_BOTTOM;
        $lastIndex   = count($daily) - 1;

        $xFor = static fn(int $i) => self::PAD_LEFT + ($lastIndex > 0 ? ($i / $lastIndex) * $innerWidth : 0);
        $yFor = static fn(int $count) => self::PAD_TOP + $innerHeight - (($count - $minCount) / $range) * $innerHeight;

        $points = [];
        foreach ($daily as $i => $row) {
            $points[] = [
                'x'     => round($xFor($i), 1),
                'y'     => round($yFor($row['count']), 1),
                'date'  => $row['date'],
                'count' => $row['count'],
            ];
        }

        $polyline = implode(' ', array_map(static fn(array $p) => "{$p['x']},{$p['y']}", $points));

        // One label per week (the day itself, plus every 7th going back) so 42 daily dots don't
        // turn into 42 overlapping x-axis labels.
        $xAxis = [];
        for ($i = $lastIndex; $i >= 0; $i -= 7) {
            $xAxis[] = ['x' => $points[$i]['x'], 'label' => $daily[$i]['date']->format('d M')];
        }

        return [
            'width'    => self::CHART_WIDTH,
            'height'   => self::CHART_HEIGHT,
            'polyline' => $polyline,
            'points'   => $points,
            'xAxis'    => array_reverse($xAxis),
            'yAxis'    => [
                ['y' => round($yFor($minCount), 1), 'label' => (string) $minCount],
                ['y' => round($yFor($maxCount), 1), 'label' => (string) $maxCount],
            ],
        ];
    }
}
