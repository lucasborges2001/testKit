#!/usr/bin/env node
/**
 * Runner de tests JS (Node, ESM).
 *
 * - Ejecuta cada `*.test.mjs` en proceso separado.
 * - Soporta loader ESM para redirigir imports test/front -> <TK_PUBLIC_DIR>.
 * - Soporta paralelismo por archivos (TEST_JOBS).
 * - Soporta filtrado por scope/category/match.
 * - Escribe reporte JSON si TESTKIT_REPORT_FILE esta definido.
 */

import { spawn, spawnSync } from "node:child_process";
import fs from "node:fs";
import path from "node:path";
import { performance } from "node:perf_hooks";
import { fileURLToPath, pathToFileURL } from "node:url";

import { banner, testHead, counts, bold, dim, gray, red } from "../utils/js/ui.mjs";
import { PVT_EXIT_PASS, PVT_EXIT_FAIL, PVT_EXIT_SKIP, PVT_EXIT_ERROR } from "../utils/js/constants.mjs";

const __filename = fileURLToPath(import.meta.url);
const __dirname = path.dirname(__filename);

const testkitRoot = process.env.TESTKIT_ROOT || path.resolve(__dirname, "..");
const repoRoot = process.env.TK_REPO_ROOT || process.env.TESTKIT_PROJECT_ROOT || path.resolve(testkitRoot, "..");

const testsDirPreferred = path.join(repoRoot, "test", "front", "tests");
const testsDir = fs.existsSync(testsDirPreferred) ? testsDirPreferred : path.join(repoRoot, "test", "front");

const scope = (process.env.TEST_SCOPE || "all").toLowerCase();
const category = (process.env.TEST_CATEGORY || "all").toLowerCase();
const failFast = (process.env.TEST_FAIL_FAST || "1") === "1";
const match = (process.env.TEST_MATCH || "").toLowerCase();
const listOnly = (process.env.TEST_LIST || "0") === "1";
const requireTests = (process.env.TEST_JS_REQUIRE_TESTS || "0") === "1";
const jobs = Math.max(1, parseInt(process.env.TEST_JOBS || "1", 10) || 1);
const useLoader = (process.env.TEST_USE_PUBLIC_LOADER || "1") === "1";
const slowThresholdMs = Math.max(1, parseInt(process.env.TEST_SLOW_THRESHOLD_MS || "1500", 10) || 1500);
const slowTop = Math.max(1, parseInt(process.env.TEST_SLOW_TOP || "10", 10) || 10);
const reportFile = process.env.TESTKIT_REPORT_FILE || "";

const VALID_SCOPES = new Set(["unit", "integration", "e2e", "all"]);
if (!VALID_SCOPES.has(scope)) {
  console.error(`TEST_SCOPE invalido: "${scope}". Valores: unit|integration|e2e|all`);
  process.exit(PVT_EXIT_ERROR);
}

function walk(dir, acc = []) {
  const entries = fs.readdirSync(dir, { withFileTypes: true });
  for (const ent of entries) {
    const p = path.join(dir, ent.name);
    if (ent.isDirectory()) {
      walk(p, acc);
      continue;
    }
    if (ent.isFile()) {
      acc.push(p);
    }
  }
  return acc;
}

function norm(p) {
  return p.replaceAll("\\", "/");
}

function matchesScope(filePath) {
  if (scope === "all") return true;
  return norm(filePath).includes(`/${scope}/`);
}

function tagsForPath(filePath) {
  const n = norm(filePath).toLowerCase();
  const tags = [];
  const map = {
    smoke: ["smoke"],
    perf: ["perf", "performance", "benchmark"],
    stress: ["stress", "load", "carga"],
    critical: ["critical", "critico", "critica"],
    contract: ["contract", "contrato"],
    slow: ["slow"],
    fragile: ["fragile", "flaky", "inestable"],
    unit: ["unit"],
    integration: ["integration"],
    e2e: ["e2e"],
  };

  for (const [tag, tokens] of Object.entries(map)) {
    if (tokens.some((token) => n.includes(`/${token}/`) || n.includes(`_${token}_`) || n.endsWith(`_${token}.test.mjs`))) {
      tags.push(tag);
    }
  }

  return Array.from(new Set(tags));
}

function matchesCategory(filePath) {
  if (!category || category === "all") return true;
  return tagsForPath(filePath).includes(category);
}

