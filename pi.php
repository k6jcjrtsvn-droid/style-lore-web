<?php
/**
 * /pi/<post id>[-b] — the preview image for a shared post, and nothing else.
 *
 * Why this exists rather than just linking the file under /uploads/:
 * robots.txt already disallows /uploads/, which is what keeps members' post
 * photos out of search. But robots.txt controls *fetching*, and a link
 * preview only works if Facebook's, iMessage's or Slack's crawler can fetch
 * the image. Loosening /uploads/ would open every post's photo, including
 * posts nobody opted to share.
 *
 * So this path is allowed to crawlers and serves the image for an opted-in
 * post only, with `X-Robots-Tag: noindex` — fetchable for a preview, never
 * listed in a search result. That is exactly the line the person drew when
 * they ticked "shareable": a link my friends can open, not a permanent
 * entry under my name.
 *
 * Anything not shareable, hidden, or missing is a 404 with no detail.
 */
require_once __DIR__ . '/includes/helpers.php';

$raw = (string)($_GET['id'] ?? '');
$wantB = false;
if (substr($raw, -2) === '-b') { $wantB = true; $raw = substr($raw, 0, -2); }

function pi_404(): void { http_response_code(404); header('X-Robots-Tag: noindex, nofollow'); exit; }

if ($raw === '' || !preg_match('/^[A-Za-z0-9-]{8,64}$/', $raw)) pi_404();

$pdo = db();
ensure_poll_schema($pdo);
ensure_post_share_column($pdo);

$stmt = $pdo->prepare(
    'SELECT photo_url, photo_b_url FROM posts
      WHERE id = ? AND hidden = 0 AND shareable = 1 AND group_id IS NULL LIMIT 1'
);
$stmt->execute([$raw]);
$post = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$post) pi_404();

$rel = (string)($wantB ? ($post['photo_b_url'] ?? '') : ($post['photo_url'] ?? ''));
if ($rel === '') pi_404();

// The stored value is a site-relative upload path. Resolve it and then check
// the real path is still inside uploads/ — a stored value should never be
// able to walk out of that directory, whatever put it there.
$uploads = realpath(__DIR__ . '/uploads');
$path = realpath(__DIR__ . '/' . ltrim($rel, '/'));
if ($uploads === false || $path === false || strpos($path, $uploads) !== 0 || !is_file($path)) pi_404();

$finfo = finfo_open(FILEINFO_MIME_TYPE);
$mime = finfo_file($finfo, $path) ?: '';
finfo_close($finfo);
if (strpos($mime, 'image/') !== 0) pi_404();

header('Content-Type: ' . $mime);
header('Content-Length: ' . filesize($path));
header('X-Robots-Tag: noindex, nofollow');
header('Cache-Control: public, max-age=86400');
readfile($path);
