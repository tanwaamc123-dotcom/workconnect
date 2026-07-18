<?php

declare(strict_types=1);

header('Content-Type: image/svg+xml; charset=UTF-8');
header('Cache-Control: public, max-age=86400');

function cover_text(string $value, int $max = 28): array
{
    $value = trim($value);
    if ($value === '') {
        return ['WorkConnect'];
    }

    $words = preg_split('/\s+/u', $value) ?: [$value];
    $lines = [];
    $current = '';

    foreach ($words as $word) {
        $candidate = trim($current === '' ? $word : $current . ' ' . $word);
        if (mb_strlen($candidate) <= $max || $current === '') {
            $current = $candidate;
            continue;
        }
        $lines[] = $current;
        $current = $word;
        if (count($lines) === 2) {
            break;
        }
    }

    if ($current !== '' && count($lines) < 3) {
        $lines[] = $current;
    }

    if (count($lines) > 3) {
        $lines = array_slice($lines, 0, 3);
    }

    return $lines ?: ['WorkConnect'];
}

function esc(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
}

function cover_keyword(string $slug): string
{
    return match ($slug) {
        'responsive-website-design' => 'WEB',
        'brand-identity-logo' => 'LOGO',
        'powerpoint-presentation' => 'SLIDE',
        'short-video-editing' => 'VIDEO',
        'e-commerce-website' => 'SHOP',
        'social-media-post-set' => 'SOCIAL',
        'resume-cv-design' => 'CV',
        'photo-retouching' => 'PHOTO',
        'mobile-app-ui-design' => 'APP UI',
        'pitch-deck-redesign' => 'PITCH',
        default => 'DESIGN',
    };
}

$title = (string) ($_GET['title'] ?? 'Creative Service');
$category = (string) ($_GET['category'] ?? 'WorkConnect');
$seller = (string) ($_GET['seller'] ?? 'Verified Seller');
$slug = strtolower(trim((string) preg_replace('/[^a-z0-9]+/i', '-', $title), '-'));

$palettes = [
    'responsive-website-design' => ['#0d1b2a', '#00b4d8', '#48cae4', '#f8fbff'],
    'brand-identity-logo' => ['#34180d', '#ff7b00', '#ffb703', '#fff8ef'],
    'powerpoint-presentation' => ['#14213d', '#f72585', '#4cc9f0', '#fdf7ff'],
    'short-video-editing' => ['#201124', '#ff006e', '#fb5607', '#fff5f7'],
    'e-commerce-website' => ['#132a13', '#2dc653', '#80ed99', '#f4fff6'],
    'social-media-post-set' => ['#240046', '#7b2cbf', '#ff006e', '#fff4fd'],
    'resume-cv-design' => ['#1f2937', '#3a86ff', '#8338ec', '#f7fbff'],
    'photo-retouching' => ['#1d3557', '#4361ee', '#f72585', '#f7f4ff'],
    'mobile-app-ui-design' => ['#111827', '#06d6a0', '#4cc9f0', '#f3fffb'],
    'pitch-deck-redesign' => ['#10002b', '#c77dff', '#ff758f', '#fff7fc'],
];

[$bg, $primary, $accent, $soft] = $palettes[$slug] ?? ['#111827', '#2563eb', '#8b5cf6', '#eff6ff'];
$titleLines = cover_text($title, 18);
$categoryLabel = strtoupper($category);
$sellerLabel = $seller !== '' ? $seller : 'Verified Seller';
$firstBadge = cover_keyword($slug);

