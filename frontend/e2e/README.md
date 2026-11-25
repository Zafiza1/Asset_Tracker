# E2E (Playwright)

```bash
npm run e2e
```

- **Servers.** The run starts its own servers: Laravel on `localhost:8001` (`php artisan serve` with `APP_ENV=e2e`) and Vite on `localhost:5174`. Development servers on 8000 and 5173 can keep running.
- **Database.** Every run resets and seeds the **test database** `asset_tracker_v1_test` (`e2e/global-setup.ts`: `migrate:fresh`, `db:context-key install`, `db:seed`, `DemoSeeder`). `php artisan test` resets that database too, so do not run the two at the same time.
- **Configuration.** `backend/.env.e2e` is written on every run from `backend/.env`, pointed at the test database and the E2E ports (`e2e/env.ts`). It is git-ignored.
- **Accounts.** E2E accounts use `DemoSeeder` (e.g. `admin@majujaya.test`) with password `E2E_PASSWORD`. The run refuses to start if `backend/.env` has the same `DEMO_PASSWORD`. That way, a successful E2E login proves the servers are using the test database.
- **Windows.** Run the command from the folder's real spelling (`D:\WORKSPACE\…`). With a different letter case, Node loads `@playwright/test` twice and reports "did not expect test() to be called here".
- **Browser.** The first time on a machine, install the browser with `npx playwright install chromium`.

| Spec | Flow |
|---|---|
| `home.spec.ts` | F-06d: Beranda after login → hide a card, still hidden after reload → "Dashboard Aset" menu |
| `asset-board.spec.ts` | F-06e: Dashboard Aset from the role template → every widget loads its data → a category tab → add a widget from the catalog. F-06f: Super Admin, Supervisor and Admin each get their own template, every widget loads |
| `deliveries.spec.ts` | PT SIG / PT CDO: plan an outgoing transaction on the web, then crew, load scan, depart, position, problem and unload scan with the API and token of the mobile app; Tracking and the detail follow it; incoming back to the warehouse |
