<?php
declare(strict_types=1);

function jbh_uninstall(string $name): void
{
    if ($name !== JBH_PLUGIN_NAME) return;
    $pdo = $GLOBALS['pdo'] ?? null;
    if (!$pdo instanceof PDO) throw new RuntimeException('Blackhole database is unavailable.');

    $base = jbh_existing_directory(BACKEND_PATH . '/var');
    if ($base === null) throw new RuntimeException('Blackhole runtime base is unavailable.');
    $pluginsDirectory = $base . '/plugins';
    if (is_link($pluginsDirectory) || (file_exists($pluginsDirectory) && !is_dir($pluginsDirectory))) {
        throw new RuntimeException('Blackhole runtime plugin directory is unsafe.');
    }
    $storage = $pluginsDirectory . '/' . JBH_PLUGIN_NAME;
    $tableCount = $pdo->prepare('SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME IN (?,?,?)');
    $tableCount->execute([JBH_EVENTS_TABLE, JBH_ITEMS_TABLE, JBH_ARTIFACTS_TABLE]);
    $remainingTables = (int)$tableCount->fetchColumn();
    $residuals = [];
    if (is_dir($pluginsDirectory)) {
        foreach (scandir($pluginsDirectory) ?: [] as $entry) {
            if (preg_match('/\A\.jyavani-blackhole-uninstall-[a-f0-9]{32}\z/D', $entry) === 1) {
                $residuals[] = $pluginsDirectory . '/' . $entry;
            }
        }
    }
    if (count($residuals) > 1) throw new RuntimeException('Multiple Blackhole uninstall recovery directories require manual review.');
    if ($residuals !== []) {
        $residual = $residuals[0];
        if (is_link($residual) || !is_dir($residual)) throw new RuntimeException('Blackhole uninstall recovery path is unsafe.');
        if ($remainingTables === 0) {
            jbh_remove_storage_tree($residual);
        } elseif (!file_exists($storage)) {
            if (!rename($residual, $storage)) throw new RuntimeException('Unable to restore staged Blackhole storage.');
            jbh_sync_directory($pluginsDirectory);
        } else {
            throw new RuntimeException('Blackhole live and staged storage both exist.');
        }
    }
    $quarantine = null;
    if (is_link($storage)) throw new RuntimeException('Blackhole storage root is a symbolic link.');
    if (file_exists($storage) && !is_dir($storage)) throw new RuntimeException('Blackhole storage root is not a directory.');
    if (is_dir($storage)) {
        $real = realpath($storage);
        if ($real === false || !str_starts_with($real, $base . DIRECTORY_SEPARATOR)) throw new RuntimeException('Blackhole storage root escaped runtime storage.');
        $quarantine = dirname($real) . '/.jyavani-blackhole-uninstall-' . bin2hex(random_bytes(16));
        try {
            if (!rename($real, $quarantine)) throw new RuntimeException('Unable to stage Blackhole storage removal.');
            jbh_sync_directory(dirname($real));
        } catch (Throwable $error) {
            if (is_dir($quarantine) && !file_exists($real)) @rename($quarantine, $real);
            throw $error;
        }
    }
    try {
        $pdo->exec('DROP TABLE IF EXISTS `' . JBH_ARTIFACTS_TABLE . '`, `' . JBH_ITEMS_TABLE . '`, `' . JBH_EVENTS_TABLE . '`');
    } catch (Throwable $error) {
        if ($quarantine !== null && is_dir($quarantine) && !file_exists($storage) && rename($quarantine, $storage)) {
            try { jbh_sync_directory(dirname($storage)); } catch (Throwable) {}
        }
        throw $error;
    }
    try {
        $translations = $pdo->prepare('DELETE FROM ui_translations WHERE scope=?');
        $translations->execute([JBH_PLUGIN_NAME]);
    } catch (Throwable $error) {
        error_log('[jyavani-blackhole] Unable to remove translation rows: ' . $error->getMessage());
    }
    if ($quarantine !== null) jbh_remove_storage_tree($quarantine);
}