echo '<?xml version="1.0" encoding="UTF-8"?>';
?>
<svg width="1600" height="900" viewBox="0 0 1600 900" fill="none" xmlns="http://www.w3.org/2000/svg">
  <defs>
    <linearGradient id="posterBg" x1="90" y1="90" x2="1510" y2="830" gradientUnits="userSpaceOnUse">
      <stop stop-color="<?= esc($bg) ?>"/>
      <stop offset="1" stop-color="<?= esc($bg) ?>" stop-opacity="0.92"/>
    </linearGradient>
    <linearGradient id="accentGlow" x1="934" y1="238" x2="1480" y2="740" gradientUnits="userSpaceOnUse">
      <stop stop-color="<?= esc($accent) ?>"/>
      <stop offset="1" stop-color="<?= esc($primary) ?>"/>
    </linearGradient>
    <linearGradient id="deviceGrad" x1="1020" y1="190" x2="1430" y2="740" gradientUnits="userSpaceOnUse">
      <stop stop-color="<?= esc($soft) ?>"/>
      <stop offset="1" stop-color="white"/>
    </linearGradient>
    <filter id="shadow" x="760" y="150" width="760" height="620" filterUnits="userSpaceOnUse" color-interpolation-filters="sRGB">
      <feDropShadow dx="0" dy="24" stdDeviation="22" flood-color="#000000" flood-opacity="0.28"/>
    </filter>
    <filter id="softShadow" x="36" y="560" width="740" height="240" filterUnits="userSpaceOnUse" color-interpolation-filters="sRGB">
      <feDropShadow dx="0" dy="10" stdDeviation="16" flood-color="#000000" flood-opacity="0.18"/>
    </filter>
  </defs>

  <rect width="1600" height="900" rx="48" fill="url(#posterBg)"/>
  <circle cx="252" cy="196" r="212" fill="<?= esc($primary) ?>" fill-opacity="0.14"/>
  <circle cx="1390" cy="738" r="250" fill="<?= esc($accent) ?>" fill-opacity="0.12"/>

  <text x="126" y="104" fill="white" fill-opacity="0.9" font-size="30" font-family="Arial, Helvetica, sans-serif" font-style="italic" font-weight="500"><?= esc($categoryLabel) ?></text>
  <path d="M126 132L546 96" stroke="white" stroke-opacity="0.56" stroke-width="3"/>

  <?php foreach ($titleLines as $index => $line): ?>
  <text x="120" y="<?= 262 + ($index * 108) ?>" fill="<?= $index === count($titleLines) - 1 ? esc($accent) : 'white' ?>" font-size="<?= $index === 0 ? '92' : '104' ?>" font-family="Arial, Helvetica, sans-serif" font-weight="900" letter-spacing="-2.8"><?= esc(mb_strtoupper($line)) ?></text>
  <?php endforeach; ?>

  <g filter="url(#softShadow)">
    <path d="M52 642L742 566L688 760L0 836L52 642Z" fill="white"/>
  </g>
  <text x="112" y="716" fill="<?= esc($bg) ?>" font-size="29" font-family="Arial, Helvetica, sans-serif" font-weight="700">Cleaner layout. Faster trust. Stronger</text>
  <text x="112" y="760" fill="<?= esc($bg) ?>" font-size="29" font-family="Arial, Helvetica, sans-serif" font-weight="700">decision-making from the first glance.</text>

  <g filter="url(#shadow)">
    <rect x="950" y="200" width="430" height="560" rx="46" fill="url(#deviceGrad)"/>
    <rect x="986" y="244" width="358" height="420" rx="30" fill="<?= esc($bg) ?>" fill-opacity="0.92"/>
    <rect x="1018" y="276" width="146" height="92" rx="18" fill="<?= esc($primary) ?>"/>
    <rect x="1180" y="276" width="134" height="92" rx="18" fill="<?= esc($accent) ?>"/>
    <rect x="1018" y="386" width="112" height="112" rx="18" fill="<?= esc($soft) ?>"/>
    <rect x="1148" y="386" width="80" height="112" rx="18" fill="white"/>
    <rect x="1244" y="386" width="70" height="112" rx="18" fill="<?= esc($primary) ?>" fill-opacity="0.26"/>
    <rect x="1018" y="516" width="296" height="116" rx="24" fill="white"/>
    <rect x="1042" y="540" width="180" height="22" rx="11" fill="<?= esc($bg) ?>" fill-opacity="0.12"/>
    <rect x="1042" y="578" width="234" height="18" rx="9" fill="<?= esc($bg) ?>" fill-opacity="0.08"/>
    <rect x="1042" y="606" width="198" height="18" rx="9" fill="<?= esc($bg) ?>" fill-opacity="0.08"/>
  </g>

  <path d="M842 454C898 394 960 356 1036 334C1092 318 1144 318 1186 328" stroke="white" stroke-opacity="0.42" stroke-width="6" stroke-dasharray="12 16"/>
  <path d="M1336 138C1314 212 1310 278 1328 332C1346 386 1384 430 1442 466" stroke="white" stroke-opacity="0.24" stroke-width="6" stroke-dasharray="12 16"/>
  <path d="M1472 328C1486 420 1484 500 1464 568C1446 626 1410 682 1358 726" stroke="white" stroke-opacity="0.24" stroke-width="6" stroke-dasharray="12 16"/>

  <rect x="1196" y="88" width="270" height="168" rx="12" fill="none" stroke="white" stroke-opacity="0.54" stroke-width="2"/>
  <circle cx="1286" cy="172" r="76" stroke="white" stroke-opacity="0.54" stroke-width="3"/>
  <circle cx="1390" cy="144" r="48" stroke="white" stroke-opacity="0.54" stroke-width="3"/>
  <circle cx="1376" cy="224" r="30" stroke="white" stroke-opacity="0.54" stroke-width="3"/>
  <circle cx="1326" cy="224" r="16" stroke="white" stroke-opacity="0.54" stroke-width="3"/>

  <rect x="1100" y="786" width="408" height="78" rx="24" fill="<?= esc($primary) ?>"/>
  <rect x="1106" y="792" width="396" height="66" rx="20" fill="url(#accentGlow)"/>
  <text x="1154" y="842" fill="<?= esc($soft) ?>" font-size="38" font-family="Arial, Helvetica, sans-serif" font-weight="800"><?= esc($firstBadge) ?></text>

  <rect x="110" y="812" width="320" height="56" rx="18" fill="white" fill-opacity="0.12"/>
  <text x="144" y="848" fill="white" font-size="24" font-family="Arial, Helvetica, sans-serif" font-weight="700"><?= esc($sellerLabel) ?></text>
</svg>
