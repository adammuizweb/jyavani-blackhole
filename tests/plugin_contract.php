<?php
declare(strict_types=1);

$root = dirname(__DIR__);
$core = '/var/www/jyavani.lan';
$failures = [];
$checks = 0;
$check = static function (bool $condition, string $message) use (&$failures, &$checks): void {
    $checks++;
    echo ($condition ? 'PASS' : 'FAIL') . ' ' . $message . PHP_EOL;
    if (!$condition) $failures[] = $message;
};

$manifest = json_decode((string)file_get_contents($root . '/plugin.json'), true, 512, JSON_THROW_ON_ERROR);
$check(($manifest['name'] ?? '') === 'jyavani-blackhole' && ($manifest['version'] ?? '') === '0.1.0', 'manifest has stable plugin identity');
$check(($manifest['requires']['jyavani'] ?? '') === '>=2.3.106', 'manifest requires the resource lifecycle Core release');
$permission = $manifest['permissions'][0] ?? [];
$check(($permission['key'] ?? '') === 'plugin.jyavani-blackhole.audit.read'
    && ($permission['supports_scope'] ?? null) === false && ($permission['delegable'] ?? null) === false,
    'archive access uses a nondelegable dynamic permission');
$pages = $manifest['admin']['pages'] ?? [];
$check(count($pages) === 2 && array_column($pages, 'permission') === array_fill(0, 2, 'plugin.jyavani-blackhole.audit.read'),
    'visible and hidden dashboard routes share the archive permission');
$copy = $manifest['static']['copy'][0] ?? [];
$check(($copy['to'] ?? '') === 'static/plugins/jyavani-blackhole/admin.css'
    && ($manifest['assets'] ?? null) === ['css' => [], 'js' => []], 'static publication stays inside the plugin namespace');

define('BACKEND_PATH', sys_get_temp_dir() . '/jbh-contract-cfg');
define('PLUGIN_PATH', $root);
define('PLUGIN_SYSTEM_LOADED', true);
require_once $core . '/cfg/helpers/hooks.php';
require_once $core . '/cfg/helpers/resource_lifecycle.php';
require_once $root . '/plugin.php';
$check(has_action('resource_lifecycle_before_mutation', 'jbh_resource_before_mutation')
    && has_action('resource_lifecycle_before_commit', 'jbh_resource_before_commit')
    && has_action('resource_lifecycle_committed', 'jbh_resource_committed'), 'plugin registers all lifecycle phases');
$check(has_action('plugin_uninstall', 'jbh_uninstall') && has_action('admin_head', 'jbh_admin_assets'),
    'plugin registers cleanup and route-scoped dashboard assets');

$pluginSource = (string)file_get_contents($root . '/plugin.php');
$archiveSource = (string)file_get_contents($root . '/includes/archive.php');
$detailSource = (string)file_get_contents($root . '/admin/detail.php');
$lifecycleSource = (string)file_get_contents($root . '/includes/lifecycle.php');
$check(!str_contains($pluginSource, 'register_resource_lifecycle_provider')
    && !str_contains($pluginSource, 'register_frontend_route'), 'plugin observes Core resources without claiming providers or public routes');
$check(!str_contains($archiveSource, '$GLOBALS[\'pdo\']')
    && str_contains($archiveSource, 'ResourceLifecycleDatabase'), 'transactional archive callbacks use only the lifecycle database facade');
$check(str_contains($pluginSource, '__($source, JBH_PLUGIN_NAME)')
    && is_file($root . '/migrations/0003-blackhole-translations.php'), 'dashboard text uses the plugin translation scope');
$check(str_contains($detailSource, 'Restore is not available yet.')
    && !preg_match('/<form|type="submit"|download=/i', $detailSource), 'detail view does not expose an unsafe restore or artifact download action');
$check(str_contains($lifecycleSource, 'file_exists($storage) && !is_dir($storage)')
    && str_contains($lifecycleSource, '.jyavani-blackhole-uninstall-'), 'complete uninstall stages storage and rejects unexpected root nodes');

if ($failures !== []) {
    fwrite(STDERR, count($failures) . " plugin contract check(s) failed.\n");
    exit(1);
}
echo "Blackhole plugin contract passed ({$checks} checks).\n";
