<?php
$site_name = "Cardigan Climbing";
$site_desc = "A community-led climbing and bouldering wall for Cardigan, Wales.";
?>
<!DOCTYPE html>
<html lang="en-GB">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title><?= htmlspecialchars($site_name) ?></title>
  <meta name="description" content="<?= htmlspecialchars($site_desc) ?>">

  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=Oswald:wght@600;700&family=Inter:wght@400;500&display=swap" rel="stylesheet">

  <!-- MailerLite Universal -->
  <script>
    (function(w,d,e,u,f,l,n){w[f]=w[f]||function(){(w[f].q=w[f].q||[])
    .push(arguments);},l=d.createElement(e),l.async=1,l.src=u,
    n=d.getElementsByTagName(e)[0],n.parentNode.insertBefore(l,n);})
    (window,document,'script','https://assets.mailerlite.com/js/universal.js','ml');
    ml('account', '2331086');
  </script>
  <!-- End MailerLite Universal -->

  <style>
    /* ── Tokens ─────────────────────────────────────────── */
    :root {
      --black:    #141414;
      --teal:     #2d6b5a;
      --teal-dk:  #1e4f41;
      --sand:     #c4a87a;
      --white:    #ffffff;
      --offwhite: #f6f6f3;
      --border:   #e4e2dc;
      --muted:    #5a5857;
      --max-w:    1140px;
      --f-head:   'Oswald', sans-serif;
      --f-body:   'Inter', sans-serif;
    }

    /* ── Reset ──────────────────────────────────────────── */
    *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }
    html { scroll-behavior: smooth; }
    body { font-family: var(--f-body); color: var(--black); background: var(--white); line-height: 1.7; }
    img { display: block; max-width: 100%; }
    a { color: inherit; text-decoration: none; }

    /* ── Layout ─────────────────────────────────────────── */
    .wrap     { max-width: var(--max-w); margin: 0 auto; padding: 0 1.5rem; }
    .section  { padding: 6rem 0; }
    .section--off { background: var(--offwhite); }

    /* ── Type ───────────────────────────────────────────── */
    .eyebrow {
      display: block;
      font-family: var(--f-head);
      font-size: 0.7rem;
      font-weight: 700;
      letter-spacing: 0.18em;
      text-transform: uppercase;
      color: var(--teal);
      margin-bottom: 1rem;
    }
    h2 {
      font-family: var(--f-head);
      font-size: clamp(2rem, 5vw, 3rem);
      font-weight: 700;
      text-transform: uppercase;
      letter-spacing: -0.01em;
      line-height: 1.05;
      margin-bottom: 1.5rem;
    }
    h3 {
      font-family: var(--f-head);
      font-size: 1rem;
      font-weight: 700;
      text-transform: uppercase;
      letter-spacing: 0.05em;
      margin-bottom: 0.5rem;
    }
    p { color: var(--muted); max-width: 62ch; line-height: 1.8; }
    p + p { margin-top: 1rem; }

    /* ── Buttons ────────────────────────────────────────── */
    .btn {
      display: inline-block;
      font-family: var(--f-head);
      font-size: 0.8rem;
      font-weight: 700;
      letter-spacing: 0.12em;
      text-transform: uppercase;
      padding: 0.85rem 2rem;
      border: 2px solid transparent;
      cursor: pointer;
      transition: background 0.18s, color 0.18s, border-color 0.18s;
    }
    .btn--teal  { background: var(--teal);  color: var(--white); border-color: var(--teal); }
    .btn--teal:hover { background: var(--teal-dk); border-color: var(--teal-dk); }
    .btn--outline { background: transparent; color: var(--black); border-color: var(--black); }
    .btn--outline:hover { background: var(--black); color: var(--white); }

    /* ── Nav ────────────────────────────────────────────── */
    .nav {
      position: fixed;
      top: 0; left: 0; right: 0;
      z-index: 100;
      background: var(--white);
      border-bottom: 1px solid transparent;
      transition: border-color 0.2s;
    }
    .nav.scrolled { border-color: var(--border); }
    .nav__inner {
      display: flex;
      align-items: center;
      justify-content: space-between;
      height: 64px;
    }
    .nav__logo {
      font-family: var(--f-head);
      font-size: 1.15rem;
      font-weight: 700;
      text-transform: uppercase;
      letter-spacing: 0.04em;
      color: var(--black);
    }
    .nav__logo span { color: var(--teal); }
    .nav__links {
      display: none;
      list-style: none;
      gap: 2.5rem;
    }
    @media (min-width: 640px) {
      .nav__links { display: flex; }
    }
    .nav__links a {
      font-size: 0.8rem;
      font-weight: 500;
      letter-spacing: 0.04em;
      color: var(--muted);
      transition: color 0.15s;
    }
    .nav__links a:hover { color: var(--black); }

    /* ── Hero ───────────────────────────────────────────── */
    .hero {
      padding: 10rem 0 5rem;
    }
    .hero__label {
      font-family: var(--f-head);
      font-size: 0.7rem;
      font-weight: 700;
      letter-spacing: 0.2em;
      text-transform: uppercase;
      color: var(--muted);
      margin-bottom: 1.5rem;
    }
    .hero h1 {
      font-family: var(--f-head);
      font-size: clamp(3rem, 10vw, 7rem);
      font-weight: 700;
      text-transform: uppercase;
      line-height: 0.95;
      letter-spacing: -0.02em;
      margin-bottom: 2rem;
      max-width: 12ch;
    }
    .hero h1 em { font-style: normal; color: var(--teal); }
    .hero__sub {
      font-size: 1.05rem;
      max-width: 48ch;
      color: var(--muted);
      margin-bottom: 2.5rem;
      line-height: 1.75;
    }
    .hero__actions { display: flex; gap: 1rem; flex-wrap: wrap; }

    .hero__signup { margin-top: 3rem; max-width: 100%; }
    .hero__signup-label {
      font-family: var(--f-head);
      font-size: clamp(1.25rem, 3vw, 1.75rem);
      font-weight: 700;
      letter-spacing: 0.04em;
      text-transform: uppercase;
      color: var(--black);
      margin-bottom: 1rem;
      max-width: none;
    }
    .hero__signup .ml-embedded { width: 100%; }
    .hero__signup .ml-embedded > div,
    .hero__signup .ml-embedded .ml-form-embedWrapper,
    .hero__signup .ml-embedded .ml-form-embedBody,
    .hero__signup .ml-embedded .ml-form-embedContent,
    .hero__signup .ml-embedded .ml-form-formContent,
    .hero__signup .ml-embedded .ml-block-form,
    .hero__signup .ml-embedded .ml-form-horizontalRow {
      margin-left: 0 !important;
      margin-right: auto !important;
      text-align: left !important;
      float: none !important;
      max-width: 100% !important;
      width: 100% !important;
    }
    .hero__signup .ml-embedded * { color: var(--black) !important; }

    /* ── Hero photo ─────────────────────────────────────── */
    .hero-photo {
      width: 100%;
      height: clamp(280px, 45vw, 560px);
      object-fit: cover;
      object-position: center 40%;
      display: block;
    }

    /* ── Stats ──────────────────────────────────────────── */
    .stats { background: var(--black); padding: 2.5rem 0; }
    .stats__grid {
      display: grid;
      grid-template-columns: repeat(auto-fit, minmax(140px, 1fr));
      gap: 1px;
      background: rgba(255,255,255,0.1);
    }
    .stats__item {
      background: var(--black);
      padding: 1.5rem 2rem;
      text-align: center;
    }
    .stats__item strong {
      display: block;
      font-family: var(--f-head);
      font-size: 2.25rem;
      font-weight: 700;
      color: var(--white);
      line-height: 1;
      margin-bottom: 0.35rem;
    }
    .stats__item span {
      font-size: 0.75rem;
      color: rgba(255,255,255,0.45);
      text-transform: uppercase;
      letter-spacing: 0.08em;
      font-weight: 500;
    }

    /* ── Mission ────────────────────────────────────────── */
    .mission__grid {
      display: grid;
      grid-template-columns: 1fr;
      gap: 4rem;
      align-items: center;
    }
    @media (min-width: 768px) {
      .mission__grid { grid-template-columns: 1fr 1fr; }
    }
    .mission__photo {
      width: 100%;
      height: 480px;
      object-fit: cover;
    }
    .mission__pillars {
      margin-top: 2.5rem;
      display: grid;
      grid-template-columns: 1fr 1fr;
      gap: 0;
      border: 1px solid var(--border);
    }
    .pillar {
      padding: 1.25rem;
      border-right: 1px solid var(--border);
      border-bottom: 1px solid var(--border);
    }
    .pillar:nth-child(even) { border-right: none; }
    .pillar:nth-child(3), .pillar:nth-child(4) { border-bottom: none; }
    .pillar h3 { font-size: 0.75rem; color: var(--black); margin-bottom: 0.35rem; }
    .pillar p  { font-size: 0.82rem; color: var(--muted); max-width: none; line-height: 1.6; }

    /* ── Plan ───────────────────────────────────────────── */
    .plan__intro { margin-bottom: 3.5rem; }
    .plan__intro p { font-size: 1.05rem; }
    .plan__steps {
      display: grid;
      grid-template-columns: repeat(auto-fit, minmax(230px, 1fr));
      gap: 0;
      border: 1px solid var(--border);
      margin-bottom: 2.5rem;
      background: var(--border);
    }
    .step {
      background: var(--white);
      padding: 2rem 1.75rem;
    }
    .step__num {
      font-family: var(--f-head);
      font-size: 0.7rem;
      font-weight: 700;
      letter-spacing: 0.15em;
      color: var(--teal);
      margin-bottom: 0.75rem;
    }
    .step h3 { color: var(--black); margin-bottom: 0.75rem; }
    .step p   { font-size: 0.9rem; max-width: none; }

    .plan__features {
      border: 1px solid var(--border);
      padding: 2.5rem;
      background: var(--white);
    }
    .plan__features h3 {
      font-size: 0.75rem;
      color: var(--muted);
      margin-bottom: 1.5rem;
      letter-spacing: 0.12em;
    }
    .feature-list {
      list-style: none;
      display: grid;
      grid-template-columns: repeat(auto-fit, minmax(220px, 1fr));
      gap: 0.6rem 3rem;
    }
    .feature-list li {
      font-size: 0.9rem;
      color: var(--muted);
      padding-left: 1.25rem;
      position: relative;
    }
    .feature-list li::before {
      content: '';
      position: absolute;
      left: 0;
      top: 0.6em;
      width: 6px;
      height: 6px;
      background: var(--teal);
    }

    /* ── Plan photo strip ───────────────────────────────── */
    .plan-photo {
      width: 100%;
      height: clamp(240px, 35vw, 480px);
      object-fit: cover;
      object-position: center 30%;
      display: block;
      margin-top: 2.5rem;
    }

    /* ── Get Involved ───────────────────────────────────── */
    .involved__ways {
      display: grid;
      grid-template-columns: repeat(auto-fit, minmax(260px, 1fr));
      gap: 2rem;
      margin-bottom: 5rem;
    }
    .way {
      border-top: 3px solid var(--teal);
      padding-top: 1.5rem;
    }
    .way h3 { color: var(--black); margin-bottom: 0.5rem; }
    .way p   { font-size: 0.9rem; }

    /* ── Signup ─────────────────────────────────────────── */
    .signup {
      background: var(--teal);
      padding: 5rem 0;
    }
    .signup .wrap {
      display: grid;
      grid-template-columns: 1fr;
      gap: 3rem;
      align-items: start;
    }
    @media (min-width: 768px) {
      .signup .wrap { grid-template-columns: 1fr 1fr; }
    }
    .signup__copy h2 { color: var(--white); }
    .signup__copy .eyebrow { color: rgba(255,255,255,0.5); }
    .signup__copy p { color: rgba(255,255,255,0.7); max-width: none; }
    .signup__form { padding-top: 0.25rem; }
    /* MailerLite form text — keep black against white form background */
    .signup__form .ml-embedded * { color: var(--black) !important; }

    /* ── Footer ─────────────────────────────────────────── */
    .footer { background: var(--black); padding: 4rem 0 2rem; }
    .footer__grid {
      display: grid;
      grid-template-columns: repeat(auto-fit, minmax(180px, 1fr));
      gap: 2.5rem;
      padding-bottom: 3rem;
      border-bottom: 1px solid rgba(255,255,255,0.08);
      margin-bottom: 2rem;
    }
    .footer__logo {
      font-family: var(--f-head);
      font-size: 1rem;
      font-weight: 700;
      text-transform: uppercase;
      letter-spacing: 0.04em;
      color: var(--white);
      margin-bottom: 0.75rem;
    }
    .footer__logo span { color: var(--teal); }
    .footer__col-p {
      font-size: 0.85rem;
      color: rgba(255,255,255,0.4);
      max-width: none;
      line-height: 1.6;
    }
    .footer__col h4 {
      font-family: var(--f-head);
      font-size: 0.65rem;
      font-weight: 700;
      letter-spacing: 0.15em;
      text-transform: uppercase;
      color: rgba(255,255,255,0.3);
      margin-bottom: 1rem;
    }
    .footer__col ul { list-style: none; }
    .footer__col ul li + li { margin-top: 0.5rem; }
    .footer__col ul a {
      font-size: 0.875rem;
      color: rgba(255,255,255,0.55);
      transition: color 0.15s;
    }
    .footer__col ul a:hover { color: var(--white); }
    .footer__bottom {
      display: flex;
      flex-wrap: wrap;
      justify-content: space-between;
      gap: 0.5rem;
      font-size: 0.8rem;
      color: rgba(255,255,255,0.25);
    }
  </style>
