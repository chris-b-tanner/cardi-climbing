# Developer guide
A PHP app built on Symfony to run a climbing wall.

## Stack
- PHP 8.2+ (extensions: ctype, gd, iconv), Symfony 7.2, Doctrine ORM, Twig.
- MySQL 8.0.
- Postmark for email, Stripe for payments (online and the S700 card reader), AWS S3 for file storage.
- No front-end build step: CSS and JS are plain files in `public/` and inline in templates.
- Hosted on Heroku, with the code on GitHub (`chris-b-tanner/cardi-climbing`).
- Staging at https://y-wal-staging-ce33a096620c.herokuapp.com

## Local development setup
You need PHP 8.2+, [Composer](https://getcomposer.org), MySQL, and ideally the
[Symfony CLI](https://symfony.com/download).

Assumes you have MySQL running on your machine. 

```bash
git clone git@github.com:chris-b-tanner/cardi-climbing.git
cd cardi-climbing
composer install
php bin/console doctrine:migrations:migrate --no-interaction
symfony serve -d                        # or: php -S localhost:8000 -t public
```

Then open http://localhost:8000.

### Configuration
- `.env` is committed and holds safe defaults for every setting the app reads. **Never put real
  secrets in `.env`.**
- Put local overrides and secrets in `.env.local` (gitignored). The defaults already match the
  Docker database, so a basic setup needs no overrides. Add Stripe test keys, S3 credentials and
  so on only when you're working on those features.
- On Heroku, every setting is a config var on the app (`heroku config -a <app>`). JawsDB sets
  `JAWSDB_URL` automatically.

### Your first admin account
Register through `/register`, then promote yourself in MySQL:

```bash
php bin/console dbal:run-sql "UPDATE user SET roles='[\"ROLE_ADMIN\"]' WHERE email='you@example.com'"
```

### Stripe locally
Use Stripe **test** keys in `.env.local`. The payment pages poll Stripe directly, so payments
complete without webhooks. To exercise the webhook too, run
`stripe listen --forward-to localhost:8000/webhook/stripe` and set `STRIPE_WEBHOOK_SECRET` to the
signing secret it prints.

## Database migrations
The schema is managed with Doctrine Migrations (files in `migrations/`). Never change the
database by hand.

1. Change the entity in `src/Entity/`.
2. Generate a migration: `php bin/console make:migration` (or `doctrine:migrations:diff`).
3. **Read the generated file.** Remove anything unrelated to your change, and add any data
   updates the change needs.
4. Apply it locally: `php bin/console doctrine:migrations:migrate`.
5. Commit the migration in the same PR as the entity change.

On Heroku, the `release` phase in the `Procfile` runs `doctrine:migrations:migrate` on every
deploy, before the new code goes live. Migrations must therefore be safe to run against live data.
If a migration fails, the release fails and the previous version keeps running.

## Email
- **Transactional email** (password resets, booking confirmations, receipts, certification
  emails and so on) is sent immediately through Postmark's transactional stream. Every email is
  built by a class in `src/Service/Mailer/`, with templates in `templates/email/`. Add new emails
  there, not inline in a controller.
- **Bulk email** (composed under Admin > Email) is queued with Symfony Messenger on the
  `bulk_email` transport (stored in the database). It is sent through Postmark's broadcast stream
  (`POSTMARK_BROADCAST_STREAM`), with one-click unsubscribe headers. On Heroku, a Scheduler job
  works through the queue:
  `php bin/console messenger:consume bulk_email --time-limit=280 --limit=100 --no-interaction`
- **Locally**, `MAILER_DSN=null://null` throws every email away. 
- Inbound email replies and open-tracking events arrive from Postmark at
  `/webhook/inbound/{WEBHOOK_SECRET}` and `/webhook/postmark-open/{WEBHOOK_SECRET}`.

## Testing
There is no CI yet, so **run the full test suite locally before opening a PR**, and again
before merging.

One-off setup of the test database (Doctrine adds a `_test` suffix to the database name):

```bash
php bin/console doctrine:database:create --env=test
php bin/console doctrine:migrations:migrate --env=test --no-interaction
```

After pulling new migrations, run the second command again. Then:

```bash
php bin/phpunit
```

In the test environment, email goes to a null transport and the tests never call Stripe.
`tests/README.md` describes how the tests are organised and how to write new ones: a test class
per controller, a shared base class, factories, and automatic cleanup. Every new controller or
feature should come with tests.

## Workflow and deployment
- `main` is always deployable. **Don't commit directly to `main`.**
- Work on a branch named for the change (e.g. `fix/booking-cancel-redirect`,
  `feature/waiting-list`), and open a pull request into `main` on GitHub.
- Keep PRs small and focused. Describe what changed and how you tested it, and include any
  migrations or new config vars.
- A PR needs a review before merging. Run the tests locally first (see above).
- **Staging** deploys automatically from every merge to `main` (Heroku pipeline). Check your
  change there.
- **Production** is a manual **Promote** from staging to production in the Heroku pipeline. Promote
  only once staging has been checked.
- New environment variables must be set as Heroku config vars on **both** staging and production
  **before** promoting, and given a safe default in `.env`.

# Functional guide for team members

## Team
There are three roles of people who can log in

- Users (climbers, event attendees etc)
- Team
- Admin

## Users / People
Everyone that the organisation interacts with, from members to suppliers.

### Families / dependents
A person can have *dependents*, which makes the group into a Family. 
Add dependents to the primary person (which can be changed if needed). 
A depedent can log in if they have their own email address but cannot manage bookings or memberships.
A primary can manage their dependents bookings online, and buy credits on behalf of a dependent.

### Memberships
A person can only have one active membership at any time. Memberships allow free 
access to events which have an access method of "Membership", while the membership is 
active (ie before expiry date)

#### Family memberships
A dependent inherits the membership of their primary, if the primary has a membership type which is marked as "family membership".

### Credits
Credits can be added to someone's account using a Sale. If someone books onto 
an event which has access method "Credit", then their credit balance is reduced by one 
for each booking. If a person has Membership *and* Credit, then if the Membership is a 
valid access method for that Event, their credits are not reduced when they book.

### Certifications / waivers / disclaimers
The system allows us to create as many Certification Types as we need. Examples may be:

- Standard Bouldering Waiver
- Self Access Induction
- Registered Climbing Instructor

Each certification type can be set for a duration, after which it needs to be refreshed (another one completed - or extended? TBC)

#### Certification flow
To give someone a certification, it has to be started by a team member. 

1. Find the person and "add certification". Choose the relevant one, the person 
will receive an email with a link to complete it online. 
2. They will need to fill out basic information (eg emergency contact details, address) 
to complete their profile before they can complete a certification. 
3. They will complete the disclaimers, sign and submit.
4. Team member opens the certification, completes any in-person checks (eg knots) and then approves.

#### Using reception kiosk/tablet for self-serve

## Events
All climbing, one-off events, courses etc are handled using Events.

### Recurring events
An event can be set to recur on a weekly basis, on certain days per week, 
until an end date. Each instance of a recurring event series has its own bookings, attendees etc. 
For monthly recurring events, create each instance separately.

### Pricing / tickets / event access methods
Events have "access method", which can be one (or more) of:

- Ticket
- Credits
- Membership

If an event is ticketed, then the price of that ticket is driven by the price of 
the *Ticket Product* which is linked to the event. An event can have multiple ticket 
products; each limited to a membership type, which allows a standard price (a ticket not linked to a membership type) 
and then discounted prices for members (of each membership type)

### Event access certifications
You can optionally limit the type of people who can book onto an event based on *certification*. 
For example:

- Out of hours self access events only bookable by people with `self-access` certification.
- Regular climbing only bookable by people who have completed the `standard climbing induction`.
- Volunteer building events only bookable by people with `build-volunteer` certification.

Note that "bookable" also means "can be checked in by admin" - which makes this method of 
controlling event attendees by certification our main waiver / disclaimer route.

### Bookings
To create a booking on an event, either the member can do it themselves via the website 
or admin can create the booking on their behalf.

### Self access
Events can be marked as "self access", which then presents information over API 
to the card readers, so that people with a confirmed booking on those events can open the self-access door.

### Instructors and event leaders

### Event leader rota / calendar


## Sales
Sales are used whenever you need to create a membership for someone, sell credits 
or items/services.

### Products
Products are of five types:

- Stock item
- Service item
- Credits
- Memberships
- Ticket

Credit and Membership products appear on the website shop area so that people can buy 
these themselves.

#### Stock items
Physical things we sell.

#### Service items
Coaching, or other "time" things that don't carry a stock level, but are not linked 
to event access.

#### Credit products
Sell these to add credit to a user account. Can be a single credit or bulk packs at a lower price.

#### Membership products
This is the thing you sell to add a membership to a person's account. 
The membership product needs to be linked to a specific membership type.
Create only one membership product for each membership type. 
To change the price of a membership (say for a discount) then do that in the sale.

#### Ticket products
Used to determine the price of an event which has access-method Ticket, eg
film nights, coached climbing session.

### Discounts
To discount items on a sale, click the price and enter a discount reason.

### Sale nominees
Someone can buy things for other people - mainly used for primary member in a family to book climbing and events for dependents.
Each ticket, membership or credit line on a sale needs to be nominated to someone. 
Service items and Stock items do not need a nominee.

## Emails
Transactional emails are sent immediately via PostMark Transactional stream, 
for bulk emails we use the PostMark Broadcast stream, with a queue system.

To consume the email queue:
`php bin/console messenger:consume bulk_email --time-limit=280 --limit=100 --no-interaction`

## Other