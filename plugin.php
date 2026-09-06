<?php
declare(strict_types=1);

if (!defined('PLUGIN_SYSTEM_LOADED') || !defined('BACKEND_PATH')) return;

const JBH_VERSION = '0.1.0';
const JBH_PLUGIN_NAME = 'jyavani-blackhole';
const JBH_AUDIT_READ_PERMISSION = 'plugin.jyavani-blackhole.audit.read';
const JBH_EVENTS_TABLE = 'jbh_events';
const JBH_ITEMS_TABLE = 'jbh_items';
const JBH_ARTIFACTS_TABLE = 'jbh_artifacts';

function jbh_h(mixed $value): string
{
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

function jbh_t(string $source, mixed ...$arguments): string
{
    $translated = function_exists('__') ? __($source, JBH_PLUGIN_NAME) : $source;
    return $arguments === [] ? $translated : sprintf($translated, ...$arguments);
}

require_once __DIR__ . '/includes/storage.php';
require_once __DIR__ . '/includes/archive.php';
require_once __DIR__ . '/includes/repository.php';
require_once __DIR__ . '/includes/lifecycle.php';

function jbh_admin_assets(): void
{
    $page = trim((string)($_GET['page'] ?? ''), '/');
    if ($page !== 'admin/tools/jyavani-blackhole' && !str_starts_with($page, 'admin/tools/jyavani-blackhole/')) return;
    echo '<link rel="stylesheet" href="/static/plugins/jyavani-blackhole/admin.css?v=' . rawurlencode(JBH_VERSION) . '">' . PHP_EOL;
}

add_action('admin_head', 'jbh_admin_assets');
add_action('resource_lifecycle_before_mutation', 'jbh_resource_before_mutation', 10);
add_action('resource_lifecycle_before_commit', 'jbh_resource_before_commit', 10);
add_action('resource_lifecycle_committed', 'jbh_resource_committed', 10);
add_action('plugin_uninstall', 'jbh_uninstall', 10);
