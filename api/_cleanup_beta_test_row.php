<?php
/**
 * One-off cleanup: removes the single leftover end-to-end test row
 * (claude-e2e-test@example.com) created while verifying beta_signup.php.
 * Deliberately hardcoded to that one email only (accepts no input), so
 * it can't be used to delete anything else even if hit again before this
 * file is removed. Delete this file after running it once.
 */
require_once __DIR__ . '/../includes/helpers.php';
$pdo = db();
$stmt = $pdo->prepare('DELETE FROM beta_testers WHERE email = ?');
$stmt->execute(['claude-e2e-test@example.com']);
echo "deleted rows: " . $stmt->rowCount();
