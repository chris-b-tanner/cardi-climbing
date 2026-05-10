<?php
// Redirect HTTP to HTTPS on Heroku
if (isset($_SERVER['HTTP_X_FORWARDED_PROTO']) && $_SERVER['HTTP_X_FORWARDED_PROTO'] !== 'https') {
    header('Location: https://' . $_SERVER['HTTP_HOST'] . $_SERVER['REQUEST_URI'], true, 301);
    exit;
}

// Language detection
$lang     = (isset($_GET['lang']) && $_GET['lang'] === 'cy') ? 'cy' : 'en';
$html_lang = ($lang === 'cy') ? 'cy' : 'en-GB';
$site_url  = 'https://www.cardiganclimbing.org';
$og_image  = $site_url . '/images/Cardigan-Climbing-Wall.jpg';

// All translations — t() returns raw HTML-safe strings (hardcoded, not user input)
$t = [
  'en' => [
    'meta_title'       => 'Cardigan Climbing — Climb. Connect. Community.',
    'meta_desc'        => 'A community-led indoor climbing and bouldering wall coming to Cardigan, Wales. Open to all ages and abilities. Follow the project and help us make it happen.',
    'meta_keywords'    => 'climbing wall, bouldering, Cardigan, Aberteifi, Ceredigion, West Wales, community climbing, indoor climbing, youth climbing',
    'og_locale'        => 'en_GB',
    'nav_logo'         => 'Cardigan <span>Climbing</span>',
    'nav_mission'      => 'Mission',
    'nav_plan'         => 'The Plan',
    'nav_involved'     => 'Get Involved',
    'nav_cta'          => 'Get Involved',
    'hero_label'       => 'Cardigan &middot; Aberteifi &middot; West Wales',
    'hero_h1'          => 'Climb.<br><em>Connect.</em><br>Community.',
    'hero_sub'         => 'We are building a community-owned indoor climbing and bouldering wall in the heart of Cardigan &#8212; open to all ages, abilities, and backgrounds. Help us make it happen.',
    'hero_cta_primary' => 'Get Involved',
    'hero_cta_secondary' => 'Our Mission',
    'hero_signup_label' => 'Follow the project',
    'stat_1_label'     => 'Dedicated wall in Ceredigion',
    'stat_2_label'     => 'Community supporters',
    'stat_3_label'     => 'People within 30 miles',
    'stat_4_label'     => 'Schools to partner with',
    'mission_eyebrow'  => 'Our Mission',
    'mission_h2'       => 'Climbing should belong to everyone',
    'mission_p1'       => 'Cardigan Climbing is a community-led initiative to bring an indoor climbing and bouldering facility to Cardigan and the wider West Wales region. The nearest dedicated climbing wall is over an hour&#8217;s drive away &#8212; we want to change that.',
    'mission_p2'       => 'Our vision is an inclusive, affordable space where children, families, seasoned climbers and complete beginners can discover the joy of movement on the wall. Climbing builds confidence, physical strength, problem-solving skills, and genuine community bonds.',
    'mission_p3'       => 'Rooted in Cardigan&#8217;s tradition of outdoor adventure and community spirit, the wall will be run as a not-for-profit &#8212; any surplus reinvested into programmes, subsidised access, and the facility itself.',
    'mission_cta'      => 'See the Plan',
    'pillar_1_title'   => 'Accessible to All',
    'pillar_1_body'    => 'Subsidised passes and equipment hire so that cost is never a barrier to participation.',
    'pillar_2_title'   => 'Schools &amp; Youth',
    'pillar_2_body'    => 'Dedicated youth programmes, curriculum-linked sessions, and after-school clubs.',
    'pillar_3_title'   => 'Rooted in Place',
    'pillar_3_body'    => 'Community-owned and governed, complementing the crags and sea cliffs on our doorstep.',
    'pillar_4_title'   => 'Not-for-Profit',
    'pillar_4_body'    => 'Surplus goes back into the facility and subsidised access &#8212; not shareholders.',
    'plan_eyebrow'     => 'The Plan',
    'plan_h2'          => 'From Vision<br>to Reality',
    'plan_intro'       => 'We have a clear, phased roadmap to take Cardigan Climbing from community ambition to open doors. Here is where we are and where we are heading.',
    'phase_label'      => 'PHASE',
    'phase_1_title'    => 'Community &amp; Feasibility',
    'phase_1_body'     => 'Building the founding team, conducting a community needs survey, and identifying suitable premises in or near Cardigan town centre. <strong>In progress.</strong>',
    'phase_2_title'    => 'Funding &amp; Legal Structure',
    'phase_2_body'     => 'Securing a mix of grants, community shares, and private investment. Establishing a Community Benefit Society to hold the asset for the long term.',
    'phase_3_title'    => 'Build &amp; Fit-Out',
    'phase_3_body'     => 'The build and fit-out will be completed entirely by the community, with specialist advice from qualified climbing guides and inspiration from other community bouldering walls.',
    'phase_4_title'    => 'Open the Doors',
    'phase_4_body'     => 'Launch with a full programme of sessions, courses, and community events. Reinvesting surplus into subsidised access from day one.',
    'features_title'   => 'What the wall will include',
    'feature_1'        => 'Bouldering area for all skill levels',
    'feature_2'        => 'Equipment hire (harnesses, shoes, chalk)',
    'feature_3'        => 'Dedicated junior training space',
    'feature_4'        => 'Changing rooms and accessible facilities',
    'feature_5'        => 'Cafe and social area',
    'feature_6'        => 'Flexible school and group booking',
    'feature_7'        => 'Coaching and instructed sessions',
    'feature_8'        => 'Day passes, memberships, and block booking',
    'involved_eyebrow' => 'Get Involved',
    'involved_h2'      => 'Be Part of It',
    'way_1_title'      => 'Volunteer',
    'way_1_body'       => 'We need people with all kinds of skills &#8212; from fundraising and design to project management, construction, and community outreach. Every hour counts.',
    'way_2_title'      => 'Support Us',
    'way_2_body'       => 'When our fundraiser launches we&#8217;d love you to contribute, and share the news! Sign up below to hear first.',
    'way_3_title'      => 'Spread the Word',
    'way_3_body'       => 'Tell your friends, share our story with local businesses, and speak to us about partnership opportunities, sponsorship, or in-kind support.',
    'signup_eyebrow'   => 'Stay in the loop',
    'signup_h2'        => 'Get Updates',
    'signup_body'      => 'Sign up for project news, community events, and be the first to hear when our fundraiser goes live.',
    'footer_about'     => 'A community climbing and bouldering wall for Cardigan, Wales.',
    'footer_nav'       => 'Navigate',
    'footer_contact'   => 'Contact',
    'footer_nav_mission'  => 'Our Mission',
    'footer_nav_plan'     => 'The Plan',
    'footer_nav_involved' => 'Get Involved',
    'footer_address_1' => 'Cardigan, Ceredigion',
    'footer_address_2' => 'Wales, SA43',
    'footer_rights'    => 'All rights reserved.',
    'footer_tagline'   => 'Cardigan &middot; Aberteifi &middot; Wales',
    'img_alt_exterior' => 'Cardigan Climbing &#8212; exterior view of the community climbing centre',
    'img_alt_bouldering' => 'The bouldering area inside Cardigan Climbing',
    'img_alt_centre'   => 'A group learning to climb at Cardigan Climbing Centre',
    'cgi_watermark'    => 'CGI \2014  our vision for the space',
    'nav_adventures'   => 'Adventures',
    'adv_eyebrow'      => 'Adventure Hub',
    'adv_h2'           => 'Your Gateway to West Wales Adventure',
    'adv_intro'        => 'Cardigan Climbing is more than a wall. We are building a hub for outdoor adventure &#8212; connecting visitors and locals with world-class activities on one of Britain&#8217;s most spectacular coastlines. Book directly with us or we&#8217;ll put you in touch with our trusted local partners.',
    'adv_guided_title' => 'Private Guiding',
    'adv_1_title'      => 'Coasteering',
    'adv_1_body'       => 'Navigate sea caves, jump from rocks, and swim through gullies on the Pembrokeshire and Ceredigion coast with qualified guides.',
    'adv_2_title'      => 'Guided Climbing',
    'adv_2_body'       => 'Take your skills outdoors onto the dramatic sea cliffs and crags of West Wales with our experienced mountain guides.',
    'adv_3_title'      => 'Mountain Biking',
    'adv_3_body'       => 'Explore the trails, lanes, and moorland of the Cambrian Mountains and Preseli Hills on two wheels &#8212; guided or self-guided.',
    'adv_partners_title' => 'Adventure Partners',
    'adv_partners_intro' => 'We work closely with a hand-picked group of local specialists so we can point you towards &#8212; or book directly with &#8212; the very best adventure experiences West Wales has to offer.',
    'adv_cta'          => 'Planning a group visit, school trip, or adventure holiday? Get in touch and we&#8217;ll build your itinerary.',
    'adv_cta_btn'      => 'Get in Touch',
  ],
  'cy' => [
    'meta_title'       => 'Dringo Aberteifi — Dringo. Cysylltu. Cymuned.',
    'meta_desc'        => 'Wal ddringo dan do dan arweiniad y gymuned yn dod i Aberteifi, Cymru. Agored i bob oedran a gallu. Dilynwch y prosiect a helpwch ni i wireddu\'r nod.',
    'meta_keywords'    => 'wal ddringo, bouldering, Aberteifi, Cardigan, Ceredigion, Gorllewin Cymru, dringo cymunedol, dringo dan do, dringo ieuenctid',
    'og_locale'        => 'cy_GB',
    'nav_logo'         => 'Dringo <span>Aberteifi</span>',
    'nav_mission'      => 'Cenhadaeth',
    'nav_plan'         => 'Y Cynllun',
    'nav_involved'     => 'Cymerwch Ran',
    'nav_cta'          => 'Cymerwch Ran',
    'hero_label'       => 'Aberteifi &middot; Cardigan &middot; Gorllewin Cymru',
    'hero_h1'          => 'Dringo.<br><em>Cysylltu.</em><br>Cymuned.',
    'hero_sub'         => 'Rydym yn adeiladu wal ddringo dan do sy&#8217;n eiddo i&#8217;r gymuned yng nghanol Aberteifi &#8212; agored i bob oedran, gallu, a chefndir. Helpwch ni i wireddu&#8217;r nod.',
    'hero_cta_primary' => 'Cymerwch Ran',
    'hero_cta_secondary' => 'Ein Cenhadaeth',
    'hero_signup_label' => 'Dilynwch y Prosiect',
    'stat_1_label'     => 'Wal bwrpasol gyntaf Ceredigion',
    'stat_2_label'     => 'Cefnogwyr cymunedol',
    'stat_3_label'     => 'Pobl o fewn 30 milltir',
    'stat_4_label'     => 'Ysgol i bartneru â nhw',
    'mission_eyebrow'  => 'Ein Cenhadaeth',
    'mission_h2'       => 'Dylai dringo berthyn i bawb',
    'mission_p1'       => 'Mae Dringo Aberteifi yn fenter dan arweiniad y gymuned i ddod â chyfleuster dringo dan do i Aberteifi a rhanbarth ehangach Gorllewin Cymru. Mae&#8217;r wal ddringo agosaf dros awr o daith car i ffwrdd &#8212; rydym am newid hynny.',
    'mission_p2'       => 'Ein gweledigaeth yw gofod cynhwysol, fforddiadwy lle gall plant, teuluoedd, dringwyr profiadol a dechreuwyr hollol ddarganfod llawenydd symud ar y wal. Mae dringo&#8217;n meithrin hyder, cryfder corfforol, sgiliau datrys problemau, a chysylltiadau cymunedol gwirioneddol.',
    'mission_p3'       => 'Wedi&#8217;i wreiddio yn nhraddodiad Aberteifi o antur awyr agored ac ysbryd cymunedol, bydd y wal yn cael ei rhedeg fel sefydliad di-elw &#8212; unrhyw warged yn cael ei ailfuddsoddi mewn rhaglenni, mynediad â chymhorthdal, a&#8217;r cyfleuster ei hun.',
    'mission_cta'      => 'Gweld y Cynllun',
    'pillar_1_title'   => 'Hygyrch i Bawb',
    'pillar_1_body'    => 'Tocynnau â chymhorthdal a llogi offer fel nad yw cost byth yn rhwystr i gyfranogi.',
    'pillar_2_title'   => 'Ysgolion a Phobl Ifanc',
    'pillar_2_body'    => 'Rhaglenni ieuenctid pwrpasol, sesiynau â chwricwlwm, a chlybiau ar ôl ysgol.',
    'pillar_3_title'   => 'Wedi&#8217;i Wreiddio yn y Lle',
    'pillar_3_body'    => 'Yn eiddo i&#8217;r gymuned ac yn cael ei lywodraethu ganddi, yn ategu&#8217;r creigiau a chlogwyni môr ar ein drws.',
    'pillar_4_title'   => 'Di-elw',
    'pillar_4_body'    => 'Mae gwarged yn mynd yn ôl i&#8217;r cyfleuster a mynediad â chymhorthdal &#8212; nid cyfranddalwyr.',
    'plan_eyebrow'     => 'Y Cynllun',
    'plan_h2'          => 'O Weledigaeth<br>i Realiti',
    'plan_intro'       => 'Mae gennym ffordd glir, raddedig i fynd â Dringo Aberteifi o uchelgais cymunedol i agor y drysau. Dyma lle rydym ac i ble rydym yn mynd.',
    'phase_label'      => 'CAM',
    'phase_1_title'    => 'Cymuned ac Ymarferoldeb',
    'phase_1_body'     => 'Adeiladu&#8217;r tîm sylfaenol, cynnal arolwg anghenion cymunedol, ac adnabod eiddo addas yng nghanol tref Aberteifi neu yn ei ymyl. <strong>Ar y gweill.</strong>',
    'phase_2_title'    => 'Cyllido a Strwythur Cyfreithiol',
    'phase_2_body'     => 'Sicrhau cymysgedd o grantiau, cyfranddaliadau cymunedol, a buddsoddiad preifat. Sefydlu Cymdeithas Budd Cymunedol i ddal yr ased yn y tymor hir.',
    'phase_3_title'    => 'Adeiladu a Ffitio Allan',
    'phase_3_body'     => 'Bydd yr adeiladu a&#8217;r ffitio allan yn cael ei gwblhau&#8217;n gyfan gwbl gan y gymuned, gyda chyngor arbenigol gan hyfforddwyr dringo cymwysedig ac ysbrydoliaeth gan waliau bouldering cymunedol eraill.',
    'phase_4_title'    => 'Agor y Drysau',
    'phase_4_body'     => 'Lansio gyda rhaglen lawn o sesiynau, cyrsiau, a digwyddiadau cymunedol. Ailfuddsoddi gwarged mewn mynediad â chymhorthdal o&#8217;r diwrnod cyntaf.',
    'features_title'   => 'Beth fydd y wal yn ei gynnwys',
    'feature_1'        => 'Ardal bouldering ar gyfer pob lefel sgiliau',
    'feature_2'        => 'Llogi offer (harneisiau, esgidiau, sialc)',
    'feature_3'        => 'Gofod hyfforddi iau pwrpasol',
    'feature_4'        => 'Ystafelloedd newid a chyfleusterau hygyrch',
    'feature_5'        => 'Caffi ac ardal gymdeithasol',
    'feature_6'        => 'Archebu hyblyg i ysgolion a grwpiau',
    'feature_7'        => 'Sesiynau hyfforddi a chyfarwyddyd',
    'feature_8'        => 'Tocynnau dydd, aelodaethau, ac archebu bloc',
    'involved_eyebrow' => 'Cymerwch Ran',
    'involved_h2'      => 'Byddwch yn Rhan ohono',
    'way_1_title'      => 'Gwirfoddoli',
    'way_1_body'       => 'Rydym angen pobl â phob math o sgiliau &#8212; o godi arian a dylunio i reoli prosiectau, adeiladu, ac allgymorth cymunedol. Mae pob awr yn cyfrif.',
    'way_2_title'      => 'Cefnogwch Ni',
    'way_2_body'       => 'Pan fydd ein hymgyrch godi arian yn lansio, byddem wrth ein bodd pe baech yn cyfrannu a rhannu&#8217;r newyddion! Cofrestrwch isod i fod y cyntaf i glywed.',
    'way_3_title'      => 'Lledaenwch y Gair',
    'way_3_body'       => 'Dywedwch wrth eich ffrindiau, rhannwch ein stori gyda busnesau lleol, a siaradwch â ni am gyfleoedd partneriaeth, nawdd, neu gefnogaeth mewn nwyddau.',
    'signup_eyebrow'   => 'Cadwch mewn Cysylltiad',
    'signup_h2'        => 'Derbyn Diweddariadau',
    'signup_body'      => 'Cofrestrwch ar gyfer newyddion y prosiect, digwyddiadau cymunedol, a byddwch y cyntaf i glywed pan fydd ein hymgyrch godi arian yn mynd yn fyw.',
    'footer_about'     => 'Wal ddringo a bouldering gymunedol ar gyfer Aberteifi, Cymru.',
    'footer_nav'       => 'Llywio',
    'footer_contact'   => 'Cyswllt',
    'footer_nav_mission'  => 'Ein Cenhadaeth',
    'footer_nav_plan'     => 'Y Cynllun',
    'footer_nav_involved' => 'Cymerwch Ran',
    'footer_address_1' => 'Aberteifi, Ceredigion',
    'footer_address_2' => 'Cymru, SA43',
    'footer_rights'    => 'Cedwir pob hawl.',
    'footer_tagline'   => 'Aberteifi &middot; Cardigan &middot; Cymru',
    'img_alt_exterior' => 'Dringo Aberteifi &#8212; golwg allanol ar y ganolfan ddringo gymunedol',
    'img_alt_bouldering' => 'Yr ardal bouldering yn Nringo Aberteifi',
    'img_alt_centre'   => 'Grŵp yn dysgu dringo yng Nghanolfan Ddringo Aberteifi',
    'cgi_watermark'    => 'CGI \2014  ein gweledigaeth ar gyfer y gofod',
    'nav_adventures'   => 'Anturiaethau',
    'adv_eyebrow'      => 'Hwb Antur',
    'adv_h2'           => 'Eich Porth i Antur Gorllewin Cymru',
    'adv_intro'        => 'Mae Dringo Aberteifi yn fwy na wal. Rydym yn adeiladu hwb ar gyfer antur awyr agored &#8212; yn cysylltu ymwelwyr a phobl leol â gweithgareddau o&#8217;r radd flaenaf ar hyd un o arfordiroedd mwyaf trawiadol Prydain. Archebwch yn uniongyrchol gyda ni neu byddwn yn cysylltu chi â&#8217;n partneriaid lleol dibynadwy.',
    'adv_guided_title' => 'Tywys Preifat',
    'adv_1_title'      => 'Arfordira',
    'adv_1_body'       => 'Archwilio ogofâu môr, neidio oddi ar greigiau, a nofio trwy fylchau ar arfordir Penfro a Cheredigion gyda thywysyddion cymwysedig.',
    'adv_2_title'      => 'Dringo dan Arweiniad',
    'adv_2_body'       => 'Ewch â&#8217;ch sgiliau i&#8217;r awyr agored ar glogwyni môr a chreigiau dramatig Gorllewin Cymru gyda&#8217;n tywysyddion mynydd profiadol.',
    'adv_3_title'      => 'Beicio Mynydd',
    'adv_3_body'       => 'Archwilio llwybrau, lonydd, a rhostir Mynyddoedd Cambria a Bryniau&#8217;r Preseli ar ddwy olwyn &#8212; gyda thywysydd neu&#8217;n annibynnol.',
    'adv_partners_title' => 'Partneriaid Antur',
    'adv_partners_intro' => 'Rydym yn gweithio&#8217;n agos gyda grŵp dethol o arbenigwyr lleol fel y gallwn eich cyfeirio at &#8212; neu archebu&#8217;n uniongyrchol gyda &#8212; y profiadau antur gorau sydd gan Orllewin Cymru i&#8217;w cynnig.',
    'adv_cta'          => 'Yn cynllunio ymweliad grŵp, taith ysgol, neu wyliau antur? Cysylltwch â ni a byddwn yn adeiladu eich rhaglen.',
    'adv_cta_btn'      => 'Cysylltwch â Ni',
  ],
];

