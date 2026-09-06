<?php
declare(strict_types=1);

function jbh_supported_event(array $event): bool
{
    return in_array($event['resource'] ?? null, ['media', 'file'], true)
        && in_array($event['operation'] ?? null, ['trash', 'restore', 'purge'], true);
}

function jbh_json(mixed $value): string
{
    return json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
}

function jbh_positive_item_id(mixed $value): int
{
    if (!is_int($value) && !(is_string($value) && preg_match('/\A[1-9][0-9]*\z/D', $value) === 1)) {
        throw new InvalidArgumentException('Blackhole supports only positive numeric resource IDs.');
    }
    $id = filter_var($value, FILTER_VALIDATE_INT);
    if ($id === false || $id <= 0) throw new InvalidArgumentException('Blackhole resource ID is out of range.');
    return $id;
}

function jbh_require_event_row(ResourceLifecycleDatabase $database, string $eventId): array
{
    $statement = $database->prepare('SELECT * FROM `' . JBH_EVENTS_TABLE . '` WHERE event_id=? LIMIT 1');
    $statement->execute([$eventId]);
    $row = $statement->fetch(PDO::FETCH_ASSOC);
    if (!is_array($row)) throw new RuntimeException('Blackhole event capture is missing.');
    return $row;
}

function jbh_resource_before_mutation(array $event, ResourceLifecycleDatabase $database): void
{
    if (!jbh_supported_event($event)) return;
    if (!function_exists('resource_lifecycle_event')) throw new RuntimeException('Jyavani resource lifecycle contract is unavailable.');
    $event = resource_lifecycle_event($event);
    $metadata = jbh_json($event['metadata']);
    $metadataHash = hash('sha256', $metadata);

    $existing = $database->prepare('SELECT contract_schema, resource, operation, bulk, actor_id, source, occurred_at, item_count, metadata_sha256 FROM `' . JBH_EVENTS_TABLE . '` WHERE event_id=? LIMIT 1');
    $existing->execute([$event['event_id']]);
    $eventRow = $existing->fetch(PDO::FETCH_ASSOC);
    if ($eventRow === false) {
        $insert = $database->prepare('INSERT INTO `' . JBH_EVENTS_TABLE . '` (event_id, contract_schema, resource, operation, bulk, actor_id, source, occurred_at, item_count, metadata_json, metadata_sha256, result_json, warnings_json, phase) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?)');
        $insert->execute([
            $event['event_id'], $event['schema'], $event['resource'], $event['operation'], $event['bulk'] ? 1 : 0,
            $event['actor_id'], $event['source'], $event['occurred_at'], count($event['items']), $metadata, $metadataHash, '[]', '[]', 'captured',
        ]);
    } else {
        $identity = [
            (int)$eventRow['contract_schema'] === $event['schema'],
            hash_equals((string)$eventRow['resource'], $event['resource']),
            hash_equals((string)$eventRow['operation'], $event['operation']),
            (int)$eventRow['bulk'] === ($event['bulk'] ? 1 : 0),
            ($eventRow['actor_id'] === null ? null : (int)$eventRow['actor_id']) === $event['actor_id'],
            hash_equals((string)$eventRow['source'], $event['source']),
            hash_equals((string)$eventRow['occurred_at'], $event['occurred_at']),
            (int)$eventRow['item_count'] === count($event['items']),
            hash_equals((string)$eventRow['metadata_sha256'], $metadataHash),
        ];
        if (in_array(false, $identity, true)) throw new RuntimeException('Blackhole event identity collision.');
    }

    $findItem = $database->prepare('SELECT item_position, before_sha256 FROM `' . JBH_ITEMS_TABLE . '` WHERE event_id=? AND item_id=? LIMIT 1');
    $insertItem = $database->prepare('INSERT INTO `' . JBH_ITEMS_TABLE . '` (event_id, item_id, item_position, before_json, before_sha256) VALUES (?,?,?,?,?)');
    $findArtifact = $database->prepare('SELECT role, managed, source_state, descriptor_before_sha256 FROM `' . JBH_ARTIFACTS_TABLE . '` WHERE event_id=? AND item_id=? AND ordinal=? LIMIT 1');
    $insertArtifact = $database->prepare('INSERT INTO `' . JBH_ARTIFACTS_TABLE . '` (event_id, item_id, ordinal, role, managed, source_state, descriptor_before_json, descriptor_before_sha256, archive_status) VALUES (?,?,?,?,?,?,?,?,?)');
    $countArtifacts = $database->prepare('SELECT COUNT(*) FROM `' . JBH_ARTIFACTS_TABLE . '` WHERE event_id=? AND item_id=?');

    foreach ($event['items'] as $position => $item) {
        $itemId = jbh_positive_item_id($item['id']);
        $before = jbh_json($item['before']);
        $beforeHash = hash('sha256', $before);
        $findItem->execute([$event['event_id'], $itemId]);
        $itemRow = $findItem->fetch(PDO::FETCH_ASSOC);
        if ($itemRow === false) {
            $insertItem->execute([$event['event_id'], $itemId, $position, $before, $beforeHash]);
        } elseif ((int)$itemRow['item_position'] !== $position || !hash_equals((string)$itemRow['before_sha256'], $beforeHash)) {
            throw new RuntimeException('Blackhole item identity collision.');
        }

        $expectedPurgeArtifact = false;
        foreach ($item['artifacts'] as $ordinal => $artifact) {
            if ($event['operation'] === 'purge' && is_string($item['before']['quarantine_path'] ?? null)
                && $item['before']['quarantine_path'] !== '' && ($artifact['state'] ?? null) !== 'present') {
                throw new RuntimeException('Blackhole blocked purge because expected managed bytes are unavailable.');
            }
            if ($event['operation'] === 'purge' && ($artifact['managed'] ?? false) && ($artifact['state'] ?? null) === 'present') {
                $expectedPurgeArtifact = true;
            }
            $descriptor = jbh_json($artifact);
            $descriptorHash = hash('sha256', $descriptor);
            $status = ($artifact['state'] ?? null) === 'missing' ? 'missing'
                : (($artifact['state'] ?? null) === 'unmanaged' ? 'unmanaged'
                    : ($event['operation'] === 'purge' && ($artifact['managed'] ?? false) && ($artifact['state'] ?? null) === 'present' ? 'pending' : 'not_applicable'));
            $findArtifact->execute([$event['event_id'], $itemId, $ordinal]);
            $artifactRow = $findArtifact->fetch(PDO::FETCH_ASSOC);
            if ($artifactRow === false) {
                $insertArtifact->execute([
                    $event['event_id'], $itemId, $ordinal, $artifact['role'], $artifact['managed'] ? 1 : 0,
                    $artifact['state'], $descriptor, $descriptorHash, $status,
                ]);
            } elseif (!hash_equals((string)$artifactRow['role'], (string)$artifact['role'])
                || (int)$artifactRow['managed'] !== ($artifact['managed'] ? 1 : 0)
                || !hash_equals((string)$artifactRow['source_state'], (string)$artifact['state'])
                || !hash_equals((string)$artifactRow['descriptor_before_sha256'], $descriptorHash)) {
                throw new RuntimeException('Blackhole artifact identity collision.');
            }
        }
        if ($event['operation'] === 'purge' && is_string($item['before']['quarantine_path'] ?? null)
            && $item['before']['quarantine_path'] !== '' && !$expectedPurgeArtifact) {
            throw new RuntimeException('Blackhole blocked purge because expected managed bytes are unavailable.');
        }
        $countArtifacts->execute([$event['event_id'], $itemId]);
        if ((int)$countArtifacts->fetchColumn() !== count($item['artifacts'])) throw new RuntimeException('Blackhole artifact set is inconsistent.');
    }
    $countItems = $database->prepare('SELECT COUNT(*) FROM `' . JBH_ITEMS_TABLE . '` WHERE event_id=?');
    $countItems->execute([$event['event_id']]);
    if ((int)$countItems->fetchColumn() !== count($event['items'])) throw new RuntimeException('Blackhole item set is inconsistent.');
}

