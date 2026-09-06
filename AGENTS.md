# Jyavani Blackhole Repository Contract

Jyavani Blackhole is a private, fail-closed lifecycle archive plugin for Jyavani CMS.

- Keep tracked source generic and free of deployment credentials or user data.
- Require Jyavani Core 2.3.106 or newer.
- Use append-only plugin migrations. Never create or repair schema during requests.
- Archive supported lifecycle events inside the caller transaction.
- Never move, rename, delete, or modify Core-owned artifacts.
- Store runtime artifacts only below `BACKEND_PATH/var/plugins/jyavani-blackhole/`.
- Never expose absolute paths or raw artifact downloads in dashboard output.
- Do not implement post-purge restore with raw SQL. Wait for a validated Core import API.
- Run PHP lint and every `tests/*_contract.php` script before committing.