function t($key) {
  global $t, $lang;
  return $t[$lang][$key] ?? $t['en'][$key] ?? $key;
}
?>
<!DOCTYPE html>
<html lang="<?= $html_lang ?>">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">

  <title><?= htmlspecialchars(strip_tags(t('meta_title'))) ?></title>
  <meta name="description" content="<?= htmlspecialchars(strip_tags(t('meta_desc'))) ?>">
  <meta name="keywords"    content="<?= htmlspecialchars(t('meta_keywords')) ?>">
  <meta name="robots"      content="index, follow">
  <link rel="canonical"    href="<?= htmlspecialchars($site_url) ?>">

  <!-- hreflang -->
  <link rel="alternate" hreflang="en-GB"    href="<?= $site_url ?>/">
  <link rel="alternate" hreflang="cy"       href="<?= $site_url ?>/?lang=cy">
  <link rel="alternate" hreflang="x-default" href="<?= $site_url ?>/">

  <!-- Open Graph -->
  <meta property="og:type"        content="website">
  <meta property="og:url"         content="<?= htmlspecialchars($site_url) ?>">
  <meta property="og:title"       content="<?= htmlspecialchars(strip_tags(t('meta_title'))) ?>">
  <meta property="og:description" content="<?= htmlspecialchars(strip_tags(t('meta_desc'))) ?>">
  <meta property="og:image"       content="<?= htmlspecialchars($og_image) ?>">
  <meta property="og:locale"      content="<?= t('og_locale') ?>">
  <meta property="og:site_name"   content="Cardigan Climbing">

  <!-- Twitter Card -->
  <meta name="twitter:card"        content="summary_large_image">
  <meta name="twitter:title"       content="<?= htmlspecialchars(strip_tags(t('meta_title'))) ?>">
  <meta name="twitter:description" content="<?= htmlspecialchars(strip_tags(t('meta_desc'))) ?>">
  <meta name="twitter:image"       content="<?= htmlspecialchars($og_image) ?>">

  <!-- JSON-LD Structured Data -->
  <script type="application/ld+json">
  {
    "@context": "https://schema.org",
    "@type": "SportsActivityLocation",
    "name": "Cardigan Climbing",
    "description": "<?= addslashes(strip_tags(t('meta_desc'))) ?>",
    "url": "<?= $site_url ?>",
    "email": "cardiganclimbing@gmail.com",
    "address": {
      "@type": "PostalAddress",
      "addressLocality": "Cardigan",
      "addressRegion": "Ceredigion",
      "addressCountry": "GB"
    },
    "geo": {
      "@type": "GeoCoordinates",
      "latitude": "52.0833",
      "longitude": "-4.6570"
    },
    "sport": "Rock Climbing",
    "currenciesAccepted": "GBP",
    "openingHoursSpecification": [],
    "sameAs": []
  }
  </script>

  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=Barlow+Condensed:wght@600;700&family=Inter:wght@400;500&display=swap" rel="stylesheet">

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
      --black:    #0d1b2a;
      --teal:     #e55934;
      --teal-dk:  #cc3f1f;
      --sand:     #c4a87a;
      --white:    #ffffff;
      --offwhite: #f4f6f8;
      --border:   #dde3ea;
      --muted:    #566778;
      --max-w:    1140px;
      --f-head:   'Barlow Condensed', sans-serif;
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
    .btn--teal    { background: var(--teal);  color: var(--white); border-color: var(--teal); }
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
      gap: 1.5rem;
    }
    .nav__logo {
      font-family: var(--f-head);
      font-size: 1.15rem;
      font-weight: 700;
      text-transform: uppercase;
      letter-spacing: 0.04em;
      color: var(--black);
      flex-shrink: 0;
    }
    .nav__logo span { color: var(--teal); }
    .nav__links {
      display: none;
      list-style: none;
      gap: 2.5rem;
      flex: 1;
    }
    @media (min-width: 640px) { .nav__links { display: flex; } }
    .nav__links a {
      font-size: 0.8rem;
      font-weight: 500;
      letter-spacing: 0.04em;
      color: var(--muted);
      transition: color 0.15s;
    }
    .nav__links a:hover { color: var(--black); }

    /* ── Hamburger ──────────────────────────────────────── */
    .nav__hamburger {
      display: flex;
      flex-direction: column;
      justify-content: space-between;
      width: 22px;
      height: 16px;
      background: none;
      border: none;
      cursor: pointer;
      padding: 0;
      flex-shrink: 0;
    }
    .nav__hamburger span {
      display: block;
      width: 100%;
      height: 2px;
      background: var(--black);
      transition: transform 0.2s, opacity 0.2s;
      transform-origin: center;
    }
    .nav--open .nav__hamburger span:nth-child(1) { transform: translateY(7px) rotate(45deg); }
    .nav--open .nav__hamburger span:nth-child(2) { opacity: 0; transform: scaleX(0); }
    .nav--open .nav__hamburger span:nth-child(3) { transform: translateY(-7px) rotate(-45deg); }
    @media (min-width: 640px) { .nav__hamburger { display: none; } }

    /* ── Mobile menu ────────────────────────────────────── */
    .nav__cta        { display: none; }
    .nav__lang-desktop { display: none; }
    @media (min-width: 640px) {
      .nav__cta          { display: inline-block; }
      .nav__lang-desktop { display: flex; }
    }
    .nav__mobile {
      display: none;
      border-top: 1px solid var(--border);
      padding-bottom: 1.5rem;
    }
    .nav--open .nav__mobile { display: block; }
    @media (min-width: 640px) { .nav__mobile { display: none !important; } }
    .nav__mobile ul { list-style: none; }
    .nav__mobile ul li { border-bottom: 1px solid var(--border); }
    .nav__mobile ul a {
      display: block;
      padding: 1rem 0;
      font-family: var(--f-head);
      font-size: 1.15rem;
      font-weight: 700;
      text-transform: uppercase;
      letter-spacing: 0.04em;
      color: var(--black);
      transition: color 0.15s;
    }
    .nav__mobile ul a:hover { color: var(--teal); }
    .nav__mobile-footer {
      display: flex;
      align-items: center;
      justify-content: space-between;
      padding-top: 1.25rem;
      margin-top: 0.5rem;
    }

    /* ── Language switcher ──────────────────────────────── */
    .lang-switch {
      display: flex;
      align-items: center;
      gap: 0.3rem;
      font-family: var(--f-head);
      font-size: 0.75rem;
      font-weight: 700;
      letter-spacing: 0.1em;
      flex-shrink: 0;
    }
    .lang-switch a { color: var(--muted); transition: color 0.15s; }
    .lang-switch a:hover { color: var(--black); }
    .lang-switch__current { color: var(--teal) !important; }
    .lang-switch__sep { color: var(--border); }

    /* ── Hero ───────────────────────────────────────────── */
    .hero { padding: 10rem 0 5rem; }
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

    /* ── CGI watermark wrapper ──────────────────────────── */
    .img-cgi { position: relative; display: block; overflow: hidden; }
    .img-cgi--full { width: 100%; }
    .img-cgi--stretch { height: 100%; }
    .lang-en .img-cgi::after { content: 'CGI \2014  our vision for the space'; }
    .lang-cy .img-cgi::after { content: 'CGI \2014  ein gweledigaeth ar gyfer y gofod'; }
    .img-cgi::after {
      position: absolute;
      bottom: 0; left: 0; right: 0;
      padding: 0.5rem 1.25rem;
      background: rgba(0,0,0,0.45);
      color: rgba(255,255,255,0.85);
      font-family: var(--f-body);
      font-size: 0.7rem;
      font-weight: 500;
      letter-spacing: 0.1em;
      text-transform: uppercase;
      text-align: center;
    }

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
    .stats__item { background: var(--black); padding: 1.5rem 2rem; text-align: center; }
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
    @media (min-width: 768px) { .mission__grid { grid-template-columns: 1fr 1fr; } }
    .mission__photo { width: 100%; height: 480px; object-fit: cover; }
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
    .step { background: var(--white); padding: 2rem 1.75rem; }
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
      border-left: none;
      padding: 2.5rem;
      background: var(--white);
    }
    @media (max-width: 767px) {
      .plan__features { border-left: 1px solid var(--border); border-top: none; }
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
      left: 0; top: 0.6em;
      width: 6px; height: 6px;
      background: var(--teal);
    }
    .plan__features-wrap {
      display: grid;
      grid-template-columns: 1fr;
      gap: 0;
    }
    @media (min-width: 768px) { .plan__features-wrap { grid-template-columns: 340px 1fr; } }
    .plan__features-photo {
      width: 100%; height: 100%; min-height: 320px;
      object-fit: cover; object-position: center top; display: block;
    }

    /* ── Adventure Hub ──────────────────────────────────── */
    .adv__intro { font-size: 1.05rem; margin-bottom: 3.5rem; }
    .adv__grid {
      display: grid;
      grid-template-columns: repeat(auto-fit, minmax(260px, 1fr));
      gap: 2rem;
      margin-bottom: 3rem;
    }
    .adv__card { border-top: 3px solid var(--teal); padding-top: 1.5rem; }
    .adv__card h3 { color: var(--black); margin-bottom: 0.5rem; }
    .adv__card p  { font-size: 0.9rem; }
    .adv__partners {
      border: 1px solid var(--border);
      padding: 2.5rem;
      margin-bottom: 2.5rem;
    }
    .adv__partners h3 {
      font-size: 0.75rem;
      color: var(--muted);
      letter-spacing: 0.12em;
      margin-bottom: 0.75rem;
    }
    .adv__partners-intro { font-size: 0.9rem; margin-bottom: 2rem; max-width: none; }
    .adv__partner-list {
      list-style: none;
      display: flex;
      flex-wrap: wrap;
      gap: 0;
      border-top: 1px solid var(--border);
      border-left: 1px solid var(--border);
    }
    .adv__partner-list li {
      padding: 0.85rem 1.5rem;
      border-right: 1px solid var(--border);
      border-bottom: 1px solid var(--border);
      font-family: var(--f-head);
      font-size: 0.85rem;
      font-weight: 700;
      text-transform: uppercase;
      letter-spacing: 0.06em;
      color: var(--black);
    }
    .adv__cta-block {
      display: flex;
      flex-wrap: wrap;
      align-items: center;
      justify-content: space-between;
      gap: 1.5rem;
      padding: 2rem 2.5rem;
      background: var(--offwhite);
      border: 1px solid var(--border);
    }
    .adv__cta-block p { font-size: 0.95rem; max-width: 56ch; }

    /* ── Get Involved ───────────────────────────────────── */
    .involved__ways {
      display: grid;
      grid-template-columns: repeat(auto-fit, minmax(260px, 1fr));
      gap: 2rem;
      margin-bottom: 5rem;
    }
    .way { border-top: 3px solid var(--teal); padding-top: 1.5rem; }
    .way h3 { color: var(--black); margin-bottom: 0.5rem; }
    .way p   { font-size: 0.9rem; }

    /* ── Signup ─────────────────────────────────────────── */
    .signup { background: var(--teal); padding: 5rem 0; }
    .signup .wrap {
      display: grid;
      grid-template-columns: 1fr;
      gap: 3rem;
      align-items: start;
    }
    @media (min-width: 768px) { .signup .wrap { grid-template-columns: 1fr 1fr; } }
    .signup__copy h2 { color: var(--white); }
    .signup__copy .eyebrow { color: rgba(255,255,255,0.5); }
    .signup__copy p { color: rgba(255,255,255,0.7); max-width: none; }
    .signup__form { padding-top: 0.25rem; }
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
    .footer__col-p { font-size: 0.85rem; color: rgba(255,255,255,0.4); max-width: none; line-height: 1.6; }
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
    .footer__col ul a { font-size: 0.875rem; color: rgba(255,255,255,0.55); transition: color 0.15s; }
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
<body class="lang-<?= $lang ?>">

