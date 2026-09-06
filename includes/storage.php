<?php
declare(strict_types=1);

$GLOBALS['__jbh_created_artifacts'] ??= [];
$GLOBALS['__jbh_shutdown_registered'] ??= false;
$GLOBALS['__jbh_reconciled'] ??= false;

function jbh_path_is_regular_file(string $path): bool
{
    $stat = @lstat($path);
    return is_array($stat) && (($stat['mode'] ?? 0) & 0170000) === 0100000 && !is_link($path);
}

function jbh_existing_directory(string $path): ?string
{
    if (!str_starts_with($path, DIRECTORY_SEPARATOR)) return null;
    $current = DIRECTORY_SEPARATOR;
    foreach (explode(DIRECTORY_SEPARATOR, trim($path, DIRECTORY_SEPARATOR)) as $segment) {
        if ($segment === '') continue;
        $current = $current === DIRECTORY_SEPARATOR ? $current . $segment : $current . DIRECTORY_SEPARATOR . $segment;
        if (is_link($current) || !is_dir($current)) return null;
    }
    $real = realpath($path);
    return $real === false ? null : rtrim($real, DIRECTORY_SEPARATOR);
}

function jbh_storage_directory(bool $create = true): string
{
    $base = jbh_existing_directory(BACKEND_PATH . '/var');
    if ($base === null) throw new RuntimeException('Blackhole runtime base is unavailable.');
    $current = $base;
    foreach (['plugins', JBH_PLUGIN_NAME, 'artifacts'] as $segment) {
        $parent = $current;
        $current .= DIRECTORY_SEPARATOR . $segment;
        if (is_link($current) || (file_exists($current) && !is_dir($current))) {
            throw new RuntimeException('Blackhole storage hierarchy is unsafe.');
        }
        if (!is_dir($current)) {
            if (!$create || !mkdir($current, 02770)) throw new RuntimeException('Blackhole storage hierarchy is unavailable.');
            jbh_sync_directory($parent);
        }
        if (is_dir($current) && !chmod($current, 02770)) {
            throw new RuntimeException('Unable to enforce Blackhole storage permissions.');
        }
    }
    $real = jbh_existing_directory($current);
    if ($real === null || !str_starts_with($real, $base . DIRECTORY_SEPARATOR)) {
        throw new RuntimeException('Blackhole storage escaped its private root.');
    }
    return $real;
}

function jbh_sync_directory(string $directory): void
{
    if (!function_exists('fsync')) return;
    $handle = @fopen($directory, 'r');
    if (!is_resource($handle) || !fsync($handle)) {
        if (is_resource($handle)) fclose($handle);
        throw new RuntimeException('Unable to sync Blackhole archive directory.');
    }
    fclose($handle);
}

function jbh_reconcile_storage(PDO|ResourceLifecycleDatabase $database, int $limit = 100, int $graceSeconds = 86400): void
{
    if ($GLOBALS['__jbh_reconciled']) return;
    $GLOBALS['__jbh_reconciled'] = true;
    try {
        $root = jbh_storage_directory(false);
    } catch (Throwable) {
        return;
    }
    $statement = $database->prepare('SELECT archive_sha256, archive_size FROM `' . JBH_ARTIFACTS_TABLE . '` WHERE archive_relative_path=? AND archive_status=\'complete\' LIMIT 1');
    $cursorPath = $root . '/.reconcile-cursor';
    $cursorRaw = jbh_path_is_regular_file($cursorPath) ? trim((string)file_get_contents($cursorPath)) : '0';
    $cursor = preg_match('/\A[0-9]{1,12}\z/D', $cursorRaw) === 1 ? (int)$cursorRaw : 0;
    $visited = 0;
    $processed = 0;
    $hasMore = false;
    $cutoff = time() - max(3600, $graceSeconds);
    $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS));
    foreach ($iterator as $entry) {
        $path = $entry->getPathname();
        if (hash_equals($cursorPath, $path) || $entry->isLink() || !$entry->isFile() || !jbh_path_is_regular_file($path)) continue;
        if ($visited++ < $cursor) continue;
        if ($processed >= max(1, min(1000, $limit))) { $hasMore = true; break; }
        $processed++;
        if ($entry->getMTime() >= $cutoff) continue;
        $name = $entry->getFilename();
        if (preg_match('/\A\.tmp-[a-f0-9]{32}\z/D', $name) === 1) {
            if (@unlink($path)) jbh_sync_directory(dirname($path));
            continue;
        }
        $relative = str_replace(DIRECTORY_SEPARATOR, '/', substr($path, strlen($root) + 1));
        if (preg_match('/\A[a-f0-9]{2}\/[a-f0-9]{64}\/[1-9][0-9]*-[0-9]+\.blob\z/D', $relative) !== 1) continue;
        $statement->execute([$relative]);
        $row = $statement->fetch(PDO::FETCH_ASSOC);
        if (is_array($row)) {
            $hash = hash_file('sha256', $path);
            $size = (int)filesize($path);
            if (!is_string($hash) || !hash_equals((string)$row['archive_sha256'], $hash) || (int)$row['archive_size'] !== $size) {
                error_log('[jyavani-blackhole] Retained a tracked archive with an integrity mismatch: ' . $relative);
            }
            continue;
        }
        if (@unlink($path)) jbh_sync_directory(dirname($path));
    }
    $nextCursor = $hasMore ? $cursor + $processed : 0;
    $temporaryCursor = $root . '/.tmp-' . bin2hex(random_bytes(16));
    if (file_put_contents($temporaryCursor, (string)$nextCursor . PHP_EOL, LOCK_EX) !== false
        && chmod($temporaryCursor, 0660) && rename($temporaryCursor, $cursorPath)) {
        jbh_sync_directory($root);
    } else {
        @unlink($temporaryCursor);
    }
}

