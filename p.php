<?php
/**
 * /p/<post id> — the one page on this site that shows Community content to
 * someone who is not a member.
 *
 * It exists because a shared link to a poll or an outfit otherwise shows a
 * stranger a sign-up wall, which is a poor first impression of a community.
 * But everything about it is deliberately narrow, because the privacy policy
 * and the sign-up consent screen both promise that Community posts are shown
 * to "other members":
 *
 *   - Only a post whose author explicitly ticked "shareable" is served here.
 *     That flag defaults to 0 and is only offered at posting time, so nothing
 *     anyone posted before this feature existed can ever appear on this page.
 *     The promise made to them is not changed retroactively.
 *   - Anything else — not shareable, hidden by moderation, or simply not
 *     found — is a plain 404. A "this post is private" page would confirm
 *     that a given id exists, which is itself a leak.
 *   - noindex, nofollow, and Disallow in robots.txt. A link works for whoever
 *     has it; Google never lists it. Someone sharing an outfit with three
 *     friends is not asking to be findable by name forever, and this is the
 *     direction that can be loosened later — the reverse cannot, because by
 *     then it is in somebody's cache.
 *   - The post only. No comments, no likes, no follower counts: those belong
 *     to other members who did not tick anything.
 *   - Poll photos show, but there is no voting here. One vote per account
 *     means nothing without an account.
 */
require_once __DIR__ . '/includes/helpers.php';

$id = (string)($_GET['id'] ?? '');

function share_404(): void {
    http_response_code(404);
    header('Content-Type: text/html; charset=utf-8');
    header('X-Robots-Tag: noindex, nofollow');
    echo '<!doctype html><html lang="en"><head><meta charset="utf-8">'
       . '<meta name="viewport" content="width=device-width, initial-scale=1">'
       . '<meta name="robots" content="noindex, nofollow">'
       . '<title>Not found · Style-LORE</title>'
       . '<style>body{font-family:system-ui,-apple-system,Segoe UI,Roboto,sans-serif;background:#faf6f3;color:#2b2724;'
       . 'display:flex;min-height:100vh;align-items:center;justify-content:center;margin:0;padding:24px;text-align:center}'
       . 'a{color:#8c5a4a}</style></head><body><div><h1 style="font-weight:600">This post isn\'t available</h1>'
       . '<p>It may have been removed, or never shared outside the app.</p>'
       . '<p><a href="/">Go to Style-LORE</a></p></div></body></html>';
    exit;
}

if ($id === '' || !preg_match('/^[A-Za-z0-9-]{8,64}$/', $id)) share_404();

$pdo = db();
ensure_poll_schema($pdo);
ensure_post_share_column($pdo);

$stmt = $pdo->prepare(
    'SELECT id, author_name, kibbe_tag, caption, photo_url, photo_b_url, is_poll, video_url, created_at
       FROM posts
      WHERE id = ? AND hidden = 0 AND shareable = 1 AND group_id IS NULL
      LIMIT 1'
);
$stmt->execute([$id]);
$post = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$post) share_404();

$typeNames = [
    'dramatic' => 'Dramatic', 'soft-dramatic' => 'Soft Dramatic',
    'flamboyant-natural' => 'Flamboyant Natural', 'natural' => 'Natural', 'soft-natural' => 'Soft Natural',
    'dramatic-classic' => 'Dramatic Classic', 'classic' => 'Classic', 'soft-classic' => 'Soft Classic',
    'flamboyant-gamine' => 'Flamboyant Gamine', 'gamine' => 'Gamine', 'soft-gamine' => 'Soft Gamine',
    'theatrical-romantic' => 'Theatrical Romantic', 'romantic' => 'Romantic',
];
$typeName = $typeNames[(string)$post['kibbe_tag']] ?? '';