<!-- Nav ──────────────────────────────────────────────── -->
<nav class="nav" id="nav">
  <div class="wrap">
    <div class="nav__inner">
      <a href="/" class="nav__logo"><?= t('nav_logo') ?></a>
      <ul class="nav__links">
        <li><a href="#mission"><?= t('nav_mission') ?></a></li>
        <li><a href="#plan"><?= t('nav_plan') ?></a></li>
        <li><a href="#adventures"><?= t('nav_adventures') ?></a></li>
        <li><a href="#involved"><?= t('nav_involved') ?></a></li>
      </ul>
      <div class="lang-switch nav__lang-desktop">
        <a href="/" class="<?= $lang === 'en' ? 'lang-switch__current' : '' ?>">EN</a>
        <span class="lang-switch__sep">/</span>
        <a href="/?lang=cy" class="<?= $lang === 'cy' ? 'lang-switch__current' : '' ?>">CY</a>
      </div>
      <a href="#involved" class="btn btn--teal nav__cta"><?= t('nav_cta') ?></a>
      <button class="nav__hamburger" id="nav-toggle" aria-label="Menu" aria-expanded="false">
        <span></span><span></span><span></span>
      </button>
    </div>

    <!-- Mobile menu -->
    <div class="nav__mobile" id="nav-mobile">
      <ul>
        <li><a href="#mission"   class="nav__mobile-link"><?= t('nav_mission') ?></a></li>
        <li><a href="#plan"      class="nav__mobile-link"><?= t('nav_plan') ?></a></li>
        <li><a href="#adventures" class="nav__mobile-link"><?= t('nav_adventures') ?></a></li>
        <li><a href="#involved"  class="nav__mobile-link"><?= t('nav_involved') ?></a></li>
      </ul>
      <div class="nav__mobile-footer">
        <div class="lang-switch">
          <a href="/" class="<?= $lang === 'en' ? 'lang-switch__current' : '' ?>">EN</a>
          <span class="lang-switch__sep">/</span>
          <a href="/?lang=cy" class="<?= $lang === 'cy' ? 'lang-switch__current' : '' ?>">CY</a>
        </div>
        <a href="#involved" class="btn btn--teal nav__mobile-link"><?= t('nav_cta') ?></a>
      </div>
    </div>
  </div>