function jbh_resource_before_commit(array $event, ResourceLifecycleDatabase $database): void
{
    if (!jbh_supported_event($event)) return;
    $event = resource_lifecycle_event($event);
    $eventRow = jbh_require_event_row($database, $event['event_id']);
    if (!hash_equals((string)$eventRow['resource'], $event['resource']) || !hash_equals((string)$eventRow['operation'], $event['operation'])) {
        throw new RuntimeException('Blackhole event changed before commit.');
    }
    $readyHash = hash('sha256', jbh_json(['items' => $event['items'], 'result' => $event['result'], 'warnings' => $event['warnings']]));
    if (($eventRow['phase'] ?? null) === 'ready') {
        if (!is_string($eventRow['ready_sha256'] ?? null) || !hash_equals($eventRow['ready_sha256'], $readyHash)) {
            throw new RuntimeException('Blackhole finalized event identity collision.');
        }
        jbh_verify_event_artifacts($database, (string)$event['event_id']);
        return;
    }
    if (($eventRow['phase'] ?? null) !== 'captured' || (int)($eventRow['item_count'] ?? 0) !== count($event['items'])) {
        throw new RuntimeException('Blackhole event phase or item set is inconsistent.');
    }

    $findItem = $database->prepare('SELECT before_sha256 FROM `' . JBH_ITEMS_TABLE . '` WHERE event_id=? AND item_id=? LIMIT 1');
    $updateItem = $database->prepare('UPDATE `' . JBH_ITEMS_TABLE . '` SET after_json=?, after_sha256=? WHERE event_id=? AND item_id=?');
    $findArtifacts = $database->prepare('SELECT ordinal, descriptor_before_sha256, archive_status, archive_relative_path, archive_sha256, archive_size FROM `' . JBH_ARTIFACTS_TABLE . '` WHERE event_id=? AND item_id=? ORDER BY ordinal');
    $updateArtifact = $database->prepare('UPDATE `' . JBH_ARTIFACTS_TABLE . '` SET descriptor_ready_json=?, archive_status=?, archive_relative_path=?, archive_sha256=?, archive_size=?, copied_at=? WHERE event_id=? AND item_id=? AND ordinal=?');
    $created = [];
    try {
        foreach ($event['items'] as $item) {
            $itemId = jbh_positive_item_id($item['id']);
            $before = jbh_json($item['before']);
            $findItem->execute([$event['event_id'], $itemId]);
            $itemRow = $findItem->fetch(PDO::FETCH_ASSOC);
            if (!is_array($itemRow) || !hash_equals((string)$itemRow['before_sha256'], hash('sha256', $before))) {
                throw new RuntimeException('Blackhole before snapshot changed before commit.');
            }
            $after = $item['after'] === null ? null : jbh_json($item['after']);
            $updateItem->execute([$after, $after === null ? null : hash('sha256', $after), $event['event_id'], $itemId]);

            $findArtifacts->execute([$event['event_id'], $itemId]);
            $storedArtifacts = $findArtifacts->fetchAll(PDO::FETCH_ASSOC) ?: [];
            if (count($storedArtifacts) !== count($item['artifacts'])) throw new RuntimeException('Blackhole artifact set changed before commit.');
            foreach ($item['artifacts'] as $ordinal => $artifact) {
                $stored = $storedArtifacts[$ordinal] ?? null;
                if (!is_array($stored) || (int)$stored['ordinal'] !== $ordinal) throw new RuntimeException('Blackhole artifact order changed before commit.');
                $readyDescriptor = jbh_json($artifact);
                $status = (string)$stored['archive_status'];
                $relative = $stored['archive_relative_path'];
                $sha256 = $stored['archive_sha256'];
                $size = $stored['archive_size'] === null ? null : (int)$stored['archive_size'];
                $copiedAt = null;
                if ($event['operation'] === 'purge' && ($artifact['managed'] ?? false) && ($artifact['state'] ?? null) === 'present') {
                    $copy = jbh_copy_purge_artifact($event, $item, $artifact, $ordinal, $database);
                    $status = 'complete';
                    $relative = $copy['relative'];
                    $sha256 = $copy['sha256'];
                    $size = $copy['size'];
                    $copiedAt = gmdate('Y-m-d H:i:s');
                    if ($copy['created']) $created[] = $copy['path'];
                }
                $updateArtifact->execute([$readyDescriptor, $status, $relative, $sha256, $size, $copiedAt, $event['event_id'], $itemId, $ordinal]);
            }
        }
        $result = jbh_json($event['result']);
        $warnings = jbh_json($event['warnings']);
        $updateEvent = $database->prepare('UPDATE `' . JBH_EVENTS_TABLE . '` SET result_json=?, warnings_json=?, phase=\'ready\', ready_sha256=?, ready_at=CURRENT_TIMESTAMP WHERE event_id=?');
        $updateEvent->execute([$result, $warnings, $readyHash, $event['event_id']]);
    } catch (Throwable $error) {
        foreach ($created as $path) {
            if (jbh_path_is_regular_file($path)) @unlink($path);
            unset($GLOBALS['__jbh_created_artifacts'][$path]);
        }
        throw $error;
    }
}

function jbh_resource_committed(array $event, ResourceLifecycleDatabase $database): void
{
    if (jbh_supported_event($event)) jbh_forget_created_event((string)$event['event_id']);
}
