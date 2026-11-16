import { execSync } from "node:child_process";
import path from "node:path";

/**
 * Ensures the demo workspace exists. The seeder is idempotent, so this never duplicates data.
 * Set E2E_SKIP_SEED=1 to skip (e.g. when the backend runs elsewhere).
 */
export default function globalSetup() {
  if (process.env.E2E_SKIP_SEED) return;
  execSync("php artisan db:seed --force", { cwd: path.resolve(__dirname, "../../backend"), stdio: "inherit" });
}
