# Database backups

`registry-<date>.sql` is a SQLite dump committed on purpose: a snapshot of the
development data, verified table by table against the live database before
export.

| | |
|---|---|
| tables | 64 (all of them; 40 hold rows) |
| listings | 103 SBOs, 105 addresses, 300 services, 601 opening hours |
| applications | 74 compliance documents, 2 seals |
| network | 13 connections, 27 conversations, 10 messages |
| procurement | 102 requests, 306 bids |
| settings | 8 fee settings, 39 categories, 184 zones |
| reference | 250 countries, 5,250 states |

## It is scrubbed

Every password hash is replaced with `SCRUBBED-NO-LOGIN` and every real email
address with an `@example.test` one, so a restored copy has **no working
logins**. That is deliberate: a dump in a repository must not double as a set of
credentials, and git history cannot practically be un-published.

Set a password after restoring:

```
php artisan tinker --execute="\$u = App\Models\User::first(); \$u->password = Hash::make('your-password'); \$u->save();"
```

## What is NOT in it

**Uploaded files.** The `media` table rows are here; the images and documents
they point at live in `storage/app/public`, which Laravel's own `.gitignore`
excludes. Restore the database and every logo, cover and document will 404.
They are exported separately as a tarball — ask for one, or:

```
tar -czf media.tar.gz --exclude='sbo-seals' -C storage/app public
```

`sbo-seals` is excluded on purpose: 326MB of generated badge images that
`SboSealIssuer` redraws on demand when the file is missing.

## Restoring

```
sqlite3 database/database.sqlite < database/backups/registry-<date>.sql
tar -xzf media.tar.gz -C storage/app
php artisan storage:link
```

**Direct SQL restore is SQLite only.** This dump is SQLite-flavoured SQL and
cannot be piped straight into MariaDB, which is what `DEPLOYMENT.md`
provisions for production.

For a live database, this repo now includes an opt-in migration that replays
the dump's row data into the already-created Laravel schema:

```
IMPORT_REGISTRY_SNAPSHOT=true php artisan migrate --force
```

That migration reads `database/backups/registry-2026-08-07.sql`, clears the
tables present in the snapshot, and inserts the snapshot rows. It skips runtime
tables such as `migrations`, `sessions`, `cache`, and queued jobs.

Use it only for a fresh restore or a deliberate snapshot replacement. If you
only want the normal baseline reference data on a live database, run the
migrations and seeders instead:

```
php artisan migrate --force
php artisan db:seed --force
```

`LocalDemoSeeder` is fenced to the local environment on purpose — it writes 100
fabricated businesses, which do not belong in a live registry.

## Everything else here is ignored

`.gitignore` excludes the contents of this directory and re-includes
`registry-*.sql` and this README by name, so committing a dump stays a decision
rather than a default. `deployment/scripts/backup.sh` writes scheduled backups
that are not artefacts to version.