</head>
<body>

<!-- Nav ──────────────────────────────────────────────── -->
<nav class="nav" id="nav">
  <div class="wrap">
    <div class="nav__inner">
      <a href="#" class="nav__logo">Cardigan <span>Climbing</span></a>
      <ul class="nav__links">
        <li><a href="#mission">Mission</a></li>
        <li><a href="#plan">The Plan</a></li>
        <li><a href="#involved">Get Involved</a></li>
      </ul>
      <a href="#involved" class="btn btn--teal">Get Involved</a>
    </div>
  </div>
</nav>

<!-- Hero ─────────────────────────────────────────────── -->
<section class="hero" id="home">
  <div class="wrap">
    <p class="hero__label">Cardigan &middot; Aberteifi &middot; West Wales</p>
    <h1>Climb.<br><em>Connect.</em><br>Community.</h1>
    <p class="hero__sub">
      We are building a community-owned indoor climbing and bouldering wall
      in the heart of Cardigan — open to all ages, abilities, and backgrounds.
      Help us make it happen.
    </p>
    <div class="hero__actions">
      <a href="#involved" class="btn btn--teal">Get Involved</a>
      <a href="#mission" class="btn btn--outline">Our Mission</a>
    </div>

    <div class="hero__signup">
      <p class="hero__signup-label">Follow the project</p>
      <div class="ml-embedded" data-form="4M8VyN"></div>
    </div>
  </div>
