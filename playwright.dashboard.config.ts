import path from "node:path";
import { fileURLToPath } from "node:url";
import { defineConfig } from "@playwright/test";

// Dashboard-only checks. Every /api call is answered by page.route, so no Laravel server and no database are used.
const dashboardDir = path.join(path.dirname(fileURLToPath(import.meta.url)), "dashboard");

export default defineConfig({
  testDir: "./e2e/mocked",
  fullyParallel: false,
  workers: 1,
  timeout: 60_000,
  expect: { timeout: 15_000 },
  reporter: [["list"]],
  use: {
    channel: "chrome",
    baseURL: "http://127.0.0.1:5174/dashboard/",
  },
  webServer: {
    command: "npm run dev -- --host 127.0.0.1 --port 5174 --strictPort",
    cwd: dashboardDir,
    url: "http://127.0.0.1:5174/dashboard/",
    reuseExistingServer: true,
    timeout: 120_000,
  },
});
