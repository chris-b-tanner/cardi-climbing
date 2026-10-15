<?php

namespace App\Controller\Web;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * MOCKUP — public list of the routes currently set at the wall, used to show prospective
 * route sponsors what a branded route looks like. Data is hard-coded below and will be replaced
 * by the route management system; the sponsors and their logos are fictional.
 */
class ClimbingRoutesController extends AbstractController
{
    // Ordered easiest → hardest; the index is the sort rank used by the "grade" sort.
    private const BOULDER_GRADES = ['VB', 'V0', 'V1', 'V2', 'V3', 'V4', 'V5', 'V6', 'V7', 'V8'];
    private const ROPED_GRADES   = ['4', '5', '5+', '6a', '6a+', '6b', '6b+', '6c', '6c+', '7a', '7a+'];

    private const SPONSORS = [
        'summit' => [
            'name'    => 'Summit Coffee Co.',
            'logo'    => '/images/routes-mock/summit-coffee.svg',
            'url'     => '#',
            'tagline' => 'Fuelling every send since 2019',
            'colour'  => '#3b2418',
        ],
        'chalk' => [
            'name'    => 'Chalk & Co.',
            'logo'    => '/images/routes-mock/chalk-and-co.svg',
            'url'     => '#',
            'tagline' => 'Grip it. Send it.',
            'colour'  => '#141414',
        ],
        'coastline' => [
            'name'    => 'Coastline Outdoor',
            'logo'    => '/images/routes-mock/coastline-outdoor.svg',
            'url'     => '#',
            'tagline' => 'Kit for the Ceredigion coast and crag',
            'colour'  => '#0f5c74',
        ],
    ];

    #[Route('/routes', name: 'app_climbing_routes')]
    public function index(): Response
    {
        return $this->render('climbing_routes/index.html.twig', [
            'boulders' => $this->prepare(self::boulders(), self::BOULDER_GRADES),
            'roped'    => $this->prepare(self::roped(), self::ROPED_GRADES),
        ]);
    }

    /**
     * Attaches grade rank and sponsor details, then orders by popularity (the default sort),
     * keeping pinned routes at the top.
     */
    private function prepare(array $routes, array $grades): array
    {
        foreach ($routes as &$route) {
            $route['gradeRank'] = array_search($route['grade'], $grades, true);
            $route['sponsor']   = isset($route['sponsor']) ? self::SPONSORS[$route['sponsor']] : null;
            $route['pinned']    = $route['pinned'] ?? false;
        }
        unset($route);

        usort($routes, fn (array $a, array $b) => [$b['pinned'], $b['ascents']] <=> [$a['pinned'], $a['ascents']]);

        return $routes;
    }

    private static function boulders(): array
    {
        return [
            ['grade' => 'V3', 'colour' => 'Blue',   'hex' => '#2f6fd6', 'wall' => 'Zone 1 – Cave',  'setter' => 'Rhys', 'setOn' => '2026-10-02', 'ascents' => 142, 'rating' => 4.6, 'name' => 'Espresso Crimp', 'sponsor' => 'summit'],
            ['grade' => 'V0', 'colour' => 'Yellow', 'hex' => '#ecbe49', 'wall' => 'Zone 1 – Slab', 'setter' => 'Cat',  'setOn' => '2026-10-09', 'ascents' => 211, 'rating' => 4.1],
            ['grade' => 'V5', 'colour' => 'Black',  'hex' => '#141414', 'wall' => 'Zone 2 – Overhang', 'setter' => 'Rhys', 'setOn' => '2026-09-25', 'ascents' => 64,  'rating' => 4.8, 'name' => 'Dust Devil', 'sponsor' => 'chalk'],
            ['grade' => 'VB', 'colour' => 'White',  'hex' => '#ffffff', 'wall' => 'Zone 2 – Slab', 'setter' => 'Cat',  'setOn' => '2026-10-09', 'ascents' => 188, 'rating' => 3.9],
            ['grade' => 'V2', 'colour' => 'Green',  'hex' => '#3c9a5f', 'wall' => 'Zone 2 – Arête', 'setter' => 'Owain','setOn' => '2026-10-02', 'ascents' => 156, 'rating' => 4.3, 'name' => 'Mam’s Ladder', 'namedBy' => 'Elin W.'],
            ['grade' => 'V4', 'colour' => 'Red',    'hex' => '#d6402f', 'wall' => 'Zone 1 – Cave',  'setter' => 'Owain','setOn' => '2026-09-25', 'ascents' => 91,  'rating' => 4.0],
            ['grade' => 'V1', 'colour' => 'Orange', 'hex' => '#ef8a2b', 'wall' => 'Zone 1 – Slab', 'setter' => 'Rhys', 'setOn' => '2026-10-09', 'ascents' => 173, 'rating' => 4.2],
            ['grade' => 'V6', 'colour' => 'Purple', 'hex' => '#7a4cc2', 'wall' => 'Zone 2 – Overhang', 'setter' => 'Cat',  'setOn' => '2026-09-18', 'ascents' => 38,  'rating' => 4.7, 'name' => 'Teifi Tide', 'sponsor' => 'coastline'],
            ['grade' => 'V3', 'colour' => 'Pink',   'hex' => '#e463a6', 'wall' => 'Zone 2 – Arête', 'setter' => 'Owain','setOn' => '2026-10-02', 'ascents' => 119, 'rating' => 3.8],
            ['grade' => 'V7', 'colour' => 'Grey',   'hex' => '#8a8a8a', 'wall' => 'Zone 1 – Cave',  'setter' => 'Rhys', 'setOn' => '2026-09-18', 'ascents' => 17,  'rating' => 4.9, 'name' => 'Bara Brith', 'namedBy' => 'Dafydd P.'],
            ['grade' => 'V2', 'colour' => 'Teal',   'hex' => '#1e9c96', 'wall' => 'Zone 2 – Slab', 'setter' => 'Cat',  'setOn' => '2026-10-09', 'ascents' => 134, 'rating' => 4.0],
            ['grade' => 'V4', 'colour' => 'Yellow', 'hex' => '#ecbe49', 'wall' => 'Zone 2 – Overhang', 'setter' => 'Owain','setOn' => '2026-09-25', 'ascents' => 77,  'rating' => 4.4],
        ];
    }

