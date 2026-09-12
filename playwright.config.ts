import path from "node:path";
import { fileURLToPath } from "node:url";
import { defineConfig } from "@playwright/test";

const platformDir = path.resolve(path.dirname(fileURLToPath(import.meta.url)));
const dashboardDir = path.join(platformDir, "dashboard");
const php = process.env.PHP_BINARY ?? (process.platform === "win32" ? "C:\\xampp\\php\\php.exe" : "php");
const e2ePort = process.env.E2E_PORT ?? "8002";
const apiUrl = process.env.E2E_API_URL ?? `http://127.0.0.1:${e2ePort}/api`;

export default defineConfig({
  testDir: "./e2e",
  globalSetup: "./e2e/global-setup.ts",
  env: {
    E2E_API_URL: apiUrl,
    E2E_PORT: e2ePort,
  },
  fullyParallel: false,
  workers: 1,
  timeout: 120_000,
  expect: { timeout: 12_000 },
  reporter: [["list"], ["html", { open: "never", outputFolder: "playwright-report" }]],
  use: {
    channel: "chrome",
    trace: "retain-on-failure",
    screenshot: "only-on-failure",
  },
  webServer: [
    {
      command: `${php} artisan serve --host=127.0.0.1 --port=${e2ePort}`,
      cwd: platformDir,
      url: `http://127.0.0.1:${e2ePort}/up`,
      reuseExistingServer: false,
      timeout: 120_000,
      env: {
        ...process.env,
        APP_ENV: "local",
        QUEUE_CONNECTION: "sync",
        TELEGRAM_STRICT: "false",
        TELEGRAM_BOT_TOKEN: "",
        TELEGRAM_STAFF_BOT_TOKEN: "",
        GEMINI_E2E_STUB: "true",
        E2E_API_URL: apiUrl,
        E2E_PORT: e2ePort,
      },
    },
    {
      command: "npm run dev -- --host 127.0.0.1 --port 5173",
      cwd: dashboardDir,
      url: "http://127.0.0.1:5173/",
      reuseExistingServer: !process.env.CI,
      timeout: 120_000,
      env: {
        ...process.env,
        VITE_API_URL: apiUrl,
      },
    },
  ],
});
