import { execFileSync } from "node:child_process";
import path from "node:path";

const php = process.env.PHP_BINARY ?? (process.platform === "win32" ? "C:\\xampp\\php\\php.exe" : "php");
const platformDir = path.resolve(__dirname, "..");

async function assertUp(name: string, url: string) {
  const response = await fetch(url, { redirect: "manual" }).catch(() => null);
  if (!response) {
    throw new Error(`${name} is not running at ${url}`);
  }
}

export default async function globalSetup() {
  execFileSync(php, ["artisan", "cache:clear"], { cwd: platformDir, stdio: "inherit" });

  await Promise.all([
    assertUp("Laravel API", "http://127.0.0.1:8000/up"),
    assertUp("Dashboard", "http://127.0.0.1:5173/"),
    assertUp("Landing", "http://localhost:3000/home-of-creativity-profile/"),
  ]);
}
