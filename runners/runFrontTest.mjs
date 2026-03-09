#!/usr/bin/env node
/**
 * Runner de tests JS (Node, ESM).
 *
 * - Ejecuta cada `*.test.mjs` como proceso separado (aisla globals/mocks).
 * - Soporta loader ESM para redirigir imports test/front -> <TK_PUBLIC_DIR>.
 * - Soporta paralelismo por archivos (TEST_JOBS) + DB per-worker (TEST_WORKER_ID).
 *
 * Uso:
 *   node test/front/runFrontTest.mjs
 *
 * Variables:
 *   TEST_SCOPE=unit|integration|e2e|all   (default: all)
 *   TEST_FAIL_FAST=1|0                   (default: 1)
 *   TEST_MATCH=<substring>               (default: '')
 *   TEST_LIST=1                          (lista tests y sale)
 *
 *   TEST_JOBS=N                          (default: 1)
 *   TEST_DB_STRATEGY=shared|clean|per_worker
 *
 *   TEST_USE_PUBLIC_LOADER=1|0           (default: 1)
 *   TEST_IMPORT_DEBUG=1|0                (default: 0)
 *
 * Bootstrap:
 *   TK_FRONT_BOOTSTRAP=<path>            (default: test/front/_support/bootstrap.mjs si existe)
 */

import { spawn, spawnSync } from "node:child_process";
import fs from "node:fs";
import path from "node:path";
import { fileURLToPath, pathToFileURL } from "node:url";

import { banner, testHead, counts, bold, dim, gray, red } from "../utils/js/ui.mjs";
import { PVT_EXIT_PASS, PVT_EXIT_FAIL, PVT_EXIT_SKIP, PVT_EXIT_ERROR } from "../utils/js/constants.mjs";

// --- paths robustos
const __filename = fileURLToPath(import.meta.url);
const __dirname = path.dirname(__filename);

// runFrontTest.mjs está en: <repoRoot>/test/front/runFrontTest.mjs
const testkitRoot = process.env.TESTKIT_ROOT || path.resolve(__dirname, "..");
const repoRoot = process.env.TK_REPO_ROOT || process.env.TESTKIT_PROJECT_ROOT || path.resolve(testkitRoot, "..");

// Preferred: <repo>/test/front/tests (so kit updates don't overwrite tests)
// Back-compat: <repo>/test/front
const testsDirPreferred = path.join(repoRoot, "test", "front", "tests");
const testsDir = fs.existsSync(testsDirPreferred) ? testsDirPreferred : path.join(repoRoot, "test", "front");

const scope = (process.env.TEST_SCOPE || "all").toLowerCase();
const failFast = (process.env.TEST_FAIL_FAST || "1") === "1";
const match = (process.env.TEST_MATCH || "").toLowerCase();
const listOnly = (process.env.TEST_LIST || "0") === "1";

const jobs = Math.max(1, parseInt(process.env.TEST_JOBS || "1", 10) || 1);

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
const loaderPath = path.join(testkitRoot, "utils", "js", "front_loader.mjs");
const loaderUrl = pathToFileURL(loaderPath).href;

// Bootstrap (opcional)
const bootstrapDefault = path.join(repoRoot, "test", "front", "_support", "bootstrap.mjs");
const bootstrapPath = process.env.TK_FRONT_BOOTSTRAP || bootstrapDefault;
const bootstrapUrl = fs.existsSync(bootstrapPath) ? pathToFileURL(bootstrapPath).href : null;

banner("FRONT / JS");
console.log(bold(`Running ${tests.length} tests JS (scope=${scope}, failFast=${failFast ? "1" : "0"}, jobs=${jobs})`));
console.log(dim(`repoRoot:  ${repoRoot}`));
console.log(dim(`testsDir:  ${testsDir}`));
if (bootstrapUrl) console.log(dim(`bootstrap: ${bootstrapPath}`));
if (useLoader) console.log(dim(`loader:    ${loaderPath}`));
if (match) console.log(dim(`match:     ${match}`));
console.log("");

if (listOnly) {
  for (const t of tests) console.log(norm(path.relative(repoRoot, t)));
  process.exit(PVT_EXIT_PASS);
}

function makeArgs(testFile) {
  const args = [];
  if (bootstrapUrl) args.push("--import", bootstrapUrl);
  if (useLoader) args.push("--loader", loaderUrl);
  args.push(testFile);
  return args;
}

function runOne(testFile, workerId) {
  const rel = norm(path.relative(repoRoot, testFile));
  const args = makeArgs(testFile);

  return new Promise((resolve) => {
    const child = spawn(process.execPath, args, {
      cwd: repoRoot,
      env: { ...process.env, TEST_WORKER_ID: String(workerId), TESTKIT_ROOT: testkitRoot, TK_REPO_ROOT: repoRoot },
      stdio: ["ignore", "pipe", "pipe"],
    });

    let out = "";
    let err = "";

    child.stdout.on("data", (d) => (out += d.toString("utf8")));
    child.stderr.on("data", (d) => (err += d.toString("utf8")));

    child.on("close", (code) => {
      resolve({ rel, code: code ?? 1, out, err });
    });
  });
}

let passed = 0,
  failed = 0,
  skipped = 0;

if (jobs <= 1) {
  for (const t of tests) {
    const rel = norm(path.relative(repoRoot, t));
    console.log(testHead(rel));

    const r = spawnSync(process.execPath, makeArgs(t), {
      cwd: repoRoot,
      env: { ...process.env, TEST_WORKER_ID: "1", TESTKIT_ROOT: testkitRoot, TK_REPO_ROOT: repoRoot },
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
} else {
  let next = 0;
  let stop = false;

  async function worker(workerId) {
    while (true) {
      if (stop) return;
      const idx = next++;
      if (idx >= tests.length) return;
      const t = tests[idx];

      const res = await runOne(t, workerId);
      console.log(testHead(res.rel));
      if (res.out) process.stdout.write(res.out);
      if (res.err) process.stderr.write(res.err);
      console.log("");

      if (res.code === 0) {
        passed++;
        continue;
      }
      if (res.code === PVT_EXIT_SKIP || res.code === 2) {
        skipped++;
        continue;
      }

      failed++;
      if (failFast) {
        stop = true;
        return;
      }
    }
  }

  await Promise.all(Array.from({ length: jobs }, (_, i) => worker(i + 1)));

  if (stop) {
    // algunos workers pueden seguir corriendo; el suite se considera fallido
    console.error(red("FAIL-FAST: abortando lanzamiento de nuevos tests (algunos pueden seguir ejecutándose)."));
  }
}

console.log(gray(`Summary JS: ${counts({ pass: passed, fail: failed, skip: skipped })}`));

if (failed > 0) process.exit(PVT_EXIT_FAIL);
if (passed === 0 && skipped > 0) process.exit(PVT_EXIT_SKIP);
process.exit(PVT_EXIT_PASS);
