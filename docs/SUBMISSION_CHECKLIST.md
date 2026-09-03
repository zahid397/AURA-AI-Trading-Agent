# Submission Checklist

## Before judging

- [ ] **New, fresh Alpaca paper account dedicated to this hackathon** —
      do not reuse an existing personal paper account. Create it at
      [alpaca.markets](https://alpaca.markets), generate a paper API
      key/secret pair, and enter them only in `backend/.env` /
      `ai-engine/.env` (never commit them). Verify via **Settings > Alpaca
      Connection > Test Connection**.
- [ ] Paper endpoint verified: `ALPACA_BASE_URL` contains `paper-api.alpaca.markets`.
      `TradingSafetyServiceProvider` / `app/core/config.py` will refuse to
      boot otherwise — that refusal itself is part of the evidence.
- [ ] Options trading capability checked on the fresh account (Settings >
      System Health > Options level).
- [ ] Trading API demonstrated (a real or demo run showing
      `submitted_via: alpaca_api`).
- [ ] MCP or CLI demonstrated (Settings > System Health capability matrix,
      or `scripts/alpaca-cli-smoke.sh`).
- [ ] Every executable strategy uses options — confirmed by
      `ai-engine/app/agents/execution.py` having no equity-order code path.
- [ ] No secrets committed — `.env` files are gitignored in every service;
      run `git status` / a secret scanner (`.github/workflows/ci.yml`'s
      `secret-scan` job) before pushing.
- [ ] Tests/build passing — see `docs/IMPLEMENTATION_STATUS.md` for the
      exact, currently-true pass/fail counts per service.
- [ ] Deployed URL and health checks (if deploying — see
      `docs/deployment-aws.md`) or a clear "run locally" path in the README
      if not.
- [ ] Demo video/script recorded (`docs/demo-script.md`).
- [ ] Up to five build-in-public links ready (see below).

## Build-in-public (documentation only — nothing here is automated)

List up to five social post links here before submitting, and tag
**@lablab.ai** and **@Alpaca** on X/LinkedIn if/when the team posts:

1.
2.
3.
4.
5.
