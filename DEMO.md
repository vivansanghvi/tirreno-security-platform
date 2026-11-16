# Demo guide

How to run this project locally with demo data and present it.

## What the project is

tirreno is an open-source, self-hosted **security and fraud-prevention platform**. A web application
(a shop, a bank portal, etc.) sends each user action (login, failed login, page view, password change)
to tirreno's **sensor** (`POST /sensor/`). A background **cron** job then evaluates **rules** against
each user's activity and gives each user a **trust score from 0 to 100** (low = risky). Operators review risky users in the
**console** and can blacklist them. Everything stays on your own server, with no third-party cloud.

| Part | Folder | Role |
|------|--------|------|
| Sensor | `sensor/` | Receives events from the protected application |
| Console | `app/`, `ui/` | Web dashboard for operators (PHP Fat-Free framework, jQuery) |
| Rules | `assets/rules/core/` | Detection rules (e.g. B06 "Potentially vulnerable URL") |
| Database | `install/install.sql` | PostgreSQL 15 schema |
| Cron | `index.php /cron` | Scores users, processes the queues |

## Requirements

Docker Desktop, running. Port 8686 must be free (to use another port, set `TIRRENO_PORT`).

## Run it (from the project folder)

```bash
# 1. Build and start the app and PostgreSQL (first run downloads images, a few minutes)
docker compose -p tirreno-demo up -d --build

# 2. Create the login, send demo events, score the users
docker compose -p tirreno-demo exec -T -u www-data tirreno-app php docker/seed-demo.php
```

Open **http://localhost:8686** and log in:

- Email: `demo@example.com`
- Password: `DemoPassw0rd!`

The seeder is safe to run again: it skips the signup and the events if they already exist.

```bash
docker compose -p tirreno-demo stop       # pause (data is kept)
docker compose -p tirreno-demo start      # resume
docker compose -p tirreno-demo down -v    # delete everything, then repeat steps 1 and 2 for a fresh demo
```

## Demo data (`docker/seed-demo.php`)

The signup uses the **Account takeover** rule preset. The seeder sends 110 events:

| User | Story | Result |
|------|-------|--------|
| alice, bob, carol, david, emma | Normal customers: register, log in, browse, log out | Trust score 89–99 |
| frank, grace | Two accounts from the same IP | Score 7: rule I04 "Shared IP" |
| mallory | 12 failed logins from 12 IPs at 2 AM, then a login, a password change on a new device and 500 errors | Score 0: A05, B05, B19, ... |
| scanner_bot | Requests `/wp-admin`, `/.env`, `/.git/config` with the `sqlmap` user agent, all 404 | Score 0: B06, B05, D01, ... |

IP addresses are from the documentation ranges (192.0.2.x, 198.51.100.x, 203.0.113.x). Country, ISP and
VPN/TOR data stay empty because IP enrichment needs the external tirreno API, which this demo does not use.

## Presenting it (about 5 minutes)

1. **Dashboard** (`/`): totals for events, users and IPs.
2. **Users** (`/id`): sort by trust score; mallory and scanner_bot are at the bottom.
3. Open **mallory**: the matched rules and the timeline of failed logins, the password change and the errors.
   This shows how an account takeover looks.
4. Open **scanner_bot**: the attack URLs and the malicious user agent (rule B06).
5. **IPs** (`/ip`): 203.0.113.50 is shared by frank and grace.
6. **Rules** (`/rules`): the active rules and their weights. Change a weight to show that scoring is configurable.
7. Blacklist mallory from the user page, then show **Blacklist** (`/blacklist`) and the **Logbook** (`/logbook`),
   which records every event the sensor received.
8. **API** (`/api`): the API key the protected application uses to send events.

Known issue: the **Watchlist** menu item returns a 500 error (`Unknown property watchlist` in
`app/Core/Services/BaseAggregator.php`); avoid it during the demo.

## Sending your own event (optional, live during the demo)

PowerShell, from the project folder:

```powershell
$KEY = docker compose -p tirreno-demo exec -T tirreno-db psql -U tirreno -Atc 'SELECT key FROM dshb_api LIMIT 1'
$now = (Get-Date).ToUniversalTime().ToString('yyyy-MM-dd HH:mm:ss') + '.000'
(Invoke-WebRequest -UseBasicParsing -Method Post -Uri http://localhost:8686/sensor/ -Headers @{ 'Api-Key' = $KEY } `
  -Body @{ userName = 'live_user'; ipAddress = '198.51.100.77'; url = '/login'; eventType = 'account_login'; eventTime = $now }).StatusCode
docker compose -p tirreno-demo exec -T -u www-data tirreno-app php index.php /cron
```

The sensor answers `204` when it accepts the event (events must be form fields, not JSON). After the cron
run, `live_user` appears in Users with a score. In Git Bash, write `//cron` instead of `/cron`: Git Bash
turns `/cron` into a Windows path.