function jbh_archive_relative(string $eventId, int $itemId, int $ordinal): string
{
    if (preg_match('/\A[a-f0-9]{64}\z/D', $eventId) !== 1 || $itemId <= 0 || $ordinal < 0 || $ordinal > 65535) {
        throw new InvalidArgumentException('Invalid Blackhole archive identity.');
    }
    return substr($eventId, 0, 2) . '/' . $eventId . '/' . $itemId . '-' . $ordinal . '.blob';
}

function jbh_ensure_archive_parent(string $root, string $relative): string
{
    $segments = explode('/', $relative);
    $basename = array_pop($segments);
    $current = $root;
    foreach ($segments as $segment) {
        if ($segment === '' || $segment === '.' || $segment === '..') throw new RuntimeException('Invalid Blackhole archive path.');
        $current .= DIRECTORY_SEPARATOR . $segment;
        if (is_link($current) || (file_exists($current) && !is_dir($current))) throw new RuntimeException('Unsafe Blackhole archive directory.');
        if (!is_dir($current)) {
            $parent = dirname($current);
            if (!mkdir($current, 02770)) throw new RuntimeException('Unable to create Blackhole archive directory.');
            jbh_sync_directory($parent);
        }
        if (!chmod($current, 02770)) throw new RuntimeException('Unable to enforce Blackhole archive permissions.');
    }
    $parent = jbh_existing_directory($current);
    if ($parent === null || !str_starts_with($parent, $root . DIRECTORY_SEPARATOR)) throw new RuntimeException('Blackhole archive parent escaped storage.');
    return $parent . DIRECTORY_SEPARATOR . $basename;
}

function jbh_purge_source(array $event, array $item, array $artifact): array
{
    $resource = (string)$event['resource'];
    $itemId = (int)$item['id'];
    $relative = $artifact['relative_path'] ?? null;
    $absolute = $artifact['absolute_path'] ?? null;
    if (!in_array($resource, ['media', 'file'], true) || $itemId <= 0
        || ($artifact['managed'] ?? null) !== true || ($artifact['state'] ?? null) !== 'present'
        || ($artifact['root'] ?? null) !== 'asset.recovery' || ($artifact['disk'] ?? null) !== 'recovery'
        || ($artifact['transition']['operation'] ?? null) !== 'delete'
        || !is_string($relative) || !is_string($absolute)
        || preg_match('/\A' . preg_quote($resource, '/') . '\/' . $itemId . '\/\.purge-recovery-[a-f0-9]{32}\z/D', $relative) !== 1) {
        throw new RuntimeException('Blackhole received an unsafe purge artifact descriptor.');
    }
    $pluginPath = defined('PLUGIN_PATH') ? realpath(PLUGIN_PATH) : false;
    $projectRoot = $pluginPath === false ? false : realpath(dirname($pluginPath));
    $quarantineRoot = $projectRoot === false ? false : jbh_existing_directory($projectRoot . '/private_files/.asset-trash');
    if ($quarantineRoot === null) throw new RuntimeException('Core quarantine storage is unavailable.');
    $candidate = $quarantineRoot . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relative);
    if (!hash_equals($candidate, $absolute) || !jbh_path_is_regular_file($candidate)) {
        throw new RuntimeException('Blackhole purge artifact is outside Core quarantine storage.');
    }
    $real = realpath($candidate);
    if ($real === false || !hash_equals($candidate, $real) || !str_starts_with($real, $quarantineRoot . DIRECTORY_SEPARATOR)) {
        throw new RuntimeException('Blackhole purge artifact containment check failed.');
    }
    $stat = lstat($candidate);
    if (!is_array($stat)) throw new RuntimeException('Blackhole purge artifact identity is unavailable.');
    return ['path' => $candidate, 'stat' => $stat];
}

