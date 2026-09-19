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
    // Fixed left edge of the chart — not a rolling "last N days" window, so the start point stays
    // put and the chart simply grows one day wider each day rather than sliding along.
    private const CHART_START_DATE = '2026-08-20';

    // The crowdfunder launch target. The baseline date/count are a fixed, explicitly-chosen
    // starting point, not recomputed from "today" — the point is a static trajectory to measure
    // real progress against, not a line that quietly re-anchors itself every day.
    private const TARGET_DATE           = '2026-10-06';
    private const TARGET_COUNT          = 500;
    private const TARGET_BASELINE_DATE  = '2026-09-17';
    private const TARGET_BASELINE_COUNT = 300;

    // SVG chart geometry — computed here rather than in Twig so the template just places
    // ready-made coordinates, no arithmetic in the view.
    private const CHART_WIDTH   = 960;
    private const CHART_HEIGHT  = 220;
    private const PAD_LEFT      = 36;
    private const PAD_RIGHT     = 12;
    private const PAD_TOP       = 16;
    private const PAD_BOTTOM    = 28;

    #[Route('', name: 'app_admin_dashboard')]
    public function index(UserRepository $userRepository): Response
    {
        $optedInDates = $userRepository->findOptedInCreatedDates();
        $optedInDaily = $this->dailyCounts($optedInDates);
        $allDaily     = $this->dailyCounts($userRepository->findAllCreatedDates());

        $baselineDate = new \DateTimeImmutable(self::TARGET_BASELINE_DATE);
        $targetDate   = new \DateTimeImmutable(self::TARGET_DATE);

        return $this->render('admin/dashboard/index.html.twig', [
            'chart' => $this->buildChart(
                daily: $optedInDaily,
                target: [
                    'start' => ['date' => $baselineDate, 'count' => self::TARGET_BASELINE_COUNT],
                    'end'   => ['date' => $targetDate, 'count' => self::TARGET_COUNT],
                ],
            ),
            'totalOptedIn' => $optedInDaily[array_key_last($optedInDaily)]['count'],
            'startCount'   => $optedInDaily[0]['count'],
            'totalMembers' => $allDaily[array_key_last($allDaily)]['count'],
        ]);
    }

    /**
     * Cumulative count of {createdDates} still relevant as of each day from the fixed
     * CHART_START_DATE through today inclusive — i.e. "how many of these people had already
     * joined by this point," not a historical status reconstruction. The array grows by one entry
     * a day as today advances; it's never a fixed-length rolling window.
     *
     * @param \DateTimeImmutable[] $createdDates
     * @return array<int, array{date: \DateTimeImmutable, count: int}>
     */
    private function dailyCounts(array $createdDates): array
    {
        $day   = new \DateTimeImmutable(self::CHART_START_DATE);
        $today = new \DateTimeImmutable('today');
        $daily = [];

        while ($day <= $today) {
            $daily[] = ['date' => $day, 'count' => $this->countAsOf($createdDates, $day)];
            $day = $day->modify('+1 day');
        }

        return $daily;
    }

    /** How many of {createdDates} fall on or before the end of {day}. */
    private function countAsOf(array $createdDates, \DateTimeImmutable $day): int
    {
        $endOfDay = $day->setTime(23, 59, 59);

        $count = 0;
        foreach ($createdDates as $createdAt) {
            if ($createdAt <= $endOfDay) {
                $count++;
            }
        }

        return $count;
    }

    /**
     * @param array<int, array{date: \DateTimeImmutable, count: int}> $daily The real "opted in" history, from CHART_START_DATE to today
     * @param array{start: array{date: \DateTimeImmutable, count: int}, end: array{date: \DateTimeImmutable, count: int}} $target
     * @return array{width: int, height: int, series: array, target: ?array, xAxis: array, yAxis: array}
     */
    private function buildChart(array $daily, array $target): array
    {
        $startDate = $daily[0]['date'];
        $endDate   = max($target['end']['date'], $daily[array_key_last($daily)]['date']);
        $spanDays  = (int) $startDate->diff($endDate)->days;
        $spanDays  = max($spanDays, 1); // avoid division by zero on a same-day start/end

        // Shared y-scale across the real data and the target line — they need to sit on one set
        // of axes to be visually comparable, not each normalised to its own range.
        $allCounts = array_merge(
            array_column($daily, 'count'),
            [$target['start']['count'], $target['end']['count']],
        );
        $minCount = min($allCounts);
        $maxCount = max($allCounts);
        $range    = max($maxCount - $minCount, 1); // avoid division by zero if everything is flat

        $innerWidth  = self::CHART_WIDTH - self::PAD_LEFT - self::PAD_RIGHT;
        $innerHeight = self::CHART_HEIGHT - self::PAD_TOP - self::PAD_BOTTOM;

        $xForDate = fn (\DateTimeImmutable $date) => self::PAD_LEFT
            + ($startDate->diff($date)->days / $spanDays) * $innerWidth;
        $yFor = fn (int $count) => self::PAD_TOP + $innerHeight - (($count - $minCount) / $range) * $innerHeight;

        $points = [];
        foreach ($daily as $row) {
            $points[] = [
                'x'     => round($xForDate($row['date']), 1),
                'y'     => round($yFor($row['count']), 1),
                'date'  => $row['date'],
                'count' => $row['count'],
            ];
        }

        $optedIn = [
            'color'    => 'var(--teal)',
            'label'    => 'opted in',
            'points'   => $points,
            'polyline' => implode(' ', array_map(static fn (array $p) => "{$p['x']},{$p['y']}", $points)),
        ];

        $targetPoints = [
            [
                'x'     => round($xForDate($target['start']['date']), 1),
                'y'     => round($yFor($target['start']['count']), 1),
                'date'  => $target['start']['date'],
                'count' => $target['start']['count'],
            ],
            [
                'x'     => round($xForDate($target['end']['date']), 1),
                'y'     => round($yFor($target['end']['count']), 1),
                'date'  => $target['end']['date'],
                'count' => $target['end']['count'],
            ],
        ];
        $targetLine = [
            'color'    => '#d1d5db',
            'label'    => 'target: ' . self::TARGET_COUNT . ' by ' . $target['end']['date']->format('j M'),
            'points'   => $targetPoints,
            'polyline' => implode(' ', array_map(static fn (array $p) => "{$p['x']},{$p['y']}", $targetPoints)),
        ];

        // Weekly ticks across the full (past + future) span, plus the target date itself so the
        // finish line is always labelled even if it doesn't land on a weekly boundary.
        $xAxis = [];
        for ($offset = 0; $offset <= $spanDays; $offset += 7) {
            $date = $startDate->modify("+{$offset} days");
            $xAxis[] = ['x' => round($xForDate($date), 1), 'label' => $date->format('d M')];
        }
        if (end($xAxis)['label'] !== $target['end']['date']->format('d M')) {
            $xAxis[] = ['x' => round($xForDate($target['end']['date']), 1), 'label' => $target['end']['date']->format('d M')];
        }

        return [
            'width'  => self::CHART_WIDTH,
            'height' => self::CHART_HEIGHT,
            'series' => ['optedIn' => $optedIn],
            'target' => $targetLine,
            'xAxis'  => $xAxis,
            'yAxis'  => [
                ['y' => round($yFor($minCount), 1), 'label' => (string) $minCount],
                ['y' => round($yFor($maxCount), 1), 'label' => (string) $maxCount],
            ],
        ];
    }
}
