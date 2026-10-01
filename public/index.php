<?php
declare(strict_types=1);

require_once __DIR__ . '/../src/config.php';
require_once __DIR__ . '/../src/i18n.php';
require_once __DIR__ . '/../src/unsplash.php';

$lang = detect_lang();
$t = STRINGS[$lang];
$otherLang = $lang === 'fr' ? 'en' : 'fr';
// Link previews need full addresses. The image's address changes when the image does, so stale copies are dropped.
$siteUrl = rtrim(env('SITE_URL', 'https://canada-gov.canner.app'), '/');
$ogImage = "$siteUrl/assets/og-$lang.png?v=" . filemtime(__DIR__ . "/assets/og-$lang.png");

const LEAF_PATH = 'm4890 4430-45-863a95 95 0 0 1 111-98l859 151-116-320a65 65 0 0 1 20-73l941-762-212-99a65 65 0 0 1-34-79l186-572-542 115a65 65 0 0 1-73-38l-105-247-423 454a65 65 0 0 1-111-57l204-1052-327 189a65 65 0 0 1-91-27l-332-652-332 652a65 65 0 0 1-91 27l-327-189 204 1052a65 65 0 0 1-111 57l-423-454-105 247a65 65 0 0 1-73 38l-542-115 186 572a65 65 0 0 1-34 79l-212 99 941 762a65 65 0 0 1 20 73l-116 320 859-151a95 95 0 0 1 111 98l-45 863z';
const FLAG = '<svg class="flag" viewBox="0 0 9600 4800" aria-hidden="true"><path fill="#d80621" d="M0 0h2400v4800H0zm7200 0h2400v4800H7200z"/><path fill="#fff" d="M2400 0h4800v4800H2400z"/><path fill="#d80621" d="' . LEAF_PATH . '"/></svg>';
const ICON_ARROW = '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M5 12h14m-6-6 6 6-6 6" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/></svg>';
const ICON_LOCK = '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M7 10V7a5 5 0 0 1 10 0v3h1a2 2 0 0 1 2 2v7a2 2 0 0 1-2 2H6a2 2 0 0 1-2-2v-7a2 2 0 0 1 2-2zm2 0h6V7a3 3 0 0 0-6 0zm3 4a1.5 1.5 0 0 0-1 2.6V18h2v-1.4a1.5 1.5 0 0 0-1-2.600z" fill="currentColor"/></svg>';
const ICON_LEAF = '<svg viewBox="2400 0 4800 4800" aria-hidden="true"><path fill="currentColor" d="' . LEAF_PATH . '"/></svg>';
const ICON_CHECK = '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="m5 12.5 4.5 4.5L19 7.5" fill="none" stroke="currentColor" stroke-width="2.6" stroke-linecap="round" stroke-linejoin="round"/></svg>';
// The shackle is its own path so it can swing shut.
const ICON_LOCK_OPEN = '<svg viewBox="0 0 24 24" aria-hidden="true"><path class="shackle" d="M8 11V7a4 4 0 0 1 8 0v4" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round"/><rect x="5" y="10" width="14" height="11" rx="2.5" fill="currentColor"/></svg>';
const ICON_PAUSE = '<span class="pause-icon"><i></i><i></i></span>';
const ICON_CHEVRON = '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="m9 6 6 6-6 6" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round"/></svg>';

function ask_form(array $t, string $id, string $placeholder): string
{
    return '<form class="ask" data-ask>'
        . '<label class="sr-only" for="' . $id . '">' . e($t['ask_label']) . '</label>'
        . '<input id="' . $id . '" name="q" type="text" autocomplete="off" maxlength="1000" placeholder="' . e($placeholder) . '">'
        . '<button type="submit" class="round primary" aria-label="' . e($t['send']) . '">' . ICON_ARROW . '</button>'
        . '</form>';
}

/** The statement as one span per word, so each can come into focus on scroll. */
function statement(string $text): string
{
    $icons = [
        // The arrow spins, then its disc unrolls into the flag.
        '{ask}' => '<span class="chip chip-ask" data-chip="flag">'
            . '<span class="arrow-disc"></span><span class="arrow-glyph">' . ICON_ARROW . '</span>'
            . '<span class="arrow-spinner"></span><span class="arrow-flag">' . FLAG . '</span></span>',
        // Three seals sit stacked and fan out.
        '{seal}' => '<span class="chip-seals">' . str_repeat('<span class="chip chip-seal">' . ICON_LEAF . '</span>', 3) . '</span>',
        // The lock is scanned, then confirmed.
        '{lock}' => '<span class="chip chip-lock" data-chip="scan">'
            . '<span class="scan-glyph">' . ICON_LOCK . '</span><span class="scan-check">' . ICON_CHECK . '</span>'
            . '<span class="scan-light"></span></span>',
    ];
    $html = '';
    foreach (explode(' ', $text) as $word) {
        $html .= '<span class="word' . (isset($icons[$word]) ? ' chip-word' : '') . '">' . ($icons[$word] ?? e($word)) . '</span> ';
    }
    return $html;
}