</nav>

<!-- Hero ─────────────────────────────────────────────── -->
<section class="hero" id="home">
  <div class="wrap">
    <p class="hero__label"><?= t('hero_label') ?></p>
    <h1><?= t('hero_h1') ?></h1>
    <p class="hero__sub"><?= t('hero_sub') ?></p>
    <div class="hero__actions">
      <a href="#involved" class="btn btn--teal"><?= t('hero_cta_primary') ?></a>
      <a href="#mission"  class="btn btn--outline"><?= t('hero_cta_secondary') ?></a>
    </div>
    <div class="hero__signup">
      <p class="hero__signup-label"><?= t('hero_signup_label') ?></p>
      <div class="ml-embedded" data-form="4M8VyN"></div>
    </div>
  </div>
</section>

<div class="img-cgi img-cgi--full">
  <img
    src="images/Cardigan-Climbing-Wall.jpg"
    alt="<?= t('img_alt_exterior') ?>"
    class="hero-photo"
  >
</div>

<!-- Stats ────────────────────────────────────────────── -->
<div class="stats">
  <div class="wrap">
    <div class="stats__grid">
      <div class="stats__item"><strong>1st</strong><span><?= t('stat_1_label') ?></span></div>
      <div class="stats__item"><strong>50+</strong><span><?= t('stat_2_label') ?></span></div>
      <div class="stats__item"><strong>10,000+</strong><span><?= t('stat_3_label') ?></span></div>
      <div class="stats__item"><strong>3</strong><span><?= t('stat_4_label') ?></span></div>
    </div>
  </div>
