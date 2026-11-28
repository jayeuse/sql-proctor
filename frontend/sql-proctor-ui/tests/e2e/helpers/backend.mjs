import { execFileSync } from "node:child_process";
import fs from "node:fs";
import path from "node:path";

const BACKEND_DIR = path.resolve(import.meta.dirname, "../../../../../backend/sql-proctor-api");
const PHP_BIN = fs.existsSync("/opt/homebrew/bin/php") ? "/opt/homebrew/bin/php" : "php";
const ENV = {
  ...process.env,
  PATH: `/opt/homebrew/bin:${process.env.PATH ?? ""}`,
  DB_CONNECTION: "pgsql",
  DB_DATABASE: "sql_proctor_test",
};

export function runArtisan(args) {
  return execFileSync(PHP_BIN, ["artisan", ...args], {
    cwd: BACKEND_DIR,
    env: ENV,
    encoding: "utf8",
  });
}

export function resetTestDatabase() {
  runArtisan(["migrate:fresh", "--force"]);
}

export function bootstrapTestAdmin() {
  const output = runArtisan(["app:bootstrap-admin"]);
  const match = output.match(/Password:\s*(.+)/);
  if (!match) {
    throw new Error("Could not extract bootstrapped admin password from artisan output.");
  }

  return {
    username: "admin",
    password: match[1].trim(),
  };
}

export function clearTestRateLimits() {
  runArtisan(["cache:clear"]);
}
