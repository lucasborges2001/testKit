#!/usr/bin/env node
/**
 * Runner de tests JS (Node, ESM).
 *
 * - Ejecuta cada `*.test.mjs` como proceso separado (aisla globals/mocks).
 * - Soporta loader ESM para redirigir imports test/front -> public_html.
 *
 * Uso:
 *   node test/front/run.mjs
 *
 * Variables:
 *   TEST_SCOPE=unit|integration|e2e|all   (default: all)
 *   TEST_FAIL_FAST=1|0                   (default: 1)
 *   TEST_MATCH=<substring>               (default: '')
 *   TEST_LIST=1                          (lista tests y sale)
 *
 *   TEST_USE_PUBLIC_LOADER=1|0           (default: 1)
 *   TEST_IMPORT_DEBUG=1|0                (default: 0) logs del loader
 *
 * Colores ANSI:
 *   PVT_COLOR=auto|1|0, NO_COLOR=1, FORCE_COLOR=1
 */

import { spawnSync } from "node:child_process";
import fs from "node:fs";
import path from "node:path";
import { fileURLToPath, pathToFileURL } from "node:url";

import { banner, testHead, counts, bold, dim, gray } from "../utils/js/ui.mjs";
import { PVT_EXIT_PASS, PVT_EXIT_FAIL, PVT_EXIT_SKIP, PVT_EXIT_ERROR } from "../utils/js/constants.mjs";

// --- paths robustos
const __filename = fileURLToPath(import.meta.url);
const __dirname = path.dirname(__filename);

// run.mjs está en: <repoRoot>/test/front/run.mjs
const repoRoot = path.resolve(__dirname, "..", "..");
const testsDir = path.join(repoRoot, "test", "front");

const scope = (process.env.TEST_SCOPE || "all").toLowerCase();
const failFast = (process.env.TEST_FAIL_FAST || "0") === "1";
const match = (process.env.TEST_MATCH || "").toLowerCase();
const listOnly = (process.env.TEST_LIST || "0") === "1";

const useLoader = (process.env.TEST_USE_PUBLIC_LOADER || "1") === "1";

const VALID_SCOPES = new Set(["unit", "integration", "e2e", "all"]);
if (!VALID_SCOPES.has(scope)) {
  console.error(`TEST_SCOPE inválido: "${scope}". Valores: unit|integration|e2e|all`);
  process.exit(PVT_EXIT_ERROR);
}

function walk(dir, acc = []) {
  const entries = fs.readdirSync(dir, { withFileTypes: true });
  for (const ent of entries) {
    const p = path.join(dir, ent.name);
    if (ent.isDirectory()) walk(p, acc);
    else if (ent.isFile()) acc.push(p);
  }
  return acc;
}

function norm(p) {
  return p.replaceAll("\\", "/");
}

function matchesScope(p) {
  if (scope === "all") return true;
  const n = norm(p);
  return n.includes(`/${scope}/`);
}

let tests = walk(testsDir)
  .filter((p) => p.endsWith(".test.mjs"))
  .filter(matchesScope)
  .sort((a, b) => a.localeCompare(b));

if (match) {
  tests = tests.filter((p) => norm(path.relative(repoRoot, p)).toLowerCase().includes(match));
}

if (!tests.length) {
  console.error(`No se encontraron tests JS en ${testsDir} (scope=${scope}, match=${match || ""}).`);
  process.exit(PVT_EXIT_ERROR);
}

// Loader: <repoRoot>/test/utils/js/front_loader.mjs
const loaderPath = path.join(repoRoot, "test", "utils", "js", "front_loader.mjs");
const loaderUrl = pathToFileURL(loaderPath).href;

banner("FRONT / JS");
console.log(bold(`Running ${tests.length} tests JS (scope=${scope}, failFast=${failFast ? "1" : "0"})`));
console.log(dim(`repoRoot: ${repoRoot}`));
console.log(dim(`testsDir: ${testsDir}`));
if (useLoader) console.log(dim(`loader:  ${loaderPath}`));
if (match) console.log(dim(`match:   ${match}`));
console.log("");

if (listOnly) {
  for (const t of tests) console.log(norm(path.relative(repoRoot, t)));
  process.exit(PVT_EXIT_PASS);
}

let passed = 0,
  failed = 0,
  skipped = 0;

for (const t of tests) {
  const rel = norm(path.relative(repoRoot, t));
  console.log(testHead(rel));

  const args = [];
  if (useLoader) args.push("--loader", loaderUrl);
  args.push(t);

  const r = spawnSync(process.execPath, args, {
    cwd: repoRoot,
    env: { ...process.env },
    stdio: ["ignore", "inherit", "inherit"],
  });

  const code = r.status ?? (r.signal ? 128 : 1);
  if (code === 0) {
    passed++;
    console.log("");
    continue;
  }
  if (code === PVT_EXIT_SKIP || code === 2) {
    skipped++;
    console.log("");
    continue;
  }

  failed++;
  console.log("");
  if (failFast) break;
}

console.log(gray(`Summary JS: ${counts({ pass: passed, fail: failed, skip: skipped })}`));

if (failed > 0) process.exit(PVT_EXIT_FAIL);
if (passed === 0 && skipped > 0) process.exit(PVT_EXIT_SKIP);
process.exit(PVT_EXIT_PASS);
