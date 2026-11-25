<?php

namespace App\Controller\Web;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * The people behind Y Wal. Deliberately not linked from the site or listed in the sitemap yet, and
 * marked noindex in the template, until the bios are in. Roles are translation keys; a bio is
 * plain text (null hides it).
 */
class TeamController extends AbstractController
{
    private const FOUNDERS = [
        ['name' => 'Mark Thomas',  'roles' => ['team_role_mountain_guide', 'team_role_health_safety', 'team_role_coaching'], 'bio' => null],
        ['name' => 'Chris Tanner', 'roles' => ['team_role_operations', 'team_role_memberships'], 'bio' => null],
        ['name' => 'Duncan Parker', 'roles' => ['team_role_volunteers', 'team_role_pr'], 'bio' => null],
    ];

    private const STEERING_GROUP = [
        ['name' => 'Lola Morgan', 'roles' => ['team_role_marketing'], 'bio' => null],
        ['name' => 'Ben James', 'roles' => ['team_role_culture'], 'bio' => null],
        ['name' => 'Owen Jones', 'roles' => ['team_role_youth'], 'bio' => null],
        ['name' => 'Jasmine Thomas', 'roles' => ['team_role_youth'], 'bio' => null],
        ['name' => 'Kačka Lorencová', 'roles' => ['team_role_schools'], 'bio' => null],
        ['name' => 'Fiona & Piers Partridge', 'roles' => ['team_role_strategy'], 'bio' => null],
        ['name' => 'Mark Chapple', 'roles' => ['team_role_governance'], 'bio' => null],
        ['name' => 'Steve Fawdry', 'roles' => ['team_role_environment'], 'bio' => null],
        ['name' => 'Richard Kinsey', 'roles' => ['team_role_finance'], 'bio' => null],
        ['name' => 'Simon Rees', 'roles' => ['team_role_premises'], 'bio' => null],
    ];

    #[Route('/team', name: 'app_team')]
    public function index(): Response
    {
        return $this->render('team/index.html.twig', [
            'founders'      => self::FOUNDERS,
            'steeringGroup' => self::STEERING_GROUP,
        ]);
    }
}