</section>

<img
  src="images/Cardigan-Climbing-Wall.jpg"
  alt="Cardigan Climbing — exterior view of the community climbing centre"
  class="hero-photo"
>

<!-- Stats ────────────────────────────────────────────── -->
<div class="stats">
  <div class="wrap">
    <div class="stats__grid">
      <div class="stats__item">
        <strong>1st</strong>
        <span>Dedicated wall in Ceredigion</span>
      </div>
      <div class="stats__item">
        <strong>50+</strong>
        <span>Community supporters</span>
      </div>
      <div class="stats__item">
        <strong>10,000+</strong>
        <span>People within 30 miles</span>
      </div>
      <div class="stats__item">
        <strong>3</strong>
        <span>Schools to partner with</span>
      </div>
    </div>
  </div>
</div>

<!-- Mission ──────────────────────────────────────────── -->
<section class="section" id="mission">
  <div class="wrap">
    <div class="mission__grid">

      <div class="mission__text">
        <span class="eyebrow">Our Mission</span>
        <h2>Climbing should belong to everyone</h2>
        <p>
          Cardigan Climbing is a community-led initiative to bring an indoor climbing
          and bouldering facility to Cardigan and the wider West Wales region.
          The nearest dedicated climbing wall is over an hour's drive away —
          we want to change that.
        </p>
        <p>
          Our vision is an inclusive, affordable space where children, families,
          seasoned climbers and complete beginners can discover the joy of movement
          on the wall. Climbing builds confidence, physical strength, problem-solving
          skills, and genuine community bonds.
        </p>
        <p>
          Rooted in Cardigan's tradition of outdoor adventure and community spirit,
          the wall will be run as a not-for-profit — any surplus reinvested into
          programmes, subsidised access, and the facility itself.
        </p>

        <div class="mission__pillars">
          <div class="pillar">
            <h3>Accessible to All</h3>
            <p>Subsidised passes and equipment hire so that cost is never a barrier to participation.</p>
          </div>
          <div class="pillar">
            <h3>Schools &amp; Youth</h3>
            <p>Dedicated youth programmes, curriculum-linked sessions, and after-school clubs.</p>
          </div>
          <div class="pillar">
            <h3>Rooted in Place</h3>
            <p>Community-owned and governed, complementing the crags and sea cliffs on our doorstep.</p>
          </div>
          <div class="pillar">
            <h3>Not-for-Profit</h3>
            <p>Surplus goes back into the facility and subsidised access — not shareholders.</p>
          </div>
        </div>
      </div>

      <img
        src="images/Cardigan-Boundering-Wall.jpg"
        alt="The bouldering area inside Cardigan Climbing"
        class="mission__photo"
      >

    </div>
  </div>
