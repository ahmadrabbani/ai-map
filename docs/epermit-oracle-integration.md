# ePermit Oracle Integration (Building Plan)

## What is implemented in Laravel

- On `Submit to AD ePermit`, the app now:
1. Builds payload compatible with your legacy Oracle PHP endpoint (`base64` fields + `checkList` + `FloorsArr`).
2. Calls `EPERMIT_ORACLE_ENDPOINT`.
3. Parses legacy success format: `1-successfully entered-{application_no}-{case_id}`.
4. Logs request/response in `bp_epermit_sync_logs`.
5. Moves workflow status to `Submitted to AD ePermit` only on successful Oracle response.

## Required env

Add in `.env`:

```env
EPERMIT_ORACLE_ENABLED=true
EPERMIT_ORACLE_ENDPOINT=https://your-host/path/to/api.php
EPERMIT_ORACLE_TIMEOUT=45
EPERMIT_ORACLE_CATEGORY_ID=30
EPERMIT_ORACLE_SUB_TYPE_ID=15
EPERMIT_ORACLE_TYPE_ID=1
EPERMIT_ORACLE_SCHEME_ID=
EPERMIT_ORACLE_PHASE_ID=
EPERMIT_ORACLE_BLOCK_ID=
EPERMIT_ORACLE_COMMERCIAL_TYPE_ID=
EPERMIT_ORACLE_IS_EBIZ_OBJECTION=0
EPERMIT_ORACLE_VERSION=1
EPERMIT_ORACLE_LOGIN_ID=31
```

## Local migration

Run:

```bash
php artisan migrate
```

This creates `bp_epermit_sync_logs` table.

## Oracle scripts

- `database/oracle/bp_ai_report_tables.sql`
  - Creates report header/detail/log tables + sequences/triggers.

- `database/oracle/sp_upsert_bp_ai_report.sql`
  - Procedure skeleton for upsert/report persistence on Oracle side.
