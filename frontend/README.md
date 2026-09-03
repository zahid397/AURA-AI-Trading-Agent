# AURA AI Trading Agent — Frontend

## Frontend status (honest, as of this session)

Everything below was actually run and observed in this environment, not assumed.

### Verified results

| Command | Result |
|---|---|
| `npm run typecheck` (`vue-tsc -b --noEmit`) | **Passes, 0 errors.** |
| `npm run test:unit` (`vitest run`) | **Passes: 3 files, 37 tests.** Covers `src/utils/format.ts` (27 tests), the `auth` Pinia store (6 tests, login/logout/bootstrap against the fixture credential check), and `AgentsPage`'s loading/empty/error/loaded states (4 tests, with mocked service responses). |
| `npm run build` (`vue-tsc -b && vite build`) | **Succeeds** (~13s). One informational (non-blocking) warning that the `echarts` chunk is >500kB gzipped to ~211kB — expected for a full charting library and not something to "fix" without a real code-splitting pass. |
| `npm run dev` | Boots cleanly on **port 5173**. Manually clicked/scripted through all 11 authenticated routes plus `/login` in a real browser (Chrome via the dev tooling in this session) after signing in with the seeded demo credentials, and confirmed zero console errors on each page after the fixes below. |

Two real runtime bugs were found this way (typecheck alone wouldn't have caught either) and fixed:
1. `formatCurrency(v, { maximumFractionDigits: 0 })` (used by chart axis-label formatters) threw a `RangeError` from `Intl.NumberFormat` because the default `minimumFractionDigits` (2) was left in place, so `minimum > maximum`. Fixed in `src/utils/format.ts` by clamping `minimumFractionDigits` to the resolved maximum, with a regression test.
2. `SettingsPage.vue` threw a `DataCloneError` calling `structuredClone()` on a Pinia-reactive settings object. Fixed by round-tripping through `JSON.parse(JSON.stringify(...))` instead, which is safe here since `AppSettings` is plain JSON-serializable data.

A third issue was root-caused and designed around rather than "fixed" in place: combining ECharts' `visualMap` (piecewise line coloring) with `markLine` on the payoff diagram (`PayoffChart.vue`) throws a deep internal `TypeError` in this exact `echarts@5.6.0` / `vue-echarts@7.0.3` pairing, even with `visualMap.seriesIndex` scoped to the line series. This was confirmed with a controlled fresh-browser-tab experiment (toggling `visualMap` on/off, independent of `markLine`) before concluding `visualMap` itself was the trigger. The payoff line is single-colored (brand blue) instead of green/red-by-sign; breakeven/current-price dashed markers and the numeric max-profit/max-loss/breakeven/current-price stat row underneath still fully convey the shape, so nothing is lost functionally.

### What's implemented

- **App shell** (`src/components/layout/`): collapsible sidebar (desktop) / icon rail (tablet, `md:` breakpoint) / drawer (mobile), with a system-status card, kill-switch button (confirmation-gated), and "Powered by Alpaca — Paper Trading" line. Top bar with sidebar/drawer toggle, page title, a persistent green "PAPER TRADING · DEMO DATA" badge, a separate amber "API unreachable" badge that only appears when a *live* request has actually fallen back to fixture data, market clock, notifications bell (backed by unacknowledged risk alerts), and a user menu. A slim amber disclaimer bar renders on every authenticated route.
- **Router** (`src/router/index.ts`): all 11 routes (`/login`, `/dashboard`, `/agents`, `/options-scanner`, `/strategy-analysis/:id?`, `/risk-management`, `/trade-execution/:proposalId?`, `/decisions`, `/portfolio`, `/backtesting`, `/settings`) plus a catch-all 404, with an auth guard that redirects unauthenticated users to `/login?redirect=...` and bounces authenticated users away from `/login`.
- **Fixture/live seam** (`src/services/apiMode.ts`): `VITE_API_MODE=fixture` (dev default) resolves every read through `src/dev/fixtures/*` with a simulated delay. `VITE_API_MODE=live` (prod default) calls the real Laravel API via a centralized Axios client (`src/services/http.ts` — `withCredentials`, Sanctum XSRF cookie/header wiring, `X-Request-Id` per request, normalized `ApiError` with `code`/`status`/`requestId`, exponential-backoff retry on idempotent GET network failures) and **falls back to the matching fixture on any failure**, flagging the domain as degraded so `DataSourceBanner.vue` can show "Showing demo data — API unreachable" inline on the affected page. Mutations (login, approve/reject/execute, kill switch, policy edits, settings saves) never silently fall back to a fake success in live mode — a failed mutation surfaces as a toast, never a pretend win.
- **Fixtures** (`src/dev/fixtures/`): extended the pre-existing `agents.ts`/`demo.ts`/`equityCurve.ts`/`orders.ts`/`proposals.ts`/`risk.ts`/`strategies.ts` with new `dashboard.ts`, `portfolio.ts`, `decisions.ts`, `scanner.ts`, `backtests.ts`, `settings.ts`, and `auth.ts` modules, all deriving from the same anchor data so numbers never drift or show as already-expired.
- **Pinia stores** (`src/stores/`): `auth`, `dashboard`, `agents`, `strategies` (scanner + strategy detail/payoff), `risk`, `portfolio`, `tradeProposals`, `decisions`, `backtests`, `settings`, `uiNotifications` (toasts + per-domain degraded-service tracking + sidebar/drawer UI state). Every store owns its own fetch + fallback + loading/error state; components only read from stores.
- **All 11 pages** are built per the design spec with distinct layouts (not template swaps): Login, Dashboard (KPIs, equity curve w/ range tabs, latest decision, positions, agent strip, market overview, recent trades, performance summary, activity timeline), Agents (autonomy controls, animated pipeline map, 5 agent cards, log detail panel), Strategy Analysis (payoff diagram, legs, 7 AI-explanation sections, confidence breakdown, audit ribbon), Risk Management (protection status, gauge, limit cards, live rule-evaluation table, alerts, rejected proposals, versioned policy-edit drawer with validation), Trade Execution (5-step stepper, order preview, risk-gate checklist, approve/reject/execute behind confirmation dialogs + idempotency key), Decisions (filterable timeline grouped by `run_id`, expandable trace detail, sanitized JSON export), Options Scanner (filter rail, ranked table, split-view detail), Portfolio (summary row, equity-vs-benchmark, allocation donut, Open/Closed/Orders/Activity tabs, monthly-return heatmap, CSV export, position drawer), Backtesting (builder form, KPI row, equity/drawdown/return-distribution/heatmap charts, trades table, explicit `historical`/`simulated_fixture`/`insufficient_data` provenance labeling), Settings (General/Alpaca/AI Provider/Risk/Agent Controls/Notifications/Security/System Health capability matrix, confirmation-gated autonomy-mode change).
- Every page follows the same loading-skeleton / empty-state / inline-retry-on-error pattern (`Skeleton.vue`, `EmptyState.vue`, `ErrorRetry.vue`) — no bare spinners or blank divs. Every chart pairs a legend/tooltip with a visible or `sr-only` accessible text summary.
- Confirmation dialogs (`ConfirmDialog.vue`) gate trade execution, proposal rejection, kill-switch toggling, and autonomy-mode changes.

### What's rough or still missing

- **Not tested against a live Laravel backend.** The `live` mode code path (Axios calls, CSRF flow, error normalization, retry) is written to the documented contract in `docs/api-routes.md` but was only exercised via the fixture path in this session, since the backend was being built concurrently by another agent. Point `VITE_API_BASE_URL` at a running Laravel instance and set `VITE_API_MODE=live` to validate it for real.
- **No Playwright e2e spec exists yet** — `test:e2e` is wired in `package.json` and `@playwright/test` is installed, but `e2e/` is empty and there's no `playwright.config.ts`. Verification in this session was manual browser click-through, not an automated e2e suite.
- Only `auth` (of the 11 stores) and `AgentsPage` (of the 12 pages) have dedicated Vitest coverage, per the "at least one store / at least one page" bar in the brief. The rest were verified manually in-browser but have no regression tests yet.
- "Compare" on the Strategy Analysis page is a placeholder toast — no comparison view was in the routed page list, so nothing was built for it beyond acknowledging the click.
- Risk alert "Acknowledge" is a client-side-only state change; there's no acknowledge endpoint in `docs/api-routes.md` to call.
- Responsive breakpoints (360/768/1024/1440/1920) were coded per the design spec and spot-checked at a couple of widths, not exhaustively audited across all pages.
- `.claude/launch.json` was added at the repo root (`aura/.claude/launch.json`, running `npm --prefix frontend run dev`) purely to support the in-session browser-preview tooling used for manual verification — it's dev tooling, not part of the shipped app.

## Development

```bash
npm install       # already done in this environment
npm run dev       # http://localhost:5173, VITE_API_MODE=fixture by default
npm run typecheck
npm run test:unit
npm run build
```

`.env.development` defaults to `VITE_API_MODE=fixture` (no backend needed). `.env.production` defaults to `VITE_API_MODE=live` against `VITE_API_BASE_URL` (`http://localhost:8000/api/v1` by default) — point this at the real Laravel backend once it's up.