</div>

<!-- Mission ──────────────────────────────────────────── -->
<section class="section" id="mission">
  <div class="wrap">
    <div class="mission__grid">
      <div class="mission__text">
        <span class="eyebrow"><?= t('mission_eyebrow') ?></span>
        <h2><?= t('mission_h2') ?></h2>
        <p><?= t('mission_p1') ?></p>
        <p><?= t('mission_p2') ?></p>
        <p><?= t('mission_p3') ?></p>
        <div class="mission__pillars">
          <div class="pillar">
            <h3><?= t('pillar_1_title') ?></h3>
            <p><?= t('pillar_1_body') ?></p>
          </div>
          <div class="pillar">
            <h3><?= t('pillar_2_title') ?></h3>
            <p><?= t('pillar_2_body') ?></p>
          </div>
          <div class="pillar">
            <h3><?= t('pillar_3_title') ?></h3>
            <p><?= t('pillar_3_body') ?></p>
          </div>
          <div class="pillar">
            <h3><?= t('pillar_4_title') ?></h3>
            <p><?= t('pillar_4_body') ?></p>
          </div>
        </div>
        <a href="#plan" class="btn btn--teal" style="margin-top:1.5rem;"><?= t('mission_cta') ?></a>
      </div>
      <div class="img-cgi">
        <img
          src="images/Cardigan-Boundering-Wall.jpg"
          alt="<?= t('img_alt_bouldering') ?>"
          class="mission__photo"
        >
      </div>
    </div>
  </div>
