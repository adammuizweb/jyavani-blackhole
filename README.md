# Jyavani Blackhole

Private, fail-closed lifecycle archival for Jyavani CMS Media and File resources.

## Requirements

- Jyavani CMS 2.3.106 or newer
- PHP 8.1+
- MySQL or MariaDB with InnoDB

Install through Jyavani Plugin Manager so Core applies append-only migrations and publishes the dashboard stylesheet safely.

## Behavior

- Captures complete before/after snapshots for Media and File trash, restore, and purge operations.
- Copies managed purge bytes into private plugin runtime storage before Core commits permanent deletion.
- Aborts purge when a required snapshot or artifact cannot be archived and verified.
- Exposes a permission-protected, read-only audit list and detail view.
- Keeps archives indefinitely in version 0.1.0.

Runtime files are stored below `BACKEND_PATH/var/plugins/jyavani-blackhole/` and are not part of plugin updates.
Old temporary and unreferenced crash artifacts are reconciled in bounded batches during later purge and dashboard requests. Referenced files with integrity mismatches are retained and reported rather than deleted automatically.

## Restore Status

Jyavani Core 2.3.106 does not expose a validated API for importing a purged Media or File record and its bytes. This plugin therefore does not restore through raw SQL. The archive preserves the complete snapshot and verified bytes until Core provides a safe import contract with explicit conflict and overwrite handling.

## Development

```bash
git ls-files '*.php' -z | xargs -0 -n1 php -l
for test in tests/*_contract.php; do php "$test" || exit 1; done
```