</section>

<!-- Plan ─────────────────────────────────────────────── -->
<section class="section section--off" id="plan">
  <div class="wrap">
    <div class="plan__intro">
      <span class="eyebrow">The Plan</span>
      <h2>From Vision<br>to Reality</h2>
      <p>
        We have a clear, phased roadmap to take Cardigan Climbing from community
        ambition to open doors. Here is where we are and where we are heading.
      </p>
    </div>

    <div class="plan__steps">
      <div class="step">
        <p class="step__num">PHASE 01</p>
        <h3>Community &amp; Feasibility</h3>
        <p>
          Building the founding team, conducting a community needs survey, and
          identifying suitable premises in or near Cardigan town centre.
          <strong>In progress.</strong>
        </p>
      </div>
      <div class="step">
        <p class="step__num">PHASE 02</p>
        <h3>Funding &amp; Legal Structure</h3>
        <p>
          Securing a mix of grants, community shares, and private investment.
          Establishing a Community Benefit Society to hold the asset for the long term.
        </p>
      </div>
      <div class="step">
        <p class="step__num">PHASE 03</p>
        <h3>Build &amp; Fit-Out</h3>
        <p>
          The build and fit-out will be completed entirely by the community, with
          specialist advice from qualified climbing guides and inspiration from
          other community bouldering walls.
        </p>
      </div>
      <div class="step">
        <p class="step__num">PHASE 04</p>
        <h3>Open the Doors</h3>
        <p>
          Launch with a full programme of sessions, courses, and community events.
          Reinvesting surplus into subsidised access from day one.
        </p>
      </div>
    </div>

    <div class="plan__features">
      <h3>What the wall will include</h3>
      <ul class="feature-list">
        <li>Bouldering area for all skill levels</li>