</section>

<!-- Plan ─────────────────────────────────────────────── -->
<section class="section section--off" id="plan">
  <div class="wrap">
    <div class="plan__intro">
      <span class="eyebrow"><?= t('plan_eyebrow') ?></span>
      <h2><?= t('plan_h2') ?></h2>
      <p><?= t('plan_intro') ?></p>
    </div>
    <div class="plan__steps">
      <div class="step">
        <p class="step__num"><?= t('phase_label') ?> 01</p>
        <h3><?= t('phase_1_title') ?></h3>
        <p><?= t('phase_1_body') ?></p>
      </div>
      <div class="step">
        <p class="step__num"><?= t('phase_label') ?> 02</p>
        <h3><?= t('phase_2_title') ?></h3>
        <p><?= t('phase_2_body') ?></p>
      </div>
      <div class="step">
        <p class="step__num"><?= t('phase_label') ?> 03</p>
        <h3><?= t('phase_3_title') ?></h3>
        <p><?= t('phase_3_body') ?></p>
      </div>
      <div class="step">
        <p class="step__num"><?= t('phase_label') ?> 04</p>
        <h3><?= t('phase_4_title') ?></h3>
        <p><?= t('phase_4_body') ?></p>
      </div>
    </div>
    <div class="plan__features-wrap">
      <div class="img-cgi img-cgi--stretch">
        <img
          src="images/Cardigan-Climbing-Bouldering.jpg"
          alt="<?= t('img_alt_centre') ?>"
          class="plan__features-photo"
        >
      </div>
      <div class="plan__features">
        <h3><?= t('features_title') ?></h3>
        <ul class="feature-list">
          <li><?= t('feature_1') ?></li>
          <li><?= t('feature_2') ?></li>
          <li><?= t('feature_3') ?></li>
          <li><?= t('feature_4') ?></li>
          <li><?= t('feature_5') ?></li>
          <li><?= t('feature_6') ?></li>
          <li><?= t('feature_7') ?></li>
          <li><?= t('feature_8') ?></li>
        </ul>
      </div>
    </div>
  </div>