$h = fn($s) => htmlspecialchars((string)$s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
$site = 'https://style-lore.com';
$abs = function (?string $u) use ($site) {
    if (!$u) return null;
    return strpos($u, 'http') === 0 ? $u : $site . '/' . ltrim($u, '/');
};

$caption = trim((string)$post['caption']);
$isPoll = !empty($post['is_poll']);
$author = trim((string)$post['author_name']) ?: 'A Style-LORE member';
$title = ($caption !== '' ? mb_substr($caption, 0, 80) : ($isPoll ? 'Help me choose' : 'An outfit')) . ' · Style-LORE';
$description = $isPoll
    ? $author . ' is asking which one to wear. Find your Kibbe body type, colors and style words free in two minutes.'
    : 'Shared by ' . $author . ($typeName !== '' ? ' · ' . $typeName : '') . '. Find your Kibbe body type, colors and style words free in two minutes.';
// Through /pi/ rather than /uploads/ so a crawler can actually fetch it
// for the preview without /uploads/ having to be opened up. See pi.php.
$ogImage = $post['photo_url'] ? $site . '/pi/' . rawurlencode((string)$post['id']) : null;

header('X-Robots-Tag: noindex, nofollow');
header('Content-Type: text/html; charset=utf-8');
header('Referrer-Policy: strict-origin-when-cross-origin');
?><!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex, nofollow">
<title><?= $h($title) ?></title>
<meta name="description" content="<?= $h($description) ?>">
<meta property="og:type" content="article">
<meta property="og:title" content="<?= $h($caption !== '' ? $caption : ($isPoll ? 'Help me choose' : 'An outfit on Style-LORE')) ?>">
<meta property="og:description" content="<?= $h($description) ?>">
<?php if ($ogImage): ?>
<meta property="og:image" content="<?= $h($ogImage) ?>">
<meta name="twitter:card" content="summary_large_image">
<?php else: ?>
<meta name="twitter:card" content="summary">
<?php endif; ?>
<link rel="icon" href="/favicon.ico">
<link rel="preconnect" href="https://fonts.googleapis.com">
<style>
  :root { --paper:#faf6f3; --ink:#2b2724; --ink-soft:#7a6f68; --line:#e6ddd6; --accent:#8c5a4a; }
  @media (prefers-color-scheme: dark) {
    :root { --paper:#1b1917; --ink:#f0ebe7; --ink-soft:#a99e97; --line:#332e2b; --accent:#d79a86; }
  }
  * { box-sizing:border-box; }
  body { margin:0; background:var(--paper); color:var(--ink); padding:24px 16px 48px;
         font-family:system-ui,-apple-system,"Segoe UI",Roboto,sans-serif; line-height:1.55; }
  .wrap { max-width:520px; margin:0 auto; }
  .brand { font-size:12px; letter-spacing:.14em; text-transform:uppercase; color:var(--ink-soft); margin-bottom:18px; }
  .brand a { color:inherit; text-decoration:none; }
  .who { font-size:13px; color:var(--ink-soft); margin-bottom:10px; }
  h1 { font-size:20px; font-weight:600; margin:0 0 14px; }
  .shots { display:flex; gap:10px; }
  .shots img, .single img { width:100%; border-radius:12px; display:block; border:1px solid var(--line); }
  .shots > div { flex:1; min-width:0; }
  .opt { font-size:12px; color:var(--ink-soft); margin-top:6px; text-align:center; }
  .cta { margin-top:26px; padding:18px; border:1px solid var(--line); border-radius:14px; text-align:center; }
  .cta p { margin:0 0 12px; font-size:14px; }
  .btn { display:inline-block; background:var(--accent); color:#fff; text-decoration:none;
         padding:11px 20px; border-radius:999px; font-weight:600; font-size:14px; }
  .foot { margin-top:22px; font-size:12px; color:var(--ink-soft); text-align:center; }
  .foot a { color:var(--ink-soft); }
</style>
</head>
<body>
<div class="wrap">
  <div class="brand"><a href="/">Style-LORE</a></div>
  <div class="who"><?= $h($author) ?><?= $typeName !== '' ? ' · ' . $h($typeName) : '' ?></div>
  <?php if ($caption !== ''): ?><h1><?= $h($caption) ?></h1><?php endif; ?>

  <?php if ($isPoll && $post['photo_url'] && $post['photo_b_url']): ?>
    <div class="shots">
      <div><img src="<?= $h($abs($post['photo_url'])) ?>" alt="Option A"><div class="opt">Option A</div></div>
      <div><img src="<?= $h($abs($post['photo_b_url'])) ?>" alt="Option B"><div class="opt">Option B</div></div>
    </div>
    <p class="opt" style="margin-top:12px">Voting happens in the app.</p>
  <?php elseif ($post['photo_url']): ?>
    <div class="single"><img src="<?= $h($abs($post['photo_url'])) ?>" alt="<?= $h($caption !== '' ? $caption : 'Outfit shared on Style-LORE') ?>"></div>
  <?php endif; ?>

  <div class="cta">
    <p>Find your Kibbe body type, your colors and your style words — free, private, about two minutes.</p>
    <a class="btn" href="/">Take the free quiz</a>
  </div>

  <div class="foot">
    Shared by a Style-LORE member. <a href="/privacy.html">Privacy</a> · <a href="/terms.html">Terms</a>
  </div>
</div>
</body>
</html>
