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

    // Crowdfunder reward for sponsoring a route — linked from the listing and every route page.
    private const SPONSOR_ROUTE_URL = 'https://www.crowdfunder.co.uk/checkout/cardigan-climbing-and-bouldering/start/2993975';

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
        'madebybill' => [
            'name'     => 'Made by Bill',
            'logo'     => '/images/routes-mock/made-by-bill.png',
            'logoDark' => true, // their logo is white-on-transparent; the templates darken it for our white cards
            'url'      => 'https://www.bybill.co.uk',
            'tagline'  => 'A design and make studio in Wales',
            'colour'   => '#6b4a2f',
            'about'    => 'Made by Bill makes small runs of its own objects and furniture, and takes on private commissions, kitchens and interiors for homes and businesses. Everything is handmade in their workshop in Wales using local native timbers.',
        ],
        'baramenyn' => [
            'name'    => 'Bara Menyn',
            'logo'    => '/images/routes-mock/bara-menyn.png',
            'url'     => 'https://www.baramenynbakehouse.co.uk',
            'tagline' => 'Bakehouse & mill on Chancery Lane, Aberteifi',
            'colour'  => '#c4497a',
            'about'   => 'Bara Menyn bakes burnished sourdough loaves, focaccia, pizza by the slice and their famous almond croissant at 6B Chancery Lane in Cardigan, using flour stone-milled in-house from local landrace wheats.',
        ],
        'elsalsa' => [
            'name'    => 'El Salsa',
            'logo'    => '/images/routes-mock/el-salsa.png', // their white logo, recoloured orange for our white cards
            'url'     => 'https://elsalsa.co.uk',
            'tagline' => 'Mexican kitchen & bar, Cardigan',
            'colour'  => '#c8471a',
            'about'   => 'El Salsa is a Mexican kitchen and bar in Cardigan, serving food from the kitchen and drinks from the bar. In their own words: “Come on down, we can’t wait to feed you!”',
        ],
        'cardished' => [
            'name'    => 'CardiShed',
            'logo'    => '/images/routes-mock/cardished.png',
            'url'     => 'https://cardished.cymru',
            'tagline' => 'Building community through creativity',
            'colour'  => '#c41e2a',
            'about'   => 'CardiShed is a volunteer-run charity in Cardigan with a carpentry machine shop and a greenwood workshop open to anyone over 18. It is an inclusive, non-judgmental place where everyone is made to feel welcome, with open workshops, wood carving and green woodwork through the week.',
        ],
        'cardibuildingsupplies' => [
            'name'     => 'Cardi Building Supplies',
            'logo'     => '/images/routes-mock/cardi-building-supplies.png',
            'url'      => 'https://www.cardibuildingsupplies.com',
            'tagline'  => 'Your local independent builders merchant',
            'colour'   => '#1f3a5f',
            'about'    => 'Cardigan’s local builders merchant for trade professionals and DIY customers, set up in 2003 at Llangoedmor. They offer expert advice, an approved Dulux paint mixing centre and free local delivery across Ceredigion, Pembrokeshire and Carmarthenshire.',
        ],
        'lofi' => [
            'name'    => 'LOFI',
            'logo'    => '/images/routes-mock/lofi.png',
            'url'     => 'https://www.lofi.com',
            'tagline' => 'An evolution in outdoor cooking',
            'colour'  => '#2b2b2b',
            'about'   => 'LOFI is a small outdoor equipment design and manufacturing company based in Cardigan, making gas-free cooking kit like the LOFI Stove to simplify life outdoors and respect the environment. Designed and made in West Wales.',
        ],
        'bluegreen' => [
            'name'    => 'BlueGreenCymru',
            'logo'    => '/images/routes-mock/bluegreen-cymru.png',
            'url'     => 'https://www.bluegreencymru.com',
            'tagline' => 'Health & wellbeing workshops in West Wales',
            'colour'  => '#2e8540',
            'about'   => 'BlueGreenCymru CIC works alongside local communities to support mental health and wellbeing through accessible spaces in nature, meaningful connections, tailored workshops and community food growing, at Glan-Teifi Woods overlooking St Dogmaels and the Danyrhelyg Community Garden in Newcastle Emlyn.',
        ],
        'howies' => [
            'name'    => 'howies',
            'logo'    => '/images/routes-mock/howies.png',
            'url'     => 'https://howies.co.uk',
            'tagline' => 'Active clothing from West Wales since 1995',
            'colour'  => '#4a4a4a',
            'about'   => 'howies is an independent active clothing company based in West Wales, owned and run by its employees. Since 1995 they have made high quality, lower impact clothing for the outdoors, running and cycling, using GOTS certified organic cotton and merino wool.',
        ],
        'frog' => [
            'name'    => 'frog architects',
            'logo'    => null, // no logo file on their site; the templates fall back to the name
            'url'     => 'https://frogarchitects.co.uk',
            'tagline' => 'Architects studio based in West Wales',
            'colour'  => '#3d7a3a',
            'about'   => 'Frog is an RIBA Chartered Practice based in West Wales, founded by Laura Knight and Phil Reeves. They specialise in new build, conservation and planning at all stages and scales, working closely with clients to deliver buildings that serve their needs while striving for sustainable outcomes.',
        ],
        'brwsh' => [
            'name'    => 'BRWSH',
            'logo'    => '/images/routes-mock/brwsh.png',
            'url'     => 'https://www.brwsh.co.uk',
            'tagline' => 'Trade trusted. Customer approved.',
            'colour'  => '#b5651d',
            'about'   => 'A family-run decorating supply store in Cardigan, founded in 2018 by Matthew and Claire Morgan. Pop in for free colour and wallpaper consultations, paint mixing and colour matching, with brands from Tikkurila and Johnstone’s to Sanderson and Morris & Co.',
        ],
        'hawen' => [
            'name'    => 'Hawen Heating',
            'logo'    => '/images/routes-mock/hawen-heating.png',
            'url'     => 'https://www.hawenheating.co.uk',
            'tagline' => 'Plumbing, heating & renewables',
            'colour'  => '#55730a',
            'about'   => 'A truly local business providing plumbing, heating and renewable energy solutions across Ceredigion, Pembrokeshire and Carmarthenshire, with over 15 years of experience and 24/7 emergency callouts.',
        ],
        'bvrees' => [
            'name'    => 'B V Rees',
            'logo'    => '/images/routes-mock/bv-rees.png',
            'url'     => 'https://www.bvrees.com',
            'tagline' => 'New & used cars and vans in West Wales',
            'colour'  => '#2b2b2b',
            'about'   => 'A family-run garage at Abbey Garage, St Dogmaels, founded in 1948 and selling and servicing Fiat vehicles since 1970. Today they sell new Fiat, KGM and Leapmotor vehicles alongside used cars and vans, with servicing, MOTs, a bodyshop and vehicle recovery.',
        ],
        'wenyn' => [
            'name'    => 'Wenyn',
            'logo'    => '/images/routes-mock/wenyn.png',
            'url'     => 'https://www.wenyn.co.uk',
            'tagline' => 'Natural beeswax candles, handpoured in West Wales',
            'colour'  => '#8a5a12',
            'about'   => 'Danny makes 100% local beeswax candles with cotton wicks, handpoured in West Wales. The wax comes from a local beekeeper who keeps 12 hives on Wenyn’s own land, and the candles contain nothing but beeswax and a cotton wick.',
        ],
    ];

    #[Route('/routes', name: 'app_climbing_routes')]
    public function index(): Response
    {
        $boulders = $this->prepare(self::boulders(), self::BOULDER_GRADES);
        $roped    = $this->prepare(self::roped(), self::ROPED_GRADES);

        // One entry per sponsor for the logo strip, linking to their first route.
        $sponsors = [];
        foreach ([...self::boulders(), ...self::roped()] as $route) {
            $key = $route['sponsor'] ?? null;
            if ($key !== null && !isset($sponsors[$key])) {
                $sponsors[$key] = ['sponsor' => self::SPONSORS[$key], 'slug' => $route['slug']];
            }
        }

        return $this->render('climbing_routes/index.html.twig', [
            'boulders' => $boulders,
            'roped'    => $roped,
            'sponsors' => array_values($sponsors),
            'sponsorRouteUrl' => self::SPONSOR_ROUTE_URL,
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
                        'sponsorRouteUrl' => self::SPONSOR_ROUTE_URL,
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
            ['slug' => 'sourdough-starter', 'grade' => 'V0', 'colour' => 'Yellow', 'hex' => '#ecbe49', 'wall' => 'Zone 1 – Slab', 'setter' => 'Rhys', 'setOn' => '2026-11-06', 'ascents' => 87, 'rating' => 4.4, 'name' => 'Sourdough Starter', 'sponsor' => 'baramenyn',
                'description' => 'The perfect first problem of the day. Generous, chunky holds and a gentle angle let you warm up slowly, and it gets better every time you come back to it.'],
            ['slug' => 'shed-load', 'grade' => 'V3', 'colour' => 'Red', 'hex' => '#d6402f', 'wall' => 'Zone 2 – Arête', 'setter' => 'Cat', 'setOn' => '2026-11-10', 'ascents' => 41, 'rating' => 4.5, 'name' => 'Shed Load', 'sponsor' => 'cardished',
                'description' => 'Hand-crafted on the arête with plenty of chunky, woody holds. Each move is simple on its own, but it takes a bit of patience and good technique to put the whole thing together.'],
            ['slug' => 'breathing-space', 'grade' => 'V2', 'colour' => 'Green', 'hex' => '#3c9a5f', 'wall' => 'Zone 1 – Slab', 'setter' => 'Cat', 'setOn' => '2026-11-10', 'ascents' => 52, 'rating' => 4.4, 'name' => 'Breathing Space', 'sponsor' => 'bluegreen',
                'description' => 'A calm, flowing slab with no need to rush. Slow down, breathe between moves and let your feet do the work, and it all falls into place.'],
            ['slug' => 'leap-frog', 'grade' => 'V4', 'colour' => 'Purple', 'hex' => '#7a4cc2', 'wall' => 'Zone 2 – Overhang', 'setter' => 'Rhys', 'setOn' => '2026-11-10', 'ascents' => 44, 'rating' => 4.6, 'name' => 'Leap Frog', 'sponsor' => 'frog',
                'description' => 'Well designed and built to a plan: a crouched start, a coiled-up foot move, then a big jump across the overhang to a generous finishing hold.'],
            ['slug' => 'first-coat', 'grade' => 'VB', 'colour' => 'White', 'hex' => '#ffffff', 'wall' => 'Zone 1 – Slab', 'setter' => 'Owain', 'setOn' => '2026-11-10', 'ascents' => 96, 'rating' => 4.1, 'name' => 'First Coat', 'sponsor' => 'brwsh',
                'description' => 'The perfect first layer for new climbers. Big holds, a gentle angle and a smooth finish, and you will want to go back for a second coat.'],
            ['slug' => 'honeycomb', 'grade' => 'V3', 'colour' => 'Yellow', 'hex' => '#ecbe49', 'wall' => 'Zone 2 – Arête', 'setter' => 'Cat', 'setOn' => '2026-11-11', 'ascents' => 39, 'rating' => 4.5, 'name' => 'Honeycomb', 'sponsor' => 'wenyn',
                'description' => 'A sweet little problem built from a cluster of hexagonal holds up the arête. Slow and steady wins here: work the cells one at a time and keep a light touch.'],
            ['slug' => 'green-fingers', 'grade' => 'V2', 'colour' => 'Teal',   'hex' => '#1e9c96', 'wall' => 'Zone 2 – Slab', 'setter' => 'Cat',  'setOn' => '2026-10-09', 'ascents' => 134, 'rating' => 4.0, 'name' => 'Green Fingers', 'sponsor' => 'harvst',
                'description' => 'A delicate slab of small crimps and smears. Trust your feet, keep your weight over them and the route grows on you.'],
        ];
    }

    private static function roped(): array
    {
        return [
            ['slug' => 'dovetail', 'grade' => '6a+', 'colour' => 'Green', 'hex' => '#3c9a5f', 'wall' => 'Line 2', 'style' => 'Top rope', 'setter' => 'Cat', 'setOn' => '2026-11-06', 'ascents' => 47, 'rating' => 4.5, 'name' => 'Dovetail', 'sponsor' => 'madebybill',
                'description' => 'Interlocking moves that only fit together one way. Opposing side-pulls and a neat foot swap near the middle make a beautifully crafted line, so take your time and measure twice.'],
            ['slug' => 'extra-picante', 'grade' => '6c', 'colour' => 'Red', 'hex' => '#d6402f', 'wall' => 'Line 5', 'style' => 'Lead', 'setter' => 'Rhys', 'setOn' => '2026-11-06', 'ascents' => 29, 'rating' => 4.7, 'name' => 'Extra Picante', 'sponsor' => 'elsalsa',
                'description' => 'Starts mild and builds the heat clip by clip. A pumpy steep section near the top will have your forearms burning, so shake out, breathe, and save something for the finish.'],
            ['slug' => 'foundations', 'grade' => '6a', 'colour' => 'Grey', 'hex' => '#8a8a8a', 'wall' => 'Line 1', 'style' => 'Auto-belay', 'setter' => 'Owain', 'setOn' => '2026-11-10', 'ascents' => 66, 'rating' => 4.2, 'name' => 'Foundations', 'sponsor' => 'cardibuildingsupplies',
                'description' => 'Solid, blocky holds stacked like brickwork make this a great route to build your technique on. Get the basics right low down and the top goes up in no time.'],
            ['slug' => 'slow-burn', 'grade' => '6b+', 'colour' => 'Yellow', 'hex' => '#ecbe49', 'wall' => 'Line 4', 'style' => 'Top rope', 'setter' => 'Rhys', 'setOn' => '2026-11-10', 'ascents' => 34, 'rating' => 4.6, 'name' => 'Slow Burn', 'sponsor' => 'lofi',
                'description' => 'No single hard move, just a long, sustained climb that slowly turns up the heat. Keep things simple, climb efficiently and you will have enough fuel left for the top.'],
            ['slug' => 'wear-it-out', 'grade' => '6c+', 'colour' => 'Black', 'hex' => '#141414', 'wall' => 'Line 5', 'style' => 'Lead', 'setter' => 'Rhys', 'setOn' => '2026-11-10', 'ascents' => 22, 'rating' => 4.8, 'name' => 'Wear It Out', 'sponsor' => 'howies',
                'description' => 'Long, steep and built for endurance. It will test your stamina all the way to the chain, so pace yourself, clip efficiently and wear it out.'],
            ['slug' => 'heat-pump', 'grade' => '6b', 'colour' => 'Blue', 'hex' => '#2f6fd6', 'wall' => 'Line 3', 'style' => 'Top rope', 'setter' => 'Cat', 'setOn' => '2026-11-10', 'ascents' => 61, 'rating' => 4.3, 'name' => 'Heat Pump', 'sponsor' => 'hawen',
                'description' => 'Steady and efficient climbing that slowly builds the pump in your forearms. Find the rests, shake out and keep the energy flowing to the top.'],
            ['slug' => 'full-service', 'grade' => '6a+', 'colour' => 'Orange', 'hex' => '#ef8a2b', 'wall' => 'Line 2', 'style' => 'Top rope', 'setter' => 'Owain', 'setOn' => '2026-11-10', 'ascents' => 58, 'rating' => 4.3, 'name' => 'Full Service', 'sponsor' => 'bvrees',
                'description' => 'A reliable all-rounder that checks every part of your climbing: a bit of balance, a bit of power and a tricky gear change halfway up. Well maintained and running smoothly since it was set.'],
            ['slug' => 'the-beanpole', 'grade' => '6b',  'colour' => 'Purple', 'hex' => '#7a4cc2', 'wall' => 'Line 4', 'style' => 'Top rope', 'setter' => 'Owain','setOn' => '2026-09-28', 'ascents' => 72,  'rating' => 4.3, 'name' => 'The Beanpole', 'sponsor' => 'harvst',
                'description' => 'Tall, straight up, and full of long reaches between well-spaced holds. Shorter climbers will find a high foot or two that does the job.'],
            ['slug' => 'cast-on', 'grade' => '6a',  'colour' => 'Pink',   'hex' => '#e463a6', 'wall' => 'Line 3', 'style' => 'Top rope', 'setter' => 'Cat',  'setOn' => '2026-09-28', 'ascents' => 111, 'rating' => 4.1, 'name' => 'Cast On', 'sponsor' => 'craftcourses',
                'description' => 'A flowing line of pinches and side-pulls that weaves up the wall. Find the rhythm early and it comes together stitch by stitch.'],
        ];
    }
}