</section>

<!-- Adventure Hub ────────────────────────────────────── -->
<section class="section section--off" id="adventures">
  <div class="wrap">
    <span class="eyebrow"><?= t('adv_eyebrow') ?></span>
    <h2><?= t('adv_h2') ?></h2>
    <p class="adv__intro"><?= t('adv_intro') ?></p>

    <span class="eyebrow"><?= t('adv_guided_title') ?></span>
    <div class="adv__grid">
      <div class="adv__card">
        <h3><?= t('adv_1_title') ?></h3>
        <p><?= t('adv_1_body') ?></p>
      </div>
      <div class="adv__card">
        <h3><?= t('adv_2_title') ?></h3>
        <p><?= t('adv_2_body') ?></p>
      </div>
      <div class="adv__card">
        <h3><?= t('adv_3_title') ?></h3>
        <p><?= t('adv_3_body') ?></p>
      </div>
    </div>

    <div class="adv__partners">
      <h3><?= t('adv_partners_title') ?></h3>
      <p class="adv__partners-intro"><?= t('adv_partners_intro') ?></p>
      <ul class="adv__partner-list">
        <li>Adventure Beyond</li>
        <li>Walking on Water</li>
        <li>Forest Canoeing</li>
        <li>Anturio</li>
        <li>Planturio</li>
      </ul>
    </div>

    <div class="adv__cta-block">
      <p><?= t('adv_cta') ?></p>
      <a href="mailto:cardiganclimbing@gmail.com" class="btn btn--teal"><?= t('adv_cta_btn') ?></a>
    </div>
  </div>