<li>Equipment hire (harnesses, shoes, chalk)</li>
        <li>Dedicated junior training space</li>
        <li>Changing rooms and accessible facilities</li>
        <li>Cafe and social area</li>
        <li>Flexible school and group booking</li>
        <li>Coaching and instructed sessions</li>
        <li>Day passes, memberships, and block booking</li>
      </ul>
    </div>

    <img
      src="images/Cardigan-Climbing-Centre.jpg"
      alt="The tall climbing wall inside Cardigan Climbing Centre"
      class="plan-photo"
    >
  </div>
</section>

<!-- Get Involved ─────────────────────────────────────── -->
<section class="section" id="involved">
  <div class="wrap">
    <span class="eyebrow">Get Involved</span>
    <h2>Be Part of It</h2>

    <div class="involved__ways">
      <div class="way">
        <h3>Volunteer</h3>
        <p>
          We need people with all kinds of skills — from fundraising and design to
          project management, construction, and community outreach.
          Every hour counts.
        </p>
      </div>
      <div class="way">
        <h3>Support Us</h3>
        <p>
          When our community share offer launches you will be able to invest directly
          in the wall and become a co-owner. Sign up below to hear first.
        </p>
      </div>
      <div class="way">
        <h3>Spread the Word</h3>
        <p>
          Tell your friends, share our story with local businesses, and speak to
          us about partnership opportunities, sponsorship, or in-kind support.
        </p>
      </div>
    </div>
  </div>
