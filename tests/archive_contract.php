<?php
declare(strict_types=1);

$root = dirname(__DIR__);
$core = '/var/www/jyavani.lan';
$fixture = sys_get_temp_dir() . '/jbh-archive-' . bin2hex(random_bytes(6));
mkdir($fixture . '/cfg/var', 0770, true);
mkdir($fixture . '/plugins', 0770, true);
define('BACKEND_PATH', $fixture . '/cfg');
define('PLUGIN_PATH', $fixture . '/plugins');
define('JBH_PLUGIN_NAME', 'jyavani-blackhole');
define('JBH_EVENTS_TABLE', 'jbh_events');
define('JBH_ITEMS_TABLE', 'jbh_items');
define('JBH_ARTIFACTS_TABLE', 'jbh_artifacts');
require_once $core . '/cfg/helpers/hooks.php';
require_once $core . '/cfg/helpers/resource_lifecycle.php';
require_once $root . '/includes/storage.php';
require_once $root . '/includes/archive.php';

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

$pdo = new PDO('sqlite::memory:');
$pdo->exec('CREATE TABLE jbh_events (event_id TEXT PRIMARY KEY, contract_schema INTEGER, resource TEXT, operation TEXT, bulk INTEGER, actor_id INTEGER, source TEXT, occurred_at TEXT, item_count INTEGER, metadata_json TEXT, metadata_sha256 TEXT, result_json TEXT, warnings_json TEXT, phase TEXT, ready_sha256 TEXT, captured_at TEXT DEFAULT CURRENT_TIMESTAMP, ready_at TEXT)');
$pdo->exec('CREATE TABLE jbh_items (event_id TEXT, item_id INTEGER, item_position INTEGER, before_json TEXT, after_json TEXT, before_sha256 TEXT, after_sha256 TEXT, PRIMARY KEY(event_id,item_id))');
$pdo->exec('CREATE TABLE jbh_artifacts (event_id TEXT, item_id INTEGER, ordinal INTEGER, role TEXT, managed INTEGER, source_state TEXT, descriptor_before_json TEXT, descriptor_before_sha256 TEXT, descriptor_ready_json TEXT, archive_status TEXT, archive_relative_path TEXT, archive_sha256 TEXT, archive_size INTEGER, copied_at TEXT, PRIMARY KEY(event_id,item_id,ordinal))');
$database = new ResourceLifecycleDatabase($pdo);
$event = resource_lifecycle_event([
    'schema' => 1,
    'event_id' => str_repeat('a', 64),
    'occurred_at' => '2026-09-06T12:00:00Z',
    'resource' => 'media',
    'operation' => 'trash',
    'bulk' => false,
    'actor_id' => 9,
    'source' => 'core.asset_lifecycle',
    'items' => [[
        'id' => 7,
        'before' => ['id' => 7, 'title' => 'Before'],
        'after' => null,
        'artifacts' => [['kind' => 'file', 'role' => 'primary', 'managed' => false, 'state' => 'unmanaged']],
    ]],
    'metadata' => [],
    'result' => [],
    'warnings' => [],
]);
$pdo->beginTransaction();
jbh_resource_before_mutation($event, $database);
jbh_resource_before_mutation($event, $database);
$check((int)$pdo->query('SELECT COUNT(*) FROM jbh_events')->fetchColumn() === 1
    && (int)$pdo->query('SELECT COUNT(*) FROM jbh_items')->fetchColumn() === 1
    && (int)$pdo->query('SELECT COUNT(*) FROM jbh_artifacts')->fetchColumn() === 1,
    'identical before-mutation delivery is idempotent');
$ready = $event;
$ready['items'][0]['after'] = ['id' => 7, 'title' => 'Before', 'is_deleted' => 1];
$ready['result'] = ['affected' => 1];
jbh_resource_before_commit($ready, $database);
jbh_resource_before_commit($ready, $database);
$row = $pdo->query('SELECT phase, result_json FROM jbh_events')->fetch(PDO::FETCH_ASSOC);
$check(($row['phase'] ?? '') === 'ready' && json_decode((string)$row['result_json'], true) === ['affected' => 1],
    'identical pre-commit delivery finalizes idempotently');
$changedReady = $ready;
$changedReady['result'] = ['affected' => 2];
$check($throws(static fn() => jbh_resource_before_commit($changedReady, $database)),
    'changed finalized data under one event ID fails closed');
$pdo->rollBack();
$check((int)$pdo->query('SELECT COUNT(*) FROM jbh_events')->fetchColumn() === 0, 'caller rollback removes all transactional archive rows');

$pdo->beginTransaction();
jbh_resource_before_mutation($event, $database);
$collision = $event;
$collision['items'][0]['before']['title'] = 'Changed';
$check($throws(static fn() => jbh_resource_before_mutation($collision, $database)), 'changed data under one event ID fails closed');
$pdo->rollBack();
$check(!jbh_supported_event(array_replace($event, ['resource' => 'article']))
    && $throws(static fn() => jbh_positive_item_id('../7')), 'unsupported resources are ignored and invalid item IDs are rejected');

$missing = $event;
$missing['event_id'] = str_repeat('d', 64);
$missing['operation'] = 'purge';
$missing['items'][0]['before']['quarantine_path'] = 'media/7/original.trash';
$missing['items'][0]['artifacts'][0] = ['kind' => 'file', 'role' => 'primary', 'managed' => false, 'state' => 'missing'];
$pdo->beginTransaction();
$check($throws(static fn() => jbh_resource_before_mutation(resource_lifecycle_event($missing), $database)),
    'purge fails closed when a snapshot expects quarantine bytes that Core reports missing');
$pdo->rollBack();

$remove($fixture);
if ($failures !== []) { fwrite(STDERR, count($failures) . " archive contract check(s) failed.\n"); exit(1); }
echo "Blackhole archive contract passed ({$checks} checks).\n";