$photos = unsplash_photos(count($t['examples']));

$config = [
    'lang' => $lang,
    'examples' => array_column($t['examples'], 0),
    'credits' => array_map(fn (?array $photo) => $photo ? [$photo['name'], $photo['profile'], $photo['page']] : null, $photos),
    'officialDomains' => OFFICIAL_DOMAINS,
    'strings' => array_intersect_key($t, array_flip(
        ['try', 'thinking', 'error', 'sources', 'copy', 'copied', 'pause', 'play', 'pause_motion', 'play_motion']
    )),
];
$navLinks = array_combine(['#how', '#privacy', '#soon', '#faq'], $t['footer_links']);
?>
<!doctype html>
<html lang="<?= $lang ?>">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= e($t['title']) ?></title>
<meta name="description" content="<?= e($t['description']) ?>">
<link rel="alternate" hreflang="<?= $otherLang ?>" href="?lang=<?= $otherLang ?>">
<meta property="og:type" content="website">
<meta property="og:site_name" content="Canada.gov">
<meta property="og:title" content="<?= e($t['title']) ?>">
<meta property="og:description" content="<?= e($t['description']) ?>">
<meta property="og:url" content="<?= e("$siteUrl/?lang=$lang") ?>">
<meta property="og:locale" content="<?= $lang ?>_CA">
<meta property="og:locale:alternate" content="<?= $otherLang ?>_CA">
<meta property="og:image" content="<?= e($ogImage) ?>">
<meta property="og:image:type" content="image/png">
<meta property="og:image:width" content="1200">
<meta property="og:image:height" content="630">
<meta property="og:image:alt" content="<?= e($t['hello'] . '. ' . $t['tagline'] . ' ' . $t['banner']) ?>">
<meta name="twitter:card" content="summary_large_image">
<link rel="preload" href="assets/fonts/castoro-400.woff2" as="font" type="font/woff2" crossorigin>
<link rel="preload" href="assets/fonts/radio-canada-var.woff2" as="font" type="font/woff2" crossorigin>
<link rel="stylesheet" href="assets/app.css">
<link rel="icon" href="assets/favicon.svg">
</head>
<body>

<p class="banner"><?= e($t['banner']) ?></p>

<div class="hero">
  <header class="top">
    <a class="brand" href="?lang=<?= $lang ?>"><?= FLAG ?><span>Canada.gov</span></a>
    <nav class="top-actions" aria-label="<?= e($t['menu']) ?>">
      <a class="pill ghost" href="?lang=<?= $otherLang ?>" lang="<?= $otherLang ?>" hreflang="<?= $otherLang ?>"><?= e($t['other_lang']) ?></a>
      <button class="pill" type="button" data-open-menu><?= e($t['menu']) ?></button>
    </nav>
  </header>

  <main id="top">
    <h1 data-enter="0"><?= e($t['hello']) ?></h1>
    <p class="tagline" data-enter="1"><?= e($t['tagline']) ?></p>

    <div class="stage" data-enter="2">
      <?= ask_form($t, 'ask-hero', sprintf($t['try'], $t['examples'][0][0])) ?>
      <div class="carousel" aria-roledescription="carousel" data-enter="3">
        <?php foreach ($t['examples'] as $i => [$question, $topic]): ?>
        <?php $photo = $photos[$i] ?? null; ?>
        <button type="button" class="slide slide-<?= $i % 5 ?><?= $photo ? ' has-photo' : '' ?><?= $i === 0 ? ' current' : '' ?>"<?= $i === 0 ? '' : ' tabindex="-1" aria-hidden="true"' ?>>
          <?php if ($photo): ?>
          <img class="slide-photo" alt="" referrerpolicy="no-referrer" style="background: <?= e($photo['color']) ?>" <?= $i === 0 ? 'src' : 'data-src' ?>="<?= e($photo['src']) ?>">
          <?php endif; ?>
          <span class="slide-topic"><?= e($topic) ?></span>
          <span class="slide-question"><?= e($question) ?></span>
        </button>
        <?php endforeach; ?>
      </div>
      <?php if ($photos): ?>
      <p class="credit" data-credit<?= $photos[0] ? '' : ' hidden' ?>><?= sprintf(
          e($t['photo_credit']),
          '<a data-credit-photo href="' . e($photos[0]['page'] ?? '') . '" target="_blank" rel="noopener">Photo</a>',
          '<a data-credit-author href="' . e($photos[0]['profile'] ?? '') . '" target="_blank" rel="noopener">' . e($photos[0]['name'] ?? '') . '</a>',
          '<a href="https://unsplash.com/' . UNSPLASH_REFERRAL . '" target="_blank" rel="noopener">Unsplash</a>'
      ) ?></p>
      <?php endif; ?>
    </div>

    <div class="carousel-controls" data-enter="4">
      <button type="button" class="round" data-carousel="prev" aria-label="<?= e($t['previous']) ?>"><span class="flip"><?= ICON_CHEVRON ?></span></button>
      <button type="button" class="round" data-carousel="toggle" aria-label="<?= e($t['pause']) ?>"><?= ICON_PAUSE ?></button>
      <button type="button" class="round" data-carousel="next" aria-label="<?= e($t['next']) ?>"><?= ICON_CHEVRON ?></button>
    </div>
  </main>
