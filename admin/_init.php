<?php
declare(strict_types=1);

if (!defined('DASHBOARD_CONTEXT')) exit;
$pdo = $GLOBALS['pdo'] ?? null;
if (!$pdo instanceof PDO) {
    adiwira_render_404();
    return;
}
[$jbhUserId] = adiwira_require_permission($pdo, JBH_AUDIT_READ_PERMISSION, false);
if (!jbh_schema_ready($pdo)) throw new RuntimeException(jbh_t('Blackhole archive storage is unavailable.'));
jbh_reconcile_storage($pdo);
$jbhBase = rtrim((string)ADMIN_BASE_PATH, '/') . '/?page=admin/tools/jyavani-blackhole';
