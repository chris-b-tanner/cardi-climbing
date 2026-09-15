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
        $optedInDaily = $this->dailyCounts($userRepository->findOptedInCreatedDates());
        $allDaily     = $this->dailyCounts($userRepository->findAllCreatedDates());

        return $this->render('admin/dashboard/index.html.twig', [
            'chart'            => $this->buildChart([
                'total'    => ['daily' => $allDaily, 'color' => '#d1d5db', 'label' => 'total members'],
                'optedIn'  => ['daily' => $optedInDaily, 'color' => 'var(--teal)', 'label' => 'opted in'],
            ]),
            'totalOptedIn'     => $optedInDaily[self::DAYS - 1]['count'],
            'sixWeeksAgoCount' => $optedInDaily[0]['count'],
            'totalMembers'     => $allDaily[self::DAYS - 1]['count'],
        ]);
    }

    /**
     * Cumulative count of {createdDates} still relevant as of each of the last DAYS days — i.e.
     * "how many of these people had already joined by this point," not a historical status
     * reconstruction (see the two callers: one already-filtered to currently opted-in, one not).
     *
     * @param \DateTimeImmutable[] $createdDates
     * @return array<int, array{date: \DateTimeImmutable, count: int}>
     */
    private function dailyCounts(array $createdDates): array
    {
        $today = new \DateTimeImmutable('today');
        $daily = [];

        for ($i = self::DAYS - 1; $i >= 0; $i--) {
            $day = $today->modify("-{$i} days");
            $endOfDay = $day->setTime(23, 59, 59);

            $count = 0;
            foreach ($createdDates as $createdAt) {
                if ($createdAt <= $endOfDay) {
                    $count++;
                }
            }

            $daily[] = ['date' => $day, 'count' => $count];
        }

        return $daily;
    }

    /**
     * @param array<string, array{daily: array<int, array{date: \DateTimeImmutable, count: int}>, color: string, label: string}> $seriesInput
     * @return array{width: int, height: int, series: array, xAxis: array, yAxis: array}
     */
    private function buildChart(array $seriesInput): array
    {
        // Both series cover the same 42-day window, so any one of them gives the shared x-axis
        // dates/point count — they don't need to be looked up per series.
        $reference = reset($seriesInput)['daily'];
        $lastIndex = count($reference) - 1;

        // Shared y-scale across every series — the two lines need to sit on one set of axes to
        // be visually comparable, not each normalised to its own range.
        // array_values() first: $seriesInput's string keys ('total', 'optedIn') would otherwise
        // be spread as named arguments into array_merge()'s variadic parameter, which fatals.
        $allCounts = array_merge(...array_values(array_map(
            static fn(array $s) => array_column($s['daily'], 'count'),
            $seriesInput,
        )));
        $minCount = min($allCounts);
        $maxCount = max($allCounts);
        $range    = max($maxCount - $minCount, 1); // avoid division by zero if every series is flat

        $innerWidth  = self::CHART_WIDTH - self::PAD_LEFT - self::PAD_RIGHT;
        $innerHeight = self::CHART_HEIGHT - self::PAD_TOP - self::PAD_BOTTOM;

        $xFor = static fn(int $i) => self::PAD_LEFT + ($lastIndex > 0 ? ($i / $lastIndex) * $innerWidth : 0);
        $yFor = static fn(int $count) => self::PAD_TOP + $innerHeight - (($count - $minCount) / $range) * $innerHeight;

        $series = [];
        foreach ($seriesInput as $key => $s) {
            $points = [];
            foreach ($s['daily'] as $i => $row) {
                $points[] = [
                    'x'     => round($xFor($i), 1),
                    'y'     => round($yFor($row['count']), 1),
                    'date'  => $row['date'],
                    'count' => $row['count'],
                ];
            }

            $series[$key] = [
                'color'    => $s['color'],
                'label'    => $s['label'],
                'points'   => $points,
                'polyline' => implode(' ', array_map(static fn(array $p) => "{$p['x']},{$p['y']}", $points)),
            ];
        }

        // One label per week (the day itself, plus every 7th going back) so 42 daily dots don't
        // turn into 42 overlapping x-axis labels.
        $xAxis = [];
        for ($i = $lastIndex; $i >= 0; $i -= 7) {
            $xAxis[] = ['x' => round($xFor($i), 1), 'label' => $reference[$i]['date']->format('d M')];
        }

        return [
            'width'  => self::CHART_WIDTH,
            'height' => self::CHART_HEIGHT,
            'series' => $series,
            'xAxis'  => array_reverse($xAxis),
            'yAxis'  => [
                ['y' => round($yFor($minCount), 1), 'label' => (string) $minCount],
                ['y' => round($yFor($maxCount), 1), 'label' => (string) $maxCount],
            ],
        ];
    }
}
