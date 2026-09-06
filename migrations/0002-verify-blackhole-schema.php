<?php
declare(strict_types=1);

return static function (PDO $pdo): void {
    $required = [
        'jbh_events' => ['event_id', 'contract_schema', 'resource', 'operation', 'bulk', 'actor_id', 'source', 'occurred_at', 'item_count', 'metadata_json', 'metadata_sha256', 'result_json', 'warnings_json', 'phase', 'ready_sha256', 'captured_at', 'ready_at'],
        'jbh_items' => ['event_id', 'item_id', 'item_position', 'before_json', 'after_json', 'before_sha256', 'after_sha256'],
        'jbh_artifacts' => ['event_id', 'item_id', 'ordinal', 'role', 'managed', 'source_state', 'descriptor_before_json', 'descriptor_before_sha256', 'descriptor_ready_json', 'archive_status', 'archive_relative_path', 'archive_sha256', 'archive_size', 'copied_at'],
    ];
    $columns = $pdo->prepare('SELECT COLUMN_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=?');
    foreach ($required as $table => $expected) {
        $columns->execute([$table]);
        $available = array_fill_keys(array_map('strval', $columns->fetchAll(PDO::FETCH_COLUMN) ?: []), true);
        foreach ($expected as $column) {
            if (!isset($available[$column])) throw new RuntimeException('Blackhole schema is incompatible: ' . $table . '.' . $column);
        }
    }

    $critical = [
        'jbh_events' => [
            'event_id' => ['/^char\(64\)$/', 'NO', 'ascii', 'ascii_bin'],
            'contract_schema' => ['/^tinyint(?:\([0-9]+\))? unsigned$/', 'NO', null, null],
            'resource' => ['/^varchar\(100\)$/', 'NO', 'ascii', 'ascii_bin'],
            'operation' => ['/^varchar\(100\)$/', 'NO', 'ascii', 'ascii_bin'],
            'bulk' => ['/^tinyint(?:\(1\))?$/', 'NO', null, null],
            'actor_id' => ['/^bigint(?:\([0-9]+\))?$/', 'YES', null, null],
            'source' => ['/^varchar\(100\)$/', 'NO', 'ascii', 'ascii_bin'],
            'occurred_at' => ['/^char\(20\)$/', 'NO', 'ascii', 'ascii_bin'],
            'item_count' => ['/^smallint(?:\([0-9]+\))? unsigned$/', 'NO', null, null],
            'metadata_json' => ['/^longtext$/', 'NO', 'utf8mb4', 'utf8mb4_unicode_ci'],
            'metadata_sha256' => ['/^char\(64\)$/', 'NO', 'ascii', 'ascii_bin'],
            'result_json' => ['/^longtext$/', 'NO', 'utf8mb4', 'utf8mb4_unicode_ci'],
            'warnings_json' => ['/^longtext$/', 'NO', 'utf8mb4', 'utf8mb4_unicode_ci'],
            'phase' => ['/^varchar\(16\)$/', 'NO', 'ascii', 'ascii_bin'],
            'ready_sha256' => ['/^char\(64\)$/', 'YES', 'ascii', 'ascii_bin'],
            'captured_at' => ['/^datetime$/', 'NO', null, null],
            'ready_at' => ['/^datetime$/', 'YES', null, null],
        ],
        'jbh_items' => [
            'event_id' => ['/^char\(64\)$/', 'NO', 'ascii', 'ascii_bin'],
            'item_id' => ['/^bigint(?:\([0-9]+\))? unsigned$/', 'NO', null, null],
            'item_position' => ['/^smallint(?:\([0-9]+\))? unsigned$/', 'NO', null, null],
            'before_json' => ['/^longtext$/', 'NO', 'utf8mb4', 'utf8mb4_unicode_ci'],
            'after_json' => ['/^longtext$/', 'YES', 'utf8mb4', 'utf8mb4_unicode_ci'],
            'before_sha256' => ['/^char\(64\)$/', 'NO', 'ascii', 'ascii_bin'],
            'after_sha256' => ['/^char\(64\)$/', 'YES', 'ascii', 'ascii_bin'],
        ],
        'jbh_artifacts' => [
            'event_id' => ['/^char\(64\)$/', 'NO', 'ascii', 'ascii_bin'],
            'item_id' => ['/^bigint(?:\([0-9]+\))? unsigned$/', 'NO', null, null],
            'ordinal' => ['/^smallint(?:\([0-9]+\))? unsigned$/', 'NO', null, null],
            'role' => ['/^varchar\(100\)$/', 'NO', 'ascii', 'ascii_bin'],
            'managed' => ['/^tinyint(?:\(1\))?$/', 'NO', null, null],
            'source_state' => ['/^varchar\(16\)$/', 'NO', 'ascii', 'ascii_bin'],
            'descriptor_before_json' => ['/^longtext$/', 'NO', 'utf8mb4', 'utf8mb4_unicode_ci'],
            'descriptor_before_sha256' => ['/^char\(64\)$/', 'NO', 'ascii', 'ascii_bin'],
            'descriptor_ready_json' => ['/^longtext$/', 'YES', 'utf8mb4', 'utf8mb4_unicode_ci'],
            'archive_status' => ['/^varchar\(24\)$/', 'NO', 'ascii', 'ascii_bin'],
            'archive_relative_path' => ['/^varchar\(512\)$/', 'YES', 'ascii', 'ascii_bin'],
            'archive_sha256' => ['/^char\(64\)$/', 'YES', 'ascii', 'ascii_bin'],
            'archive_size' => ['/^bigint(?:\([0-9]+\))? unsigned$/', 'YES', null, null],
            'copied_at' => ['/^datetime$/', 'YES', null, null],
        ],
    ];
    $columnDetails = $pdo->prepare('SELECT COLUMN_NAME, COLUMN_TYPE, IS_NULLABLE, CHARACTER_SET_NAME, COLLATION_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=?');
    foreach ($critical as $table => $specifications) {
        $columnDetails->execute([$table]);
        $details = [];
        foreach ($columnDetails->fetchAll(PDO::FETCH_ASSOC) ?: [] as $detail) $details[(string)$detail['COLUMN_NAME']] = $detail;
        foreach ($specifications as $column => [$typePattern, $nullable, $charset, $collation]) {
            $detail = $details[$column] ?? null;
            if (!is_array($detail) || preg_match($typePattern, strtolower((string)$detail['COLUMN_TYPE'])) !== 1
                || (string)$detail['IS_NULLABLE'] !== $nullable
                || ($charset !== null && (string)$detail['CHARACTER_SET_NAME'] !== $charset)
                || ($collation !== null && (string)$detail['COLLATION_NAME'] !== $collation)) {
                throw new RuntimeException('Blackhole column contract is incompatible: ' . $table . '.' . $column);
            }
        }
    }

    $placeholders = implode(',', array_fill(0, count($required), '?'));
    $tables = $pdo->prepare('SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME IN (' . $placeholders . ") AND ENGINE='InnoDB'");
    $tables->execute(array_keys($required));
    if ((int)$tables->fetchColumn() !== count($required)) throw new RuntimeException('Blackhole archive tables must use InnoDB.');

    foreach ([
        ['jbh_events', 'PRIMARY', 0, ['event_id']],
        ['jbh_items', 'PRIMARY', 0, ['event_id', 'item_id']],
        ['jbh_items', 'jbh_items_position', 0, ['event_id', 'item_position']],
        ['jbh_artifacts', 'PRIMARY', 0, ['event_id', 'item_id', 'ordinal']],
    ] as [$table, $index, $nonUnique, $indexColumns]) {
        $statement = $pdo->prepare('SELECT NON_UNIQUE, COLUMN_NAME FROM information_schema.STATISTICS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=? AND INDEX_NAME=? ORDER BY SEQ_IN_INDEX');
        $statement->execute([$table, $index]);
        $rows = $statement->fetchAll(PDO::FETCH_ASSOC) ?: [];
        if ($rows === [] || array_unique(array_map(static fn(array $row): int => (int)$row['NON_UNIQUE'], $rows)) !== [$nonUnique]
            || array_map(static fn(array $row): string => (string)$row['COLUMN_NAME'], $rows) !== $indexColumns) {
            throw new RuntimeException('Blackhole archive index is incompatible: ' . $table . '.' . $index);
        }
    }

    $constraints = $pdo->prepare("SELECT COUNT(*) FROM information_schema.REFERENTIAL_CONSTRAINTS WHERE CONSTRAINT_SCHEMA=DATABASE() AND ((CONSTRAINT_NAME='jbh_items_event_fk' AND TABLE_NAME='jbh_items' AND REFERENCED_TABLE_NAME='jbh_events') OR (CONSTRAINT_NAME='jbh_artifacts_item_fk' AND TABLE_NAME='jbh_artifacts' AND REFERENCED_TABLE_NAME='jbh_items')) AND DELETE_RULE='CASCADE'");
    $constraints->execute();
    if ((int)$constraints->fetchColumn() !== 2) throw new RuntimeException('Blackhole cascade constraints are incomplete.');

    $keyColumns = $pdo->prepare("SELECT CONSTRAINT_NAME, COLUMN_NAME, REFERENCED_COLUMN_NAME FROM information_schema.KEY_COLUMN_USAGE WHERE CONSTRAINT_SCHEMA=DATABASE() AND CONSTRAINT_NAME IN ('jbh_items_event_fk','jbh_artifacts_item_fk') ORDER BY CONSTRAINT_NAME, ORDINAL_POSITION");
    $keyColumns->execute();
    $mappings = [];
    foreach ($keyColumns->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) {
        $mappings[(string)$row['CONSTRAINT_NAME']][] = [(string)$row['COLUMN_NAME'], (string)$row['REFERENCED_COLUMN_NAME']];
    }
    if (($mappings['jbh_items_event_fk'] ?? null) !== [['event_id', 'event_id']]
        || ($mappings['jbh_artifacts_item_fk'] ?? null) !== [['event_id', 'event_id'], ['item_id', 'item_id']]) {
        throw new RuntimeException('Blackhole foreign-key column mappings are incompatible.');
    }
};
