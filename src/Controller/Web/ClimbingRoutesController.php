<?php

namespace App\Controller\Web;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * MOCKUP — public list of the routes currently set at the wall, used to show prospective
 * route sponsors what a branded route looks like. Data is hard-coded below and will be replaced
 * by the route management system. The sponsors are real businesses; the route data is sample data.
 */
class ClimbingRoutesController extends AbstractController
{
    // Ordered easiest → hardest; the index is the sort rank used by the "grade" sort.
    private const BOULDER_GRADES = ['VB', 'V0', 'V1', 'V2', 'V3', 'V4', 'V5', 'V6', 'V7', 'V8'];
    private const ROPED_GRADES   = ['4', '5', '5+', '6a', '6a+', '6b', '6b+', '6c', '6c+', '7a', '7a+'];

    private const SPONSORS = [
        'harvst' => [
            'name'    => 'Harvst',
            'logo'    => '/images/routes-mock/harvst.png',
            'url'     => 'https://harvst.io',
            'tagline' => 'Grow more',
            'colour'  => '#3a3a3a',
            'about'   => 'Harvst helps growing businesses do more of what they do best, with software that takes care of the busywork.',
        ],
        'craftcourses' => [
            'name'    => 'Craft Courses',
            'logo'    => '/images/routes-mock/craft-courses.svg',
            'url'     => 'https://www.craftcourses.com',
            'tagline' => 'Art & craft workshops with 1000s of UK makers',
            'colour'  => '#be3455',
            'about'   => 'Craft Courses lists art and craft workshops run by thousands of independent makers across the UK, from pottery and printmaking to knitting and woodwork.',
        ],
        'adventurebeyond' => [
            'name'    => 'Adventure Beyond',
            'logo'    => '/images/routes-mock/adventure-beyond.png',
            'url'     => 'https://adventurebeyond.co.uk',
            'tagline' => 'Family-run outdoor adventures in Cardigan Bay since 1997',
            'colour'  => '#0a4fa3',
            'about'   => 'A family-run outdoor activity centre in Cardigan, running adventures on the coast and rivers of Cardigan Bay since 1997.',
        ],
        'baytoremember' => [
            'name'    => 'A Bay to Remember',
            'logo'    => '/images/routes-mock/bay-to-remember.png',
            'url'     => 'https://www.baytoremember.co.uk',
            'tagline' => 'Dolphin watching trips in Cardigan Bay',
            'colour'  => '#e5572b',
            'about'   => 'Boat trips from Cardigan Bay to watch dolphins and other wildlife along the Ceredigion coast.',
        ],
        'pobldda' => [
            'name'    => 'Pobl Dda',
            'logo'    => '/images/routes-mock/pobl-dda.png',
            'url'     => 'https://www.pobl-dda.com',
            'tagline' => 'Hot desks & coworking in Cardigan, West Wales',
            'colour'  => '#b9533c',
            'about'   => 'A collective of creative and self-employed folk based around a coworking space on Cardigan high street, with hot desks for anyone who wants good company while they work.',
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

    #[Route('/routes/{slug}', name: 'app_climbing_route_show', requirements: ['slug' => '[a-z0-9-]+'])]
    public function show(string $slug): Response
    {
        $all = [
            'problem' => $this->prepare(self::boulders(), self::BOULDER_GRADES),
            'route'   => $this->prepare(self::roped(), self::ROPED_GRADES),
        ];

        foreach ($all as $kind => $routes) {
            foreach ($routes as $route) {
                if ($route['slug'] === $slug) {
                    return $this->render('climbing_routes/show.html.twig', [
                        'route' => $route,
                        'kind'  => $kind,
                    ]);
                }
            }
        }

        throw $this->createNotFoundException();
    }

    /**
     * Attaches grade rank and sponsor details, then orders by popularity (the default sort),
     * keeping sponsored routes pinned at the top — the template's sort keeps them there too.
     */
    private function prepare(array $routes, array $grades): array
    {
        foreach ($routes as &$route) {
            $route['gradeRank'] = array_search($route['grade'], $grades, true);
            $route['sponsor']   = isset($route['sponsor']) ? self::SPONSORS[$route['sponsor']] : null;
            $route['pinned']    = $route['sponsor'] !== null;
        }
        unset($route);

        usort($routes, fn (array $a, array $b) => [$b['pinned'], $b['ascents']] <=> [$a['pinned'], $a['ascents']]);

        return $routes;
    }

    private static function boulders(): array
    {
        return [
            ['slug' => 'coasteer-clear', 'grade' => 'V3', 'colour' => 'Blue',   'hex' => '#2f6fd6', 'wall' => 'Zone 1 – Cave',  'setter' => 'Rhys', 'setOn' => '2026-10-02', 'ascents' => 142, 'rating' => 4.6, 'name' => 'Coasteer Clear', 'sponsor' => 'adventurebeyond',
                'description' => 'A sit start under the lip of the cave, then a run of big, positive holds out to the edge. Keep your feet on through the roof and the top-out is a jug fest.'],
            ['slug' => 'dolphin-dyno', 'grade' => 'V5', 'colour' => 'Black',  'hex' => '#141414', 'wall' => 'Zone 2 – Overhang', 'setter' => 'Rhys', 'setOn' => '2026-09-25', 'ascents' => 64,  'rating' => 4.8, 'name' => 'Dolphin Dyno', 'sponsor' => 'baytoremember',
                'description' => 'Two moves to set up, then one committing leap to the finishing jug. Hard to stick, but most people find it pure fun to try.'],
            ['slug' => 'hot-desk', 'grade' => 'V1', 'colour' => 'Orange', 'hex' => '#ef8a2b', 'wall' => 'Zone 1 – Slab', 'setter' => 'Owain', 'setOn' => '2026-11-06', 'ascents' => 58, 'rating' => 4.3, 'name' => 'Hot Desk', 'sponsor' => 'pobldda',
                'description' => 'A sociable warm-up with plenty of room for company. Big friendly holds lead to a balancy step-through near the top, where a quick chat with your feet keeps things moving.'],
            ['slug' => 'green-fingers', 'grade' => 'V2', 'colour' => 'Teal',   'hex' => '#1e9c96', 'wall' => 'Zone 2 – Slab', 'setter' => 'Cat',  'setOn' => '2026-10-09', 'ascents' => 134, 'rating' => 4.0, 'name' => 'Green Fingers', 'sponsor' => 'harvst',
                'description' => 'A delicate slab of small crimps and smears. Trust your feet, keep your weight over them and the route grows on you.'],
        ];
    }

    private static function roped(): array
    {
        return [
            ['slug' => 'the-beanpole', 'grade' => '6b',  'colour' => 'Purple', 'hex' => '#7a4cc2', 'wall' => 'Line 4', 'style' => 'Top rope', 'setter' => 'Owain','setOn' => '2026-09-28', 'ascents' => 72,  'rating' => 4.3, 'name' => 'The Beanpole', 'sponsor' => 'harvst',
                'description' => 'Tall, straight up, and full of long reaches between well-spaced holds. Shorter climbers will find a high foot or two that does the job.'],
            ['slug' => 'cast-on', 'grade' => '6a',  'colour' => 'Pink',   'hex' => '#e463a6', 'wall' => 'Line 3', 'style' => 'Top rope', 'setter' => 'Cat',  'setOn' => '2026-09-28', 'ascents' => 111, 'rating' => 4.1, 'name' => 'Cast On', 'sponsor' => 'craftcourses',
                'description' => 'A flowing line of pinches and side-pulls that weaves up the wall. Find the rhythm early and it comes together stitch by stitch.'],
        ];
    }
}