function jbh_register_created_artifact(string $eventId, string $path, string $relative, string $sha256, int $size): void
{
    $GLOBALS['__jbh_created_artifacts'][$path] = compact('eventId', 'path', 'relative', 'sha256', 'size');
    if ($GLOBALS['__jbh_shutdown_registered']) return;
    $GLOBALS['__jbh_shutdown_registered'] = true;
    register_shutdown_function('jbh_cleanup_uncommitted_artifacts');
}

function jbh_cleanup_uncommitted_artifacts(): void
{
    $created = $GLOBALS['__jbh_created_artifacts'] ?? [];
    if ($created === []) return;
    $pdo = $GLOBALS['pdo'] ?? null;
    if (!$pdo instanceof PDO || $pdo->inTransaction()) return;
    try {
        $statement = $pdo->prepare('SELECT archive_sha256, archive_size FROM `' . JBH_ARTIFACTS_TABLE . '` WHERE event_id=? AND archive_relative_path=? AND archive_status=\'complete\' LIMIT 1');
        foreach ($created as $entry) {
            $statement->execute([$entry['eventId'], $entry['relative']]);
            $row = $statement->fetch(PDO::FETCH_ASSOC);
            if (is_array($row) && hash_equals($entry['sha256'], (string)$row['archive_sha256']) && (int)$row['archive_size'] === $entry['size']) continue;
            if (jbh_path_is_regular_file($entry['path'])) @unlink($entry['path']);
        }
    } catch (Throwable $error) {
        error_log('[jyavani-blackhole] Artifact cleanup check failed: ' . $error->getMessage());
    }
}

function jbh_forget_created_event(string $eventId): void
{
    foreach ($GLOBALS['__jbh_created_artifacts'] ?? [] as $path => $entry) {
        if (hash_equals($eventId, (string)$entry['eventId'])) unset($GLOBALS['__jbh_created_artifacts'][$path]);
    }
}

function jbh_verify_event_artifacts(PDO|ResourceLifecycleDatabase $database, string $eventId): void
{
    $statement = $database->prepare('SELECT archive_relative_path, archive_sha256, archive_size FROM `' . JBH_ARTIFACTS_TABLE . '` WHERE event_id=? AND archive_status=\'complete\'');
    $statement->execute([$eventId]);
    $rows = $statement->fetchAll(PDO::FETCH_ASSOC) ?: [];
    if ($rows === []) return;
    $root = jbh_storage_directory(false);
    foreach ($rows as $row) {
        $relative = (string)($row['archive_relative_path'] ?? '');
        if (preg_match('/\A[a-f0-9]{2}\/[a-f0-9]{64}\/[1-9][0-9]*-[0-9]+\.blob\z/D', $relative) !== 1) {
            throw new RuntimeException('Blackhole archive row contains an unsafe path.');
        }
        $path = $root . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relative);
        $hash = jbh_path_is_regular_file($path) ? hash_file('sha256', $path) : false;
        if (!is_string($hash) || !hash_equals((string)$row['archive_sha256'], $hash)
            || (int)$row['archive_size'] !== (int)filesize($path)) {
            throw new RuntimeException('Blackhole finalized archive integrity check failed.');
        }
    }
}