</section>

<!-- Get Involved ─────────────────────────────────────── -->
<section class="section" id="involved">
  <div class="wrap">
    <span class="eyebrow"><?= t('involved_eyebrow') ?></span>
    <h2><?= t('involved_h2') ?></h2>
    <div class="involved__ways">
      <div class="way">
        <h3><?= t('way_1_title') ?></h3>
        <p><?= t('way_1_body') ?></p>
      </div>
      <div class="way">
        <h3><?= t('way_2_title') ?></h3>
        <p><?= t('way_2_body') ?></p>
      </div>
      <div class="way">
        <h3><?= t('way_3_title') ?></h3>
        <p><?= t('way_3_body') ?></p>
      </div>
    </div>
  </div>
</section>

<!-- Signup ───────────────────────────────────────────── -->
<div class="signup">
  <div class="wrap">
    <div class="signup__copy">
      <span class="eyebrow"><?= t('signup_eyebrow') ?></span>
      <h2><?= t('signup_h2') ?></h2>
      <p><?= t('signup_body') ?></p>
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
        <div class="footer__logo"><?= t('nav_logo') ?></div>
        <p class="footer__col-p"><?= t('footer_about') ?></p>
      </div>
      <div class="footer__col">
        <h4><?= t('footer_nav') ?></h4>
        <ul>
          <li><a href="#mission"><?= t('footer_nav_mission') ?></a></li>
          <li><a href="#plan"><?= t('footer_nav_plan') ?></a></li>
          <li><a href="#involved"><?= t('footer_nav_involved') ?></a></li>
        </ul>
      </div>
      <div class="footer__col">
        <h4><?= t('footer_contact') ?></h4>
        <ul>
          <li><a href="mailto:cardiganclimbing@gmail.com">cardiganclimbing@gmail.com</a></li>
          <li><?= t('footer_address_1') ?></li>
          <li><?= t('footer_address_2') ?></li>
        </ul>
      </div>
      <!-- Social links: unhide once profiles are live
      <div class="footer__col">
        <h4>Follow Us / Dilynwch Ni</h4>
        <ul>
          <li><a href="#">Facebook</a></li>
          <li><a href="#">Instagram</a></li>
          <li><a href="#">Twitter / X</a></li>
        </ul>
      </div>
      -->
    </div>
    <div class="footer__bottom">
      <span>&copy; <?= date('Y') ?> Cardigan Climbing. <?= t('footer_rights') ?></span>
      <span><?= t('footer_tagline') ?></span>
    </div>
  </div>
</footer>

<script>
  const nav    = document.getElementById('nav');
  const toggle = document.getElementById('nav-toggle');

  // Sticky border on scroll
  window.addEventListener('scroll', () => {
    nav.classList.toggle('scrolled', window.scrollY > 20);
  });

  // Hamburger toggle
  toggle.addEventListener('click', () => {
    const open = nav.classList.toggle('nav--open');
    toggle.setAttribute('aria-expanded', open);
  });

  // Close menu when any mobile link is clicked
  document.querySelectorAll('.nav__mobile-link').forEach(link => {
    link.addEventListener('click', () => {
      nav.classList.remove('nav--open');
      toggle.setAttribute('aria-expanded', 'false');
    });
  });
</script>

</body>
</html>
