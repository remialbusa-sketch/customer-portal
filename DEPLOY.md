# CI/CD Deployment Guide

## Architecture

```
GitHub push to main
  ↓
GitHub Actions CI (test)
  ├── composer install
  ├── npm ci + vite build
  ├── php artisan test
  ├── scripts/test_tsr_form.php (36/36)
  └── verify_fixes.php (advisory)
  ↓
GitHub Actions CD (deploy)
  ├── composer install --no-dev --optimize-autoloader
  ├── npm ci + vite build (production)
  ├── SCP files to cPanel via SSH
  └── SSH: migrate → cache → permissions
  ↓
cPanel @ https://customer-portal.mcbtsi.com
```

## One-time setup

### 1. Generate SSH key for deployment

On your local machine:
```bash
ssh-keygen -t ed25519 -C "github-actions-deploy" -f deploy_key -N ""
```

This creates:
- `deploy_key` (private) — goes into GitHub Secrets
- `deploy_key.pub` (public) — goes into cPanel

### 2. Add public key to cPanel

1. Log in to cPanel → **SSH Access** (under Security)
2. Or use **Terminal** in cPanel if available
3. Append the public key:
```bash
mkdir -p ~/.ssh && chmod 700 ~/.ssh
echo "<contents of deploy_key.pub>" >> ~/.ssh/authorized_keys
chmod 600 ~/.ssh/authorized_keys
```

### 3. Test SSH connection
```bash
ssh -i deploy_key -p <port> <cpanel-user>@<cpanel-host> "pwd"
```

### 4. Set GitHub Secrets

Go to **GitHub repo → Settings → Secrets and variables → Actions → New repository secret**

| Secret | Value | Example |
|---|---|---|
| `CPANEL_HOST` | Server hostname or IP | `mcbtsi.com` or `192.168.1.100` |
| `CPANEL_USER` | cPanel username | `mcbtsicu` |
| `CPANEL_SSH_KEY` | Contents of `deploy_key` (private key) | `-----BEGIN OPENSSH PRIVATE KEY-----...` |
| `CPANEL_SSH_PORT` | SSH port (usually 22) | `22` |
| `CPANEL_DEPLOY_PATH` | Absolute path to project root on server | `/home/mcbtsicu/customer-portal` |

### 5. Create GitHub Environment (optional but recommended)

1. Go to **Settings → Environments → New environment**
2. Name it `production`
3. Add **Required reviewers** (yourself) for manual deploy approval
4. This gates deployment so you must click "Approve and deploy" in the GitHub UI

## How it works

### CI (every push + PR)
- Runs on **ubuntu-latest** with PHP 8.3 + Node 20
- Installs deps, builds Vite, runs all tests
- PR merges are blocked if CI fails

### CD (push to `main` only)
- Only runs after CI passes
- Builds production assets (no dev deps)
- SCPs files to cPanel
- SSHes in to run migrations + cache commands
- Requires `production` environment approval if configured

### Manual deployment (SSH into cPanel)
```bash
cd /home/mcbtsicu/customer-portal/portal
bash scripts/deploy.sh
```

## File structure on cPanel

```
/home/<cpanel-user>/
└── customer-portal/          ← CPANEL_DEPLOY_PATH
    └── portal/               ← Laravel root
        ├── app/
        ├── bootstrap/
        ├── config/
        ├── database/
        │   └── database.sqlite
        ├── public/            ← Document root (set in cPanel)
        │   ├── index.php
        │   ├── build/         ← Vite output (shipped by CI)
        │   ├── .htaccess
        │   └── images/
        ├── resources/
        ├── routes/
        ├── scripts/
        ├── storage/
        ├── vendor/            ← Production deps only
        ├── .env               ← NOT in git (manually placed)
        └── artisan
```

## Troubleshooting

### Scheduler (offline TSR drainer)
`SyncPendingTsrReports` (the Monday-sync safety net for offline TSRs) is
registered to run every 5 minutes in `bootstrap/app.php`. On cPanel, add a
cron job (cPanel → Cron Jobs → every minute):

```
* * * * * cd /home/USER/CPANEL_DEPLOY_PATH && php artisan schedule:run >> /dev/null 2>&1
```

Verify with: `php artisan schedule:list` (should show `tsr-drainer`).

### Deploy fails with "Permission denied"
- Check `deploy_key.pub` is in cPanel's `~/.ssh/authorized_keys`
- Verify cPanel user has write access to `CPANEL_DEPLOY_PATH`

### Migrations fail
- SSH in and check: `php artisan migrate:status`
- If DB is locked: `sqlite3 database/database.sqlite ".tables"`

### Vite assets not loading
- Check `public/build/manifest.json` exists
- Verify `.env` has correct `APP_URL`
- Run: `php artisan view:clear && php artisan view:cache`

### Rollback
If a deploy breaks production, SSH in and restore the previous version:
```bash
cd /home/mcbtsicu/customer-portal/portal
git log --oneline -5          # find last good commit
git checkout <commit-hash>    # restore files
bash scripts/deploy.sh        # re-deploy
```
