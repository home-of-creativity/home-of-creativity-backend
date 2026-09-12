import { execFileSync } from "node:child_process";
import path from "node:path";
import { fileURLToPath } from "node:url";

const php = process.env.PHP_BINARY ?? (process.platform === "win32" ? "C:\\xampp\\php\\php.exe" : "php");
const platformDir = path.resolve(path.dirname(fileURLToPath(import.meta.url)), "..");

async function assertUp(name: string, url: string) {
  const response = await fetch(url, { redirect: "manual" }).catch(() => null);
  if (!response) {
    throw new Error(`${name} is not running at ${url}`);
  }
}

export default async function globalSetup() {
  execFileSync(php, ["artisan", "migrate", "--force"], { cwd: platformDir, stdio: "inherit" });
  execFileSync(php, ["artisan", "db:seed", "--force"], { cwd: platformDir, stdio: "inherit" });
  execFileSync(php, ["artisan", "cache:clear"], { cwd: platformDir, stdio: "inherit" });

  if (process.env.E2E_REQUIRE_LANDING === "1") {
    await assertUp("Landing", "http://localhost:3000/home-of-creativity-profile/");
  }
}