</section>

<!-- Signup ───────────────────────────────────────────── -->
<div class="signup">
  <div class="wrap">
    <div class="signup__copy">
      <span class="eyebrow">Stay in the loop</span>
      <h2>Get Updates</h2>
      <p>
        Sign up for project news, community events, and be the first to hear
        when our share offer goes live.
      </p>
    </div>
    <div class="signup__form">
      <div class="ml-embedded" data-form="hfBS2G"></div>
    </div>
  </div>
</div>

<!-- Footer ───────────────────────────────────────────── -->
<footer class="footer">
  <div class="wrap">
    <div class="footer__grid">
      <div class="footer__col">
        <div class="footer__logo">Cardigan <span>Climbing</span></div>
        <p class="footer__col-p">A community climbing and bouldering wall for Cardigan, Wales.</p>
      </div>
      <div class="footer__col">
        <h4>Navigate</h4>
        <ul>
          <li><a href="#mission">Our Mission</a></li>
          <li><a href="#plan">The Plan</a></li>
          <li><a href="#involved">Get Involved</a></li>
        </ul>
      </div>
      <div class="footer__col">
        <h4>Contact</h4>
        <ul>
          <!-- TODO: Replace with your contact details -->
          <li><a href="mailto:cardiganclimbing@gmail.com">cardiganclimbing@gmail.com</a></li>
          <li>Cardigan, Ceredigion</li>
          <li>Wales, SA43</li>
        </ul>
      </div>
      <!-- Social links: unhide once profiles are live
      <div class="footer__col">
        <h4>Follow Us</h4>
        <ul>
          <li><a href="#">Facebook</a></li>
          <li><a href="#">Instagram</a></li>
          <li><a href="#">Twitter / X</a></li>
        </ul>
      </div>
      -->
    </div>
    <div class="footer__bottom">
      <span>&copy; <?= date('Y') ?> Cardigan Climbing. All rights reserved.</span>
      <span>Cardigan &middot; Aberteifi &middot; Wales</span>
    </div>
  </div>
</footer>

<script>
  const nav = document.getElementById('nav');
  window.addEventListener('scroll', () => {
    nav.classList.toggle('scrolled', window.scrollY > 20);
  });
</script>

</body>
</html>