function moduleFromRel(rel) {
  const parts = norm(rel).split("/").filter(Boolean);
  if (parts.length >= 3 && parts[0] === "test") {
    return `${parts[1]}/${parts[2]}`;
  }
  if (parts.length >= 2) {
    return `${parts[0]}/${parts[1]}`;
  }
  return parts[0] || "unknown";
}

function moduleSummary(entries) {
  const out = {};
  for (const entry of entries) {
    const mod = entry.module || "unknown";
    if (!out[mod]) out[mod] = { total: 0, pass: 0, fail: 0, skip: 0 };
    out[mod].total += 1;
    out[mod][entry.status] += 1;
  }
  return out;
}

function buildReport({ startedAt, startedMs, tests, passed, failed, skipped, exitCode }) {
  const finishedAt = new Date().toISOString();
  const durationMs = Math.max(0, Math.round(performance.now() - startedMs));

  const failedTests = tests.filter((t) => t.status === "fail");
  const slowTests = tests
    .filter((t) => t.duration_ms >= slowThresholdMs)
    .sort((a, b) => b.duration_ms - a.duration_ms)
    .slice(0, slowTop);

  return {
    suite_id: "front_js",
    language: "js",
    scope,
    category,
    tests_total: tests.length,
    pass: passed,
    fail: failed,
    skip: skipped,
    tests,
    failed_tests: failedTests,
    slow_tests: slowTests,
    module_summary: moduleSummary(tests),
    perf_violations: [],
    fragility_hints: [],
    started_at: startedAt,
    finished_at: finishedAt,
    duration_ms: durationMs,
    exit_code: exitCode,
  };
}

function writeReport(report) {
  if (!reportFile) return;
  try {
    fs.mkdirSync(path.dirname(reportFile), { recursive: true });
    fs.writeFileSync(reportFile, JSON.stringify(report, null, 2), "utf8");
  } catch (err) {
    console.error(`WARN: no se pudo escribir TESTKIT_REPORT_FILE (${reportFile}): ${err?.message || err}`);
  }
}

const testsDirExists = fs.existsSync(testsDir) && fs.statSync(testsDir).isDirectory();
let tests = (testsDirExists ? walk(testsDir) : [])
  .filter((p) => p.endsWith(".test.mjs"))
  .filter(matchesScope)
  .filter(matchesCategory)
  .sort((a, b) => a.localeCompare(b));

if (match) {
  tests = tests.filter((p) => norm(path.relative(repoRoot, p)).toLowerCase().includes(match));
}

const suiteStartedAt = new Date().toISOString();
const suiteStartedMs = performance.now();

if (!tests.length) {
  const msg = `No se encontraron tests JS en ${testsDir} (scope=${scope}, category=${category}, match=${match || ""}).`;

  banner("FRONT / JS");
  console.log(bold(`Running 0 tests JS (scope=${scope}, category=${category}, failFast=${failFast ? "1" : "0"}, jobs=${jobs})`));
  console.log(dim(`repoRoot:  ${repoRoot}`));
  console.log(dim(`testsDir:  ${testsDir}`));

  if (requireTests) {
    console.error(msg);
    const report = buildReport({ startedAt: suiteStartedAt, startedMs: suiteStartedMs, tests: [], passed: 0, failed: 1, skipped: 0, exitCode: PVT_EXIT_FAIL });
    writeReport(report);
    process.exit(PVT_EXIT_FAIL);
  }

  console.log(gray(`SKIP: ${msg}`));
  const report = buildReport({ startedAt: suiteStartedAt, startedMs: suiteStartedMs, tests: [], passed: 0, failed: 0, skipped: 0, exitCode: PVT_EXIT_SKIP });
  writeReport(report);
  process.exit(PVT_EXIT_SKIP);
}

const loaderPath = path.join(testkitRoot, "utils", "js", "front_loader.mjs");
const loaderUrl = pathToFileURL(loaderPath).href;

const bootstrapDefault = path.join(repoRoot, "test", "front", "_support", "bootstrap.mjs");
const bootstrapPath = process.env.TK_FRONT_BOOTSTRAP || bootstrapDefault;
const bootstrapUrl = fs.existsSync(bootstrapPath) ? pathToFileURL(bootstrapPath).href : null;

banner("FRONT / JS");
console.log(bold(`Running ${tests.length} tests JS (scope=${scope}, category=${category}, failFast=${failFast ? "1" : "0"}, jobs=${jobs})`));
console.log(dim(`repoRoot:  ${repoRoot}`));
console.log(dim(`testsDir:  ${testsDir}`));
if (bootstrapUrl) console.log(dim(`bootstrap: ${bootstrapPath}`));
if (useLoader) console.log(dim(`loader:    ${loaderPath}`));
if (match) console.log(dim(`match:     ${match}`));
console.log("");