function jbh_copy_purge_artifact(array $event, array $item, array $artifact, int $ordinal, PDO|ResourceLifecycleDatabase|null $database = null): array
{
    if ($database !== null) jbh_reconcile_storage($database);
    $source = jbh_purge_source($event, $item, $artifact);
    $relative = jbh_archive_relative((string)$event['event_id'], (int)$item['id'], $ordinal);
    $root = jbh_storage_directory(true);
    $final = jbh_ensure_archive_parent($root, $relative);
    if (jbh_path_is_regular_file($final)) {
        $sourceHash = hash_file('sha256', $source['path']);
        $finalHash = hash_file('sha256', $final);
        $sourceSize = (int)filesize($source['path']);
        $finalSize = (int)filesize($final);
        if (!is_string($sourceHash) || !is_string($finalHash) || !hash_equals($sourceHash, $finalHash) || $sourceSize !== $finalSize) {
            throw new RuntimeException('Existing Blackhole archive does not match the purge artifact.');
        }
        return ['relative' => $relative, 'sha256' => $finalHash, 'size' => $finalSize, 'created' => false, 'path' => $final];
    }
    if (file_exists($final) || is_link($final)) throw new RuntimeException('Blackhole archive target is unsafe.');

    $input = fopen($source['path'], 'rb');
    if (!is_resource($input)) throw new RuntimeException('Unable to open Core purge artifact.');
    $sourceHandleStat = fstat($input);
    if (!is_array($sourceHandleStat) || (int)$sourceHandleStat['dev'] !== (int)$source['stat']['dev']
        || (int)$sourceHandleStat['ino'] !== (int)$source['stat']['ino']) {
        fclose($input);
        throw new RuntimeException('Core purge artifact changed before archival.');
    }
    $temporary = dirname($final) . '/.tmp-' . bin2hex(random_bytes(16));
    $output = fopen($temporary, 'x+b');
    if (!is_resource($output)) {
        fclose($input);
        throw new RuntimeException('Unable to create Blackhole temporary archive.');
    }
    $hash = hash_init('sha256');
    $size = 0;
    $published = false;
    try {
        while (!feof($input)) {
            $chunk = fread($input, 1048576);
            if ($chunk === false) throw new RuntimeException('Unable to read Core purge artifact.');
            if ($chunk === '') continue;
            hash_update($hash, $chunk);
            $length = strlen($chunk);
            $offset = 0;
            while ($offset < $length) {
                $written = fwrite($output, substr($chunk, $offset));
                if ($written === false || $written === 0) throw new RuntimeException('Unable to write Blackhole archive.');
                $offset += $written;
            }
            $size += $length;
        }
        if (!fflush($output) || (function_exists('fsync') && !fsync($output)) || !chmod($temporary, 0660)) {
            throw new RuntimeException('Unable to finalize Blackhole temporary archive.');
        }
        $sourceAfter = fstat($input);
        $pathAfter = lstat($source['path']);
        foreach (['dev', 'ino', 'size', 'mtime', 'ctime'] as $key) {
            if (!is_array($sourceAfter) || !is_array($pathAfter)
                || (int)$sourceAfter[$key] !== (int)$sourceHandleStat[$key]
                || (int)$pathAfter[$key] !== (int)$sourceHandleStat[$key]) {
                throw new RuntimeException('Core purge artifact changed during archival.');
            }
        }
        fclose($input);
        $input = null;
        fclose($output);
        $output = null;
        if (!link($temporary, $final)) throw new RuntimeException('Unable to publish Blackhole archive atomically.');
        $published = true;
        if (!unlink($temporary)) throw new RuntimeException('Unable to remove Blackhole temporary archive.');
        jbh_sync_directory(dirname($final));
        $sha256 = hash_final($hash);
        if (!jbh_path_is_regular_file($final) || (int)filesize($final) !== $size || !hash_equals($sha256, hash_file('sha256', $final))) {
            @unlink($final);
            jbh_sync_directory(dirname($final));
            throw new RuntimeException('Blackhole archive verification failed.');
        }
        jbh_register_created_artifact((string)$event['event_id'], $final, $relative, $sha256, $size);
        return ['relative' => $relative, 'sha256' => $sha256, 'size' => $size, 'created' => true, 'path' => $final];
    } catch (Throwable $error) {
        if (is_resource($input)) fclose($input);
        if (is_resource($output)) fclose($output);
        @unlink($temporary);
        if ($published && jbh_path_is_regular_file($final)) {
            @unlink($final);
            try { jbh_sync_directory(dirname($final)); } catch (Throwable) {}
        }
        throw $error;
    }
}

function jbh_remove_storage_tree(string $path): void
{
    if (is_link($path)) throw new RuntimeException('Blackhole storage contains a symbolic link.');
    if (is_file($path)) {
        if (!unlink($path)) throw new RuntimeException('Unable to remove Blackhole archive file.');
        return;
    }
    if (!is_dir($path)) return;
    foreach (scandir($path) ?: [] as $entry) {
        if ($entry !== '.' && $entry !== '..') jbh_remove_storage_tree($path . DIRECTORY_SEPARATOR . $entry);
    }
    if (!rmdir($path)) throw new RuntimeException('Unable to remove Blackhole archive directory.');
}
