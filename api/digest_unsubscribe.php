<?php
/**
 * GET /api/digest_unsubscribe.php?id=<account>&t=<token> — one-click
 * unsubscribe from the weekly email. The token is an HMAC of the account
 * id (see digest_unsub_token in helpers.php), so the link works without
 * a login and can't be forged for someone else's account.
 */
require_once __DIR__ . '/../includes/helpers.php';
header('Content-Type: text/html; charset=utf-8');

$id = (string)($_GET['id'] ?? '');
$t = (string)($_GET['t'] ?? '');
$ok = $id !== '' && $t !== '' && hash_equals(digest_unsub_token($id), $t);
if ($ok) {
    try { db()->prepare('UPDATE accounts SET digest_opt_out = 1 WHERE id = ?')->execute([$id]); } catch (Throwable $e) { $ok = false; }
}
$msg = $ok ? "You're unsubscribed from the weekly email. Your account and results are untouched."
           : "That unsubscribe link isn't valid. You can also turn the weekly email off from the app's Home screen.";
echo '<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Style-LORE</title></head>'
   . '<body style="margin:0;background:#FBF0F3;color:#2E1620;font-family:Georgia,serif;"><div style="max-width:520px;margin:60px auto;padding:0 20px;text-align:center;">'
   . '<div style="font-size:24px;font-weight:bold;margin-bottom:14px;">Style-LORE</div><p style="font-size:17px;line-height:1.5;">' . htmlspecialchars($msg) . '</p>'
   . '<p><a href="' . htmlspecialchars(SITE_BASE_URL) . '/" style="color:#C92C69;">Back to Style-LORE</a></p></div></body></html>';
