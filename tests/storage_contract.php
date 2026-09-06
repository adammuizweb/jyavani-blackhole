<?php
declare(strict_types=1);

$root = dirname(__DIR__);
$fixture = sys_get_temp_dir() . '/jbh-storage-' . bin2hex(random_bytes(6));
mkdir($fixture . '/cfg/var', 0770, true);
mkdir($fixture . '/plugins', 0770, true);
mkdir($fixture . '/private_files/.asset-trash/media/7', 0770, true);
define('BACKEND_PATH', $fixture . '/cfg');
define('PLUGIN_PATH', $fixture . '/plugins');
define('JBH_PLUGIN_NAME', 'jyavani-blackhole');
define('JBH_ARTIFACTS_TABLE', 'jbh_artifacts');
require_once $root . '/includes/storage.php';

$failures = [];
$checks = 0;
$check = static function (bool $condition, string $message) use (&$failures, &$checks): void {
    $checks++;
    echo ($condition ? 'PASS' : 'FAIL') . ' ' . $message . PHP_EOL;
    if (!$condition) $failures[] = $message;
};
$throws = static function (callable $callback): bool { try { $callback(); return false; } catch (Throwable) { return true; } };
$remove = static function (string $path) use (&$remove): void {
    if (is_link($path) || is_file($path)) { @unlink($path); return; }
    if (!is_dir($path)) return;
    foreach (scandir($path) ?: [] as $entry) if ($entry !== '.' && $entry !== '..') $remove($path . '/' . $entry);
    @rmdir($path);
};

$eventId = str_repeat('b', 64);
$source = $fixture . '/private_files/.asset-trash/media/7/.purge-recovery-' . str_repeat('c', 32);
file_put_contents($source, "verified archive bytes\n");
$event = ['event_id' => $eventId, 'resource' => 'media'];
$item = ['id' => 7];
$artifact = [
    'managed' => true, 'state' => 'present', 'root' => 'asset.recovery', 'disk' => 'recovery',
    'relative_path' => 'media/7/' . basename($source), 'absolute_path' => $source,
    'transition' => ['operation' => 'delete'],
];
$copy = jbh_copy_purge_artifact($event, $item, $artifact, 0);
$check($copy['created'] === true && is_file($copy['path']) && hash_file('sha256', $source) === $copy['sha256'],
    'managed purge bytes are copied and verified in private storage');
$again = jbh_copy_purge_artifact($event, $item, $artifact, 0);
$check($again['created'] === false && $again['sha256'] === $copy['sha256'], 'an identical existing archive is idempotent');
file_put_contents($copy['path'], 'changed');
$check($throws(static fn() => jbh_copy_purge_artifact($event, $item, $artifact, 0)), 'a mismatched existing archive fails closed');
$outside = $artifact;
$outside['absolute_path'] = $fixture . '/outside';
file_put_contents($outside['absolute_path'], 'outside');
$check($throws(static fn() => jbh_copy_purge_artifact($event, $item, $outside, 1)), 'artifact paths outside Core quarantine are rejected');
$check($throws(static fn() => jbh_archive_relative('../bad', 7, 0)), 'archive identities reject traversal input');

$orphan = dirname($copy['path']) . '/8-0.blob';
file_put_contents($orphan, 'orphan');
touch($orphan, time() - 90000);
touch($copy['path'], time() - 90000);
$pdo = new PDO('sqlite::memory:');
$pdo->exec('CREATE TABLE jbh_artifacts (event_id TEXT, archive_relative_path TEXT, archive_status TEXT, archive_sha256 TEXT, archive_size INTEGER)');
$tracked = $pdo->prepare('INSERT INTO jbh_artifacts VALUES (?,?,?,?,?)');
$tracked->execute([$eventId, $copy['relative'], 'complete', $copy['sha256'], $copy['size']]);
$GLOBALS['__jbh_reconciled'] = false;
jbh_reconcile_storage($pdo, 100, 86400);
$check(!file_exists($orphan) && file_exists($copy['path']),
    'reconciliation removes old orphans but retains tracked integrity mismatches');
$check($throws(static fn() => jbh_verify_event_artifacts($pdo, $eventId)),
    'finalized replay verification fails closed when tracked bytes are corrupt');

$GLOBALS['__jbh_created_artifacts'] = [];
$remove($fixture);
if ($failures !== []) { fwrite(STDERR, count($failures) . " storage contract check(s) failed.\n"); exit(1); }
echo "Blackhole storage contract passed ({$checks} checks).\n";