if (listOnly) {
  for (const t of tests) {
    console.log(norm(path.relative(repoRoot, t)));
  }
  const listed = tests.map((t) => {
    const rel = norm(path.relative(repoRoot, t));
    return {
      rel,
      file: norm(t),
      module: moduleFromRel(rel),
      tags: tagsForPath(t),
      status: "listed",
      exit_code: 0,
      duration_ms: 0,
    };
  });
  const report = buildReport({ startedAt: suiteStartedAt, startedMs: suiteStartedMs, tests: listed, passed: 0, failed: 0, skipped: 0, exitCode: PVT_EXIT_PASS });
  writeReport(report);
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
    const t0 = performance.now();
    const child = spawn(process.execPath, args, {
      cwd: repoRoot,
      env: { ...process.env, TEST_WORKER_ID: String(workerId), TESTKIT_ROOT: testkitRoot, TK_REPO_ROOT: repoRoot },
      stdio: ["ignore", "pipe", "pipe"],
    });

    let out = "";
    let err = "";

    child.stdout.on("data", (d) => {
      out += d.toString("utf8");
    });
    child.stderr.on("data", (d) => {
      err += d.toString("utf8");
    });

    child.on("close", (code) => {
      resolve({ rel, file: norm(testFile), code: code ?? 1, out, err, durationMs: Math.max(0, Math.round(performance.now() - t0)), tags: tagsForPath(testFile) });
    });
  });
}

let passed = 0;
let failed = 0;
let skipped = 0;
const entries = [];

if (jobs <= 1) {
  for (const t of tests) {
    const rel = norm(path.relative(repoRoot, t));
    console.log(testHead(rel));

    const t0 = performance.now();
    const r = spawnSync(process.execPath, makeArgs(t), {
      cwd: repoRoot,
      env: { ...process.env, TEST_WORKER_ID: "1", TESTKIT_ROOT: testkitRoot, TK_REPO_ROOT: repoRoot },
      stdio: ["ignore", "inherit", "inherit"],
    });

    const durationMs = Math.max(0, Math.round(performance.now() - t0));
    const code = r.status ?? (r.signal ? 128 : 1);

    let status = "fail";
    if (code === 0) {
      passed++;
      status = "pass";
    } else if (code === PVT_EXIT_SKIP || code === 2) {
      skipped++;
      status = "skip";
    } else {
      failed++;
    }

    entries.push({
      rel,
      file: norm(t),
      module: moduleFromRel(rel),
      tags: tagsForPath(t),
      status,
      exit_code: code,
      duration_ms: durationMs,
      stdout: "",
      stderr: "",
    });

    console.log("");
    if (status === "fail" && failFast) break;
  }
} else {
  let next = 0;
  let stop = false;

  async function worker(workerId) {
    while (true) {
      if (stop) return;
      const idx = next++;
      if (idx >= tests.length) return;

      const res = await runOne(tests[idx], workerId);
      console.log(testHead(res.rel));
      if (res.out) process.stdout.write(res.out);
      if (res.err) process.stderr.write(res.err);
      console.log("");

      let status = "fail";
      if (res.code === 0) {
        passed++;
        status = "pass";
      } else if (res.code === PVT_EXIT_SKIP || res.code === 2) {
        skipped++;
        status = "skip";
      } else {
        failed++;
      }

      entries.push({
        rel: res.rel,
        file: res.file,
        module: moduleFromRel(res.rel),
        tags: res.tags,
        status,
        exit_code: res.code,
        duration_ms: res.durationMs,
        stdout: res.out,
        stderr: res.err,
      });

      if (status === "fail" && failFast) {
        stop = true;
        return;
      }
    }
  }

  await Promise.all(Array.from({ length: jobs }, (_, i) => worker(i + 1)));

  if (stop) {
    console.error(red("FAIL-FAST: abortando lanzamiento de nuevos tests (algunos pueden seguir ejecutandose)."));
  }
}

console.log(gray(`Summary JS: ${counts({ pass: passed, fail: failed, skip: skipped })}`));

const exitCode = failed > 0 ? PVT_EXIT_FAIL : (passed === 0 && skipped > 0 ? PVT_EXIT_SKIP : PVT_EXIT_PASS);
const report = buildReport({ startedAt: suiteStartedAt, startedMs: suiteStartedMs, tests: entries, passed, failed, skipped, exitCode });
writeReport(report);

process.exit(exitCode);
