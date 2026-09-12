<?php
/**
 * One-off: removes the end-to-end test row created while verifying the new
 * admin-notification path in beta_signup.php, then deletes itself.
 *
 * It self-deletes because .cpanel.yml's deploy task is `cp -rf api/.
 * $DEPLOYPATH/api/` -- that only copies and overwrites, it never removes a
 * file that's been deleted from the repo, so git rm + redeploy would leave
 * this sitting on the live server. Hardcoded to the one test address, so it
 * can't remove anything else even if hit before it manages to unlink itself.
 */
require_once __DIR__ . '/../includes/helpers.php';
$pdo = db();
$stmt = $pdo->prepare('DELETE FROM beta_testers WHERE email = ?');
$stmt->execute(['claude-notify-test@example.com']);
header('Content-Type: application/json');
echo json_encode(['deleted_rows' => $stmt->rowCount()]);
@unlink(__FILE__);