    private static function roped(): array
    {
        return [
            ['grade' => '5',   'colour' => 'Green',  'hex' => '#3c9a5f', 'wall' => 'Line 1', 'style' => 'Auto-belay', 'setter' => 'Cat',  'setOn' => '2026-10-05', 'ascents' => 164, 'rating' => 4.2],
            ['grade' => '6a+', 'colour' => 'Blue',   'hex' => '#2f6fd6', 'wall' => 'Line 3', 'style' => 'Top rope', 'setter' => 'Rhys', 'setOn' => '2026-09-28', 'ascents' => 98,  'rating' => 4.6, 'name' => 'High Tide Line', 'sponsor' => 'coastline', 'pinned' => true],
            ['grade' => '4',   'colour' => 'White',  'hex' => '#ffffff', 'wall' => 'Line 1', 'style' => 'Auto-belay', 'setter' => 'Owain','setOn' => '2026-10-05', 'ascents' => 203, 'rating' => 3.9],
            ['grade' => '6c',  'colour' => 'Red',    'hex' => '#d6402f', 'wall' => 'Line 5', 'style' => 'Lead', 'setter' => 'Rhys', 'setOn' => '2026-09-21', 'ascents' => 41,  'rating' => 4.7, 'name' => 'Double Shot', 'sponsor' => 'summit', 'pinned' => true],
            ['grade' => '5+',  'colour' => 'Orange', 'hex' => '#ef8a2b', 'wall' => 'Line 2', 'style' => 'Auto-belay', 'setter' => 'Cat',  'setOn' => '2026-10-05', 'ascents' => 129, 'rating' => 4.0, 'name' => 'Nain’s Staircase', 'namedBy' => 'Gwen T.'],
            ['grade' => '6b',  'colour' => 'Purple', 'hex' => '#7a4cc2', 'wall' => 'Line 4', 'style' => 'Top rope', 'setter' => 'Owain','setOn' => '2026-09-28', 'ascents' => 72,  'rating' => 4.3],
            ['grade' => '7a',  'colour' => 'Black',  'hex' => '#141414', 'wall' => 'Line 5', 'style' => 'Lead', 'setter' => 'Rhys', 'setOn' => '2026-09-14', 'ascents' => 14,  'rating' => 4.8],
            ['grade' => '6a',  'colour' => 'Pink',   'hex' => '#e463a6', 'wall' => 'Line 3', 'style' => 'Top rope', 'setter' => 'Cat',  'setOn' => '2026-09-28', 'ascents' => 111, 'rating' => 4.1],
            ['grade' => '6b+', 'colour' => 'Yellow', 'hex' => '#ecbe49', 'wall' => 'Line 4', 'style' => 'Top rope', 'setter' => 'Owain','setOn' => '2026-09-21', 'ascents' => 55,  'rating' => 4.5],
        ];
    }
}