</div>

<section class="statement" aria-label="<?= e(strtr($t['statement'], ['{ask} ' => '', '{seal} ' => '', '{lock} ' => ''])) ?>">
  <p aria-hidden="true"><?= statement($t['statement']) ?></p>
</section>

<section class="features">
  <article class="feature">
    <div class="tile tile-sites" data-motion>
      <div class="pages" aria-hidden="true"><?php for ($i = 0; $i < 16; $i++): ?><span class="page page-<?= $i % 7 ?>"><i></i><b></b><b></b><b></b></span><?php endfor; ?></div>
      <button type="button" class="tile-pause" data-pause-tile aria-label="<?= e($t['pause_motion']) ?>"><?= ICON_PAUSE ?></button>
    </div>
    <div class="feature-text">
      <h2><?= e($t['features']['sites'][0]) ?></h2>
      <p><?= e($t['features']['sites'][1]) ?></p>
      <a href="#how"><?= e($t['features']['sites'][2]) ?></a>
    </div>
  </article>

  <article class="feature" id="privacy">
    <div class="tile tile-privacy" aria-hidden="true" data-motion>
      <span class="stars stars-1"></span><span class="stars stars-2"></span><span class="stars stars-3"></span>
      <span class="shooting-star"></span>
      <span class="aurora"></span><span class="aurora aurora-2"></span>
      <span class="lock" data-lock><?= ICON_LOCK_OPEN ?></span>
    </div>
    <div class="feature-text">
      <h2><?= e($t['features']['privacy'][0]) ?></h2>
      <p><?= e($t['features']['privacy'][1]) ?></p>
      <a href="#faq"><?= e($t['features']['privacy'][2]) ?></a>
    </div>
  </article>

  <article class="feature">
    <div class="tile tile-sources" aria-hidden="true">
      <?php foreach ($t['departments'] as $department): ?><span class="seal"><?= e($department) ?></span><?php endforeach; ?>
    </div>
    <div class="feature-text">
      <h2><?= e($t['features']['sources'][0]) ?></h2>
      <p><?= e($t['features']['sources'][1]) ?></p>
      <a href="#faq"><?= e($t['features']['sources'][2]) ?></a>
    </div>
  </article>

  <article class="feature">
    <div class="tile tile-browser" aria-hidden="true">
      <div class="window">
        <div class="window-bar"><i></i><i></i><i></i><span>canada.gov</span></div>
        <div class="window-body"><?= FLAG ?><b><?= e($t['hello']) ?></b><span class="mini-ask"></span></div>
      </div>
      <div class="phone"><?= FLAG ?><span class="mini-ask"></span></div>
    </div>
    <div class="feature-text">
      <h2><?= e($t['features']['browser'][0]) ?></h2>
      <p><?= e($t['features']['browser'][1]) ?></p>
      <a href="#top" data-focus-ask><?= e($t['features']['browser'][2]) ?></a>
    </div>
  </article>
</section>

<section class="how" id="how">
  <h2><?= e($t['how_title']) ?></h2>
  <ol>
    <?php foreach ($t['how_steps'] as [$title, $text]): ?>
    <li><h3><?= e($title) ?></h3><p><?= e($text) ?></p></li>
    <?php endforeach; ?>
  </ol>
</section>

<section class="faq" id="faq">
  <h2><?= e($t['faq_title']) ?></h2>
  <?php foreach ($t['faq'] as [$question, $answer]): ?>
  <details><summary><?= e($question) ?></summary><p><?= e($answer) ?></p></details>
  <?php endforeach; ?>
</section>

