# Mewmii OS — Deployment Notes

This exists because of a real incident: every product/variation image uploaded to production
was being silently destroyed shortly after upload. The upload code itself was verified correct
(see System Health's "Live Upload Write Test" — writes and reads back successfully, every
time). The cause was outside the application: whatever deploys this repository to the live
server was resetting the target directory to match the git tree, and since uploaded images are
neither tracked by git nor otherwise protected, every deploy wiped all of them. The database
was never affected (it lives on a separate server a code deploy never touches), which is why
the symptom looked like "the database has 50 images, the disk has 0" rather than a normal
missing-file bug.

## Paths that must survive every deployment, untouched

| Path | What it is | Why it must survive |
|---|---|---|
| `/uploads/` | User-generated content — every product/variation image ever uploaded through the app (see `includes/image_upload.php`). Never tracked by git (see `.gitignore`). | If a deploy deletes or replaces this directory, every previously uploaded image is gone permanently unless you still have the original source photos (see `BACKUP.md`). This is the exact incident this file exists to prevent from recurring. |
| `/config.php` | Environment-specific configuration (WooCommerce API URL, app URL, uploads URL). Deliberately **not tracked by git** (see `.gitignore`) — copy from `config.example.php` once per environment and edit locally. | If a deploy overwrites this with `config.example.php`'s blank defaults, or removes it, the application cannot connect to its database or to WooCommerce at all ("Database configuration is incomplete" is exactly this failure - see `includes/bootstrap.php`/`config/database.php`). |
| `/.env` | Real secrets: database credentials, WooCommerce consumer key/secret/webhook secret (see `includes/env_loader.php`). Never tracked by git. | Same failure mode as `config.php` if lost or overwritten — the app cannot authenticate to anything. |

**The rule for whatever deploys this repository**: it must only ever add/update files that are part of this git repository. It must never delete, replace, or reset anything else in the target directory — that is precisely how this incident happened.

## If your deploy mechanism does a full directory replace

Some deployment tools (including some "Git deploy" panel features on shared hosting) work by
exporting a fresh copy of the repository and swapping it in for the live directory, rather than
running `git pull` in place. That style of deploy has **no visibility into `.gitignore` at
all** on the target server — it doesn't know `/uploads`, `/config.php`, or `/.env` are supposed
to survive, because as far as it's concerned it's just producing a clean export of the repo and
putting it where the old one was.

If that's how this app is deployed, `.gitignore` alone does not protect these three paths. You
need one of:

1. **Configure the deploy tool to explicitly exclude/preserve these paths** across a deploy —
   most Git-based hosting-panel deploy features have an "exclude paths" or equivalent setting.
   This is the minimal fix if you want to keep the current deploy mechanism as-is.
2. **Move to an in-place `git pull`-based deploy** instead of a full directory replace/fresh
   clone-and-swap. A plain `git pull` merges tracked-file changes into the existing working
   tree and never touches untracked files — `/uploads`, `/config.php`, and `/.env` would simply
   never be affected by it.
3. **Move `/uploads` outside the deployed directory entirely** (the most robust, standard
   pattern for exactly this problem — e.g. Laravel Forge/Envoyer/Capistrano-style deploys all
   do this for persistent data): host it at a fixed path outside whatever directory gets
   replaced on deploy, and symlink `uploads/` from inside the app to that fixed path. A full
   directory replace then never touches the real files, because they were never inside the
   directory being replaced in the first place.

**Whichever of these you use, check it before your next deploy** — the fix in this repo
(`.gitignore` now excludes `/uploads`, `/config.php`) prevents git itself from ever tracking or
cleaning these paths, but cannot force an external deploy tool to leave them alone if that tool
doesn't consult git's ignore rules at all.

## Verifying after a deploy

Visit **Settings → System Health** after any deploy and confirm:

- The "Uploads Directory" check at the top shows the directory present and writable.
- The "Stored Images Audit" table shows images still present on disk (not newly all-missing).
- Nothing in "Database" shows a pending migration that wasn't there before.

## What was checked for this incident

No script inside this repository deletes, cleans, or resets `/uploads` — confirmed by
searching the entire codebase for `unlink`, `rmdir`, `glob`, filesystem-iteration calls, and
every existing maintenance/reset tool (`modules/settings/maintenance.php`,
`modules/settings/reset_test_data.php`, both of which explicitly and deliberately never touch
the product catalog or its images). There is no CI/CD config, deploy script, or cron
job checked into this repository either. The deploy mechanism responsible lives entirely in
your hosting provider's configuration (Hostinger, per this codebase's own existing references
to Hostinger cron jobs) - it is not something this repository can introspect or fix on its own.
