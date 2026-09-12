<?php
/**
 * Self-deleting cleanup: removes the earlier one-off
 * _cleanup_beta_test_row.php, which turned out to still be live on the
 * server after being removed from git -- .cpanel.yml's deploy task is
 * `cp -rf api/. $DEPLOYPATH/api/`, which only copies/overwrites files, it
 * never deletes a file that's missing from the repo but still present on
 * the server. So removing a file from git and redeploying does NOT
 * remove it live; the file has to be deleted on the server directly.
 * This script does that, then deletes itself the same way, so it leaves
 * nothing behind either.
 */
$target = __DIR__ . '/_cleanup_beta_test_row.php';
$result = ['removed_old' => file_exists($target) ? unlink($target) : 'already gone'];
header('Content-Type: application/json');
echo json_encode($result);
@unlink(__FILE__);
