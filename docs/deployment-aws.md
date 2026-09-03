# Deployment Guide — Single AWS EC2 Instance (Hackathon Scope)

**This describes how to deploy, and has not been executed in this build
session** (no AWS credentials/instance were available). Treat it as a
reviewed runbook, not a claim that a live deployment exists — see
`docs/IMPLEMENTATION_STATUS.md`.

## Why single-instance Docker Compose

For a hackathon demo, one EC2 instance running the same `compose.yml` used
locally is the lowest-cost, fastest path to a public URL. Managed services
(RDS, ElastiCache) are noted as optional upgrades, not required.

## Steps

1. **Launch an instance** — Ubuntu 24.04 LTS, `t3.medium` or larger (the
   AI engine + Postgres + Redis + Laravel + a Node build step benefit from
   at least 4GB RAM). Open inbound ports 22 (SSH, restricted to your IP),
   80/443 (HTTP/HTTPS).
2. **Install Docker** — `curl -fsSL https://get.docker.com | sh` then add
   your user to the `docker` group.
3. **Clone the repo** and copy environment files:
   ```bash
   git clone <this-repo> aura && cd aura
   cp .env.example .env
   cp backend/.env.example backend/.env
   cp ai-engine/.env.example ai-engine/.env
   ```
   Edit `backend/.env` / `ai-engine/.env`: set `APP_URL`/`APP_KEY`
   (`php artisan key:generate` once composer deps are available, or run it
   inside the built container), real Alpaca **paper** keys, and a strong
   `AURA_INTERNAL_SIGNING_SECRET` / `INTERNAL_SIGNING_SECRET` (must match on
   both sides). Set `POSTGRES_PASSWORD` in the root `.env` to something real.
4. **HTTPS reverse proxy** — put Caddy or nginx-with-certbot in front of the
   `frontend` container's port 8080 (or change `compose.yml` to publish 80/443
   directly and terminate TLS there). A minimal Caddyfile:
   ```
   your-domain.example {
       reverse_proxy localhost:8080
   }
   ```
5. **Bring the stack up**:
   ```bash
   docker compose up --build -d
   docker compose exec laravel php artisan aura:demo-reset   # or a real seed for judging
   bash scripts/health-check.sh
   ```
6. **Verify** — hit `https://your-domain.example/`, log in with the demo
   credentials (or your own seeded account), and walk through
   `docs/demo-script.md`.

## Backups

- Postgres: `docker compose exec postgres pg_dump -U aura aura > backup.sql`
  on a cron job (or before any risky change).
- The `ai_engine_data` volume holds the LangGraph checkpoint SQLite file —
  back it up if you need to preserve in-flight/paused runs across a
  redeploy.

## Rollback

- Keep the previous image tags; `docker compose up -d <service>` with the
  prior tag rolls a single service back without touching the others.
- Database migrations should be additive where possible; if a rollback needs
  a schema revert, run `php artisan migrate:rollback` before switching the
  Laravel image back.

## Firewall / security groups

- Only 22, 80, 443 open publicly. Postgres (5432) and Redis (6379) are only
  reachable on the Docker Compose internal network — never expose them to
  the internet.
- The FastAPI service's port (8001) and the internal
  `/api/internal/v1/events` route are for container-to-container traffic
  only — do not open 8001 in the security group.

## Managed-service upgrade path (optional, not required for the demo)

- Postgres -> Amazon RDS (Postgres 16), update `DB_HOST`/`DB_PORT` in
  `backend/.env`.
- Redis -> Amazon ElastiCache, update `REDIS_HOST` similarly.
- Both are drop-in replacements since the app only talks to them via
  standard connection strings — no code change required, just configuration.
