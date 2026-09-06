<?php
declare(strict_types=1);

$root = dirname(__DIR__);
$sql = (string)file_get_contents($root . '/migrations/0001-blackhole-archive.sql');
$verify = (string)file_get_contents($root . '/migrations/0002-verify-blackhole-schema.php');
$failures = [];
$checks = 0;
$check = static function (bool $condition, string $message) use (&$failures, &$checks): void {
    $checks++;
    echo ($condition ? 'PASS' : 'FAIL') . ' ' . $message . PHP_EOL;
    if (!$condition) $failures[] = $message;
};

$check(substr_count($sql, 'CREATE TABLE IF NOT EXISTS') === 3 && substr_count($sql, 'ENGINE=InnoDB') === 3,
    'initial migration creates exactly three InnoDB archive tables');
$check(str_contains($sql, 'jbh_items_event_fk') && str_contains($sql, 'jbh_artifacts_item_fk')
    && substr_count($sql, 'ON DELETE CASCADE') === 2, 'archive ownership uses named cascade constraints');
$check(str_contains($sql, 'descriptor_before_sha256') && str_contains($sql, 'metadata_sha256')
    && str_contains($sql, 'archive_sha256') && str_contains($sql, 'ready_sha256'), 'immutable snapshots and finalized events retain integrity hashes');
$check(str_contains($verify, 'information_schema.COLUMNS') && str_contains($verify, 'information_schema.TABLES')
    && str_contains($verify, 'COLUMN_TYPE') && str_contains($verify, 'COLLATION_NAME')
    && str_contains($verify, 'information_schema.REFERENTIAL_CONSTRAINTS'), 'verification migration checks schema types, collations, indexes, and ownership');
$check(!preg_match('/\b(?:DROP|ALTER|TRUNCATE|DELIMITER|START\s+TRANSACTION|COMMIT|ROLLBACK)\b/i', $sql),
    'initial migration contains no destructive or transaction-control SQL');

if ($failures !== []) { fwrite(STDERR, count($failures) . " migration contract check(s) failed.\n"); exit(1); }
echo "Blackhole migration contract passed ({$checks} checks).\n";