<section class="soon" id="soon">
  <h2><?= e($t['soon_title']) ?></h2>
  <p class="lead"><?= e($t['soon_text']) ?></p>
  <p class="note"><?= e($t['soon_note']) ?></p>

  <div class="previews" aria-hidden="true" data-motion>
    <div class="preview" data-tip="<?= e($t['coming']) ?>">
      <h3><?= e($t['card_camp']) ?></h3>
      <div class="map"><span class="pin"></span><span class="pin pin-2"></span><span class="pin pin-3"></span></div>
      <?php foreach ($t['card_camp_items'] as $name): ?>
      <div class="row"><span><?= e($name) ?></span><span class="tag"><?= e($t['select']) ?></span></div>
      <?php endforeach; ?>
    </div>

    <div class="preview" data-tip="<?= e($t['coming']) ?>">
      <h3><?= e($t['card_jobs']) ?></h3>
      <?php foreach ($t['card_jobs_items'] as [$job, $employer, $place]): ?>
      <div class="job"><b><?= e($job) ?></b><span><?= e($employer) ?></span><span><?= e($place) ?></span></div>
      <?php endforeach; ?>
      <span class="button"><?= e($t['apply']) ?></span>
    </div>

    <div class="preview" data-tip="<?= e($t['coming']) ?>">
      <h3><?= e($t['card_address']) ?></h3>
      <?php foreach ($t['card_address_items'] as $agency): ?>
      <div class="row"><span><?= e($agency) ?></span><span class="check"></span></div>
      <?php endforeach; ?>
      <span class="button"><?= e($t['change_address']) ?></span>
    </div>

    <div class="preview preview-passport" data-tip="<?= e($t['coming']) ?>">
      <h3><?= e($t['card_passport']) ?></h3>
      <p><?= e($t['card_passport_eta']) ?></p>
      <div class="progress"><span class="done"></span><span class="doing"></span><span></span></div>
      <div class="progress-labels"><span><?= e($t['submitted']) ?></span><span><?= e($t['processing']) ?></span><span><?= e($t['mailed']) ?></span></div>
    </div>
  </div>
</section>

<footer>
  <nav class="footer-links" aria-label="<?= e($t['title']) ?>">
    <?php foreach ($navLinks as $href => $label): ?>
    <a href="<?= $href ?>"><?= e($label) ?></a>
    <?php endforeach; ?>
  </nav>
  <svg class="wordmark" viewBox="0 0 1392 264" aria-hidden="true"><text x="0" y="170" textLength="1392" lengthAdjust="spacingAndGlyphs">Canada.gov</text></svg>
  <div class="footer-meta">
    <p><?= e($t['footer_left']) ?></p>
    <p class="centre"><?= e($t['footer_centre']) ?></p>
    <p><?= e($t['footer_right']) ?></p>
  </div>
  <p class="footer-legal"><?= e($t['banner']) ?> <a href="?lang=<?= $otherLang ?>" lang="<?= $otherLang ?>"><?= e($t['other_lang']) ?></a></p>
</footer>

<div class="dock" data-dock>
  <?= ask_form($t, 'ask-dock', $t['ask_placeholder']) ?>
</div>

<dialog class="menu" id="menu" aria-label="<?= e($t['menu']) ?>">
  <form method="dialog"><button class="pill"><?= e($t['close']) ?></button></form>
  <nav>
    <?php foreach ($navLinks as $href => $label): ?>
    <a href="<?= $href ?>" data-close-menu><?= e($label) ?></a>
    <?php endforeach; ?>
    <a href="?lang=<?= $otherLang ?>" lang="<?= $otherLang ?>"><?= e($t['other_lang']) ?></a>
  </nav>
</dialog>

<dialog class="chat" id="chat" aria-labelledby="chat-title">
  <header class="top">
    <a class="brand" href="?lang=<?= $lang ?>" data-close-chat><?= FLAG ?><span>Canada.gov</span></a>
    <h2 class="sr-only" id="chat-title"><?= e($t['chat_title']) ?></h2>
    <div class="top-actions">
      <button class="pill ghost" type="button" data-new-chat><?= e($t['new_chat']) ?></button>
      <button class="pill" type="button" data-close-chat><?= e($t['close']) ?></button>
    </div>
  </header>
  <div class="thread" data-thread aria-live="polite"></div>
  <div class="composer">
    <?= ask_form($t, 'ask-chat', $t['ask_placeholder']) ?>
    <p><?= e($t['disclaimer']) ?></p>
  </div>
</dialog>

<script type="application/json" id="config"><?= json_encode($config, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG) ?></script>
<script src="assets/app.js" defer></script>
</body>
</html>
