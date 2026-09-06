<?php
declare(strict_types=1);

function jbh_schema_ready(PDO $pdo): bool
{
    try {
        $statement = $pdo->prepare('SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME IN (?,?,?) AND ENGINE=\'InnoDB\'');
        $statement->execute([JBH_EVENTS_TABLE, JBH_ITEMS_TABLE, JBH_ARTIFACTS_TABLE]);
        return (int)$statement->fetchColumn() === 3;
    } catch (Throwable) {
        return false;
    }
}

function jbh_event_page(PDO $pdo, array $filters, int $page, int $perPage = 50): array
{
    $page = max(1, min(100000, $page));
    $perPage = max(1, min(100, $perPage));
    $where = [];
    $params = [];
    foreach (['resource', 'operation'] as $key) {
        if (($filters[$key] ?? '') === '') continue;
        $where[] = 'e.`' . $key . '`=?';
        $params[] = $filters[$key];
    }
    if (($filters['event_id'] ?? '') !== '') {
        $where[] = 'e.event_id=?';
        $params[] = $filters['event_id'];
    }
    if (($filters['item_id'] ?? 0) > 0) {
        $where[] = 'EXISTS (SELECT 1 FROM `' . JBH_ITEMS_TABLE . '` fi WHERE fi.event_id=e.event_id AND fi.item_id=?)';
        $params[] = $filters['item_id'];
    }
    if (($filters['archive_status'] ?? '') !== '') {
        $where[] = 'EXISTS (SELECT 1 FROM `' . JBH_ARTIFACTS_TABLE . '` fa WHERE fa.event_id=e.event_id AND fa.archive_status=?)';
        $params[] = $filters['archive_status'];
    }
    $clause = $where === [] ? '' : ' WHERE ' . implode(' AND ', $where);
    $count = $pdo->prepare('SELECT COUNT(*) FROM `' . JBH_EVENTS_TABLE . '` e' . $clause);
    $count->execute($params);
    $total = (int)$count->fetchColumn();
    $pages = max(1, (int)ceil($total / $perPage));
    $page = min($page, $pages);
    $offset = ($page - 1) * $perPage;
    $sql = 'SELECT e.*,
        (SELECT COUNT(*) FROM `' . JBH_ITEMS_TABLE . '` ci WHERE ci.event_id=e.event_id) AS item_count,
        (SELECT COUNT(*) FROM `' . JBH_ARTIFACTS_TABLE . '` ca WHERE ca.event_id=e.event_id AND ca.archive_status=\'complete\') AS archived_count,
        (SELECT COUNT(*) FROM `' . JBH_ARTIFACTS_TABLE . '` cw WHERE cw.event_id=e.event_id AND cw.archive_status IN (\'missing\',\'unmanaged\')) AS unavailable_count
        FROM `' . JBH_EVENTS_TABLE . '` e' . $clause . ' ORDER BY e.occurred_at DESC, e.event_id DESC LIMIT ' . $perPage . ' OFFSET ' . $offset;
    $statement = $pdo->prepare($sql);
    $statement->execute($params);
    return ['rows' => $statement->fetchAll(PDO::FETCH_ASSOC) ?: [], 'total' => $total, 'page' => $page, 'pages' => $pages];
}

function jbh_event_detail(PDO $pdo, string $eventId): ?array
{
    if (preg_match('/\A[a-f0-9]{64}\z/D', $eventId) !== 1) return null;
    $eventStatement = $pdo->prepare('SELECT * FROM `' . JBH_EVENTS_TABLE . '` WHERE event_id=? LIMIT 1');
    $eventStatement->execute([$eventId]);
    $event = $eventStatement->fetch(PDO::FETCH_ASSOC);
    if (!is_array($event)) return null;
    $items = $pdo->prepare('SELECT * FROM `' . JBH_ITEMS_TABLE . '` WHERE event_id=? ORDER BY item_position');
    $items->execute([$eventId]);
    $artifacts = $pdo->prepare('SELECT event_id, item_id, ordinal, role, managed, source_state, descriptor_before_json, descriptor_ready_json, archive_status, archive_relative_path, archive_sha256, archive_size, copied_at FROM `' . JBH_ARTIFACTS_TABLE . '` WHERE event_id=? ORDER BY item_id, ordinal');
    $artifacts->execute([$eventId]);
    return ['event' => $event, 'items' => $items->fetchAll(PDO::FETCH_ASSOC) ?: [], 'artifacts' => $artifacts->fetchAll(PDO::FETCH_ASSOC) ?: []];
}

function jbh_pretty_json(?string $json): string
{
    if ($json === null || $json === '') return '';
    try {
        return json_encode(json_decode($json, true, 512, JSON_THROW_ON_ERROR), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
    } catch (Throwable) {
        return '[invalid archived JSON]';
    }
}
