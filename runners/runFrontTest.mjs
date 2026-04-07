#!/usr/bin/env node
/**
 * Runner de tests JS (Node, ESM).
 *
 * - Ejecuta cada `*.test.mjs` en proceso separado.
 * - Soporta loader ESM para redirigir imports test/front -> <TK_PUBLIC_DIR>.
 * - Soporta paralelismo por archivos (TEST_JOBS).
 * - Soporta filtrado por scope/category/match.
 * - Resuelve report root desde los tests descubiertos (test/<side>/<module>/report).
 * - Escribe <suite>_latest.json + <suite>_YYYYmmdd_HHmmss.json y rota (máx 5 por prefijo).
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

const testRel = process.env.TK_FRONT_JS_DIR || "test/front";
const testsDirPreferred = path.join(repoRoot, testRel, "tests");
const testsDir = fs.existsSync(testsDirPreferred) ? testsDirPreferred : path.join(repoRoot, testRel);

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

// Report root: prefer the PHP-precomputed value; fall back to computing from discovered tests.
const envReportRoot = process.env.TESTKIT_REPORT_ROOT || "";
const envModuleScope = process.env.TESTKIT_SELECTED_MODULE_SCOPE || "";
const envReportScopeRel = process.env.TESTKIT_REPORT_SCOPE_REL || "";
// Legacy fallback for external tooling that still reads TESTKIT_REPORT_FILE
const legacyReportFile = process.env.TESTKIT_REPORT_FILE || "";

const VALID_SCOPES = new Set(["unit", "integration", "e2e", "all"]);
if (!VALID_SCOPES.has(scope)) {
  console.error(`TEST_SCOPE invalido: "${scope}". Valores: unit|integration|e2e|all`);
  process.exit(PVT_EXIT_ERROR);
}

// ---------------------------------------------------------------------------
// File helpers
// ---------------------------------------------------------------------------

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

// ---------------------------------------------------------------------------
// Scope / tag helpers
// ---------------------------------------------------------------------------

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
    out[mod][entry.status] = (out[mod][entry.status] || 0) + 1;
  }
  return out;
}

// ---------------------------------------------------------------------------
// Report root resolution (mirrors Paths::resolveReportRoot in PHP)
// ---------------------------------------------------------------------------

function extractFunctionalModule(rel) {
  const parts = norm(rel).split("/").filter(Boolean);
  if (parts.length < 3 || parts[0] !== "test") return null;
  if (parts[1] !== "back" && parts[1] !== "front") return null;
  return `${parts[1]}/${parts[2]}`;
}

function resolveReportRootFromRels(rels) {
  const fallback = path.join(repoRoot, "test", "reports");
  if (!rels.length) return fallback;

  const modules = new Set();
  for (const rel of rels) {
    const m = extractFunctionalModule(rel);
    if (m === null) return fallback;
    modules.add(m);
  }
  if (modules.size !== 1) return fallback;

  const [module] = modules;
  return path.join(repoRoot, "test", module, "report");
}

function commonDirFromRels(rels) {
  if (!rels.length) return "";
  const dirs = [...new Set(rels.map((r) => norm(path.dirname(r))))];
  if (dirs.length === 1) return dirs[0];
  const parts = dirs.map((d) => d.split("/").filter(Boolean));
  const minLen = Math.min(...parts.map((p) => p.length));
  const common = [];
  for (let i = 0; i < minLen; i++) {
    const seg = parts[0][i];
    if (parts.every((p) => p[i] === seg)) {
      common.push(seg);
    } else {
      break;
    }
  }
  return common.join("/");
}

// ---------------------------------------------------------------------------
// Retention / pruning (mirrors ResultWriter::pruneOldRuns in PHP)
// ---------------------------------------------------------------------------

function pruneOldRuns(dir, prefix, keep = 5) {
  try {
    const safePrefix = prefix.toLowerCase().replace(/[^a-z0-9._-]+/g, "_");
    const re = new RegExp(`^${safePrefix.replace(/[.*+?^${}()|[\]\\]/g, "\\$&")}_\\d{8}_\\d{6}\\.json$`);
    const files = fs.readdirSync(dir).filter((f) => re.test(f)).sort();
    for (let i = 0; i < files.length - keep; i++) {
      try { fs.unlinkSync(path.join(dir, files[i])); } catch { /* ignore */ }
    }
  } catch { /* ignore if dir does not exist yet */ }
}

// ---------------------------------------------------------------------------
// Failure enrichment
// ---------------------------------------------------------------------------

function textExcerpt(text, maxLines) {
  if (!text) return null;
  const lines = text.split("\n").filter((l) => l.trim());
  if (!lines.length) return null;
  return lines.slice(0, maxLines).join("\n");
}

function extractFirstMessage(text) {
  if (!text) return null;
  for (const line of text.split("\n")) {
    const t = line.trim();
    if (!t) continue;
    if (/^(at\s+|#\d+\s|Stack trace:)/.test(t)) continue;
    return t.slice(0, 200);
  }
  return null;
}

function extractTrace(text) {
  if (!text) return null;
  const lines = text.split("\n").filter((l) => /^\s*(at\s+|#\d+|\w.*:\d+)/.test(l));
  if (!lines.length) return null;
  return lines.slice(0, 10).join("\n");
}

function buildFailureEntry(t) {
  const stdout = t.stdout || "";
  const stderr = t.stderr || "";

  const message = extractFirstMessage(stderr) || extractFirstMessage(stdout) || null;
  const traceExcerpt = extractTrace(stderr || stdout);
  const stdoutExcerpt = textExcerpt(stdout, 15);
  const stderrExcerpt = textExcerpt(stderr, 15);

  const tags = t.tags || [];
  const scopeTags = tags.filter((tag) => ["unit", "integration", "e2e"].includes(tag));
  const catTags = tags.filter((tag) => !["unit", "integration", "e2e"].includes(tag));

  return {
    test_id: t.rel || t.file || "",
    test_name: path.basename(t.rel || t.file || "", ".test.mjs"),
    suite: t.module || "",
    scope: scopeTags.join(","),
    file: t.rel || "",
    line: null,
    category: catTags.join(","),
    status: t.status,
    duration_ms: t.duration_ms,
    error_type: `exit_code_${t.exit_code}`,
    message,
    assertion: null,
    diff_excerpt: null,
    trace_excerpt: traceExcerpt || null,
    stdout_excerpt: stdoutExcerpt || null,
    stderr_excerpt: stderrExcerpt || null,
  };
}

function groupFailures(failures) {
  const byFile = {};
  const byErrorType = {};
  const byMessage = {};

  for (const f of failures) {
    const testId = f.test_id || f.file || "unknown";
    const file = f.file || "unknown";
    const errorType = f.error_type || "unknown";
    const msg = f.message || "";

    if (!byFile[file]) byFile[file] = [];
    byFile[file].push(testId);

    if (!byErrorType[errorType]) byErrorType[errorType] = [];
    byErrorType[errorType].push(testId);

    if (msg) {
      const normMsg = msg.replace(/\s+/g, " ").slice(0, 80);
      if (!byMessage[normMsg]) byMessage[normMsg] = [];
      byMessage[normMsg].push(testId);
    }
  }

  return { by_file: byFile, by_error_type: byErrorType, by_message: byMessage };
}

// ---------------------------------------------------------------------------
// Report builder
// ---------------------------------------------------------------------------

function buildReport({ startedAt, startedMs, tests, passed, failed, skipped, exitCode, reportRoot, moduleScope, reportScopeRel, commonDir }) {
  const finishedAt = new Date().toISOString();
  const durationMs = Math.max(0, Math.round(performance.now() - startedMs));

  const failedTests = tests.filter((t) => t.status === "fail");
  const slowTests = tests
    .filter((t) => t.duration_ms >= slowThresholdMs)
    .sort((a, b) => b.duration_ms - a.duration_ms)
    .slice(0, slowTop);

  const failures = failedTests.map(buildFailureEntry);
  const groupedFailures = groupFailures(failures);
  const selectedTestFiles = tests.map((t) => t.rel);

  return {
    suite_id: "front_js",
    language: "js",
    scope,
    category,
    match,
    report_root: reportRoot,
    report_scope_rel: reportScopeRel,
    selected_module_scope: moduleScope,
    selected_common_dir: commonDir,
    selected_test_count: tests.length,
    selected_test_files: selectedTestFiles,
    summary: {
      total: tests.length,
      passed,
      failed,
      skipped,
      duration_ms: durationMs,
    },
    tests_total: tests.length,
    pass: passed,
    fail: failed,
    skip: skipped,
    tests,
    failures,
    grouped_failures: groupedFailures,
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

// ---------------------------------------------------------------------------
// Report writer (latest + timestamped + prune)
// ---------------------------------------------------------------------------

function writeReport(report, reportRoot) {
  try {
    fs.mkdirSync(reportRoot, { recursive: true });

    const now = new Date();
    const ts = now.toISOString().replace(/-/g, "").replace(/:/g, "").replace("T", "_").slice(0, 15);
    const suiteIdSafe = "front_js";

    const latestPath = path.join(reportRoot, `${suiteIdSafe}_latest.json`);
    const tsPath = path.join(reportRoot, `${suiteIdSafe}_${ts}.json`);
    const json = JSON.stringify(report, null, 2);

    fs.writeFileSync(latestPath, json, "utf8");
    fs.writeFileSync(tsPath, json, "utf8");
    pruneOldRuns(reportRoot, suiteIdSafe);

    // Legacy: also write to TESTKIT_REPORT_FILE if it points somewhere different
    if (legacyReportFile && norm(legacyReportFile) !== norm(latestPath)) {
      try {
        fs.mkdirSync(path.dirname(legacyReportFile), { recursive: true });
        fs.writeFileSync(legacyReportFile, json, "utf8");
      } catch { /* ignore */ }
    }
  } catch (err) {
    console.error(`WARN: no se pudo escribir reporte (${reportRoot}): ${err?.message || err}`);
  }
}

// ---------------------------------------------------------------------------
// Discovery
// ---------------------------------------------------------------------------

const testsDirExists = fs.existsSync(testsDir) && fs.statSync(testsDir).isDirectory();
let tests = (testsDirExists ? walk(testsDir) : [])
  .filter((p) => p.endsWith(".test.mjs"))
  .filter(matchesScope)
  .filter(matchesCategory)
  .sort((a, b) => a.localeCompare(b));

if (match) {
  tests = tests.filter((p) => norm(path.relative(repoRoot, p)).toLowerCase().includes(match));
}

// Compute report root: prefer value passed by PHP; fall back to self-computed from discovered tests.
const testRels = tests.map((t) => norm(path.relative(repoRoot, t)));
const computedReportRoot = envReportRoot || resolveReportRootFromRels(testRels);
const computedModuleScope = envModuleScope || (() => {
  const mods = new Set(testRels.map(extractFunctionalModule).filter(Boolean));
  return mods.size === 1 ? [...mods][0] : "";
})();
const computedReportScopeRel = envReportScopeRel || norm(path.relative(repoRoot, computedReportRoot));
const computedCommonDir = commonDirFromRels(testRels);

// ---------------------------------------------------------------------------
// Early exit if no tests
// ---------------------------------------------------------------------------

const suiteStartedAt = new Date().toISOString();
const suiteStartedMs = performance.now();

if (!tests.length) {
  const msg = `No se encontraron tests JS en ${testsDir} (scope=${scope}, category=${category}, match=${match || ""}).`;

  banner("FRONT / JS");
  console.log(bold(`Running 0 tests JS (scope=${scope}, category=${category}, failFast=${failFast ? "1" : "0"}, jobs=${jobs})`));
  console.log(dim(`repoRoot:    ${repoRoot}`));
  console.log(dim(`testsDir:    ${testsDir}`));
  console.log(dim(`reportRoot:  ${computedReportRoot}`));

  if (requireTests) {
    console.error(msg);
    const report = buildReport({
      startedAt: suiteStartedAt, startedMs: suiteStartedMs,
      tests: [], passed: 0, failed: 1, skipped: 0, exitCode: PVT_EXIT_FAIL,
      reportRoot: computedReportRoot, moduleScope: computedModuleScope,
      reportScopeRel: computedReportScopeRel, commonDir: computedCommonDir,
    });
    writeReport(report, computedReportRoot);
    process.exit(PVT_EXIT_FAIL);
  }

  console.log(gray(`SKIP: ${msg}`));
  const report = buildReport({
    startedAt: suiteStartedAt, startedMs: suiteStartedMs,
    tests: [], passed: 0, failed: 0, skipped: 0, exitCode: PVT_EXIT_SKIP,
    reportRoot: computedReportRoot, moduleScope: computedModuleScope,
    reportScopeRel: computedReportScopeRel, commonDir: computedCommonDir,
  });
  writeReport(report, computedReportRoot);
  process.exit(PVT_EXIT_SKIP);
}

// ---------------------------------------------------------------------------
// Setup
// ---------------------------------------------------------------------------

const loaderPath = path.join(testkitRoot, "utils", "js", "front_loader.mjs");
const loaderUrl = pathToFileURL(loaderPath).href;

const bootstrapDefault = path.join(repoRoot, "test", "front", "_support", "bootstrap.mjs");
const bootstrapPath = process.env.TK_FRONT_BOOTSTRAP || bootstrapDefault;
const bootstrapUrl = fs.existsSync(bootstrapPath) ? pathToFileURL(bootstrapPath).href : null;

banner("FRONT / JS");
console.log(bold(`Running ${tests.length} tests JS (scope=${scope}, category=${category}, failFast=${failFast ? "1" : "0"}, jobs=${jobs})`));
console.log(dim(`repoRoot:    ${repoRoot}`));
console.log(dim(`testsDir:    ${testsDir}`));
console.log(dim(`reportRoot:  ${computedReportRoot}`));
if (computedModuleScope) console.log(dim(`module:      ${computedModuleScope}`));
if (bootstrapUrl) console.log(dim(`bootstrap:   ${bootstrapPath}`));
if (useLoader) console.log(dim(`loader:      ${loaderPath}`));
if (match) console.log(dim(`match:       ${match}`));
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
      stdout: "",
      stderr: "",
    };
  });
  const report = buildReport({
    startedAt: suiteStartedAt, startedMs: suiteStartedMs,
    tests: listed, passed: 0, failed: 0, skipped: 0, exitCode: PVT_EXIT_PASS,
    reportRoot: computedReportRoot, moduleScope: computedModuleScope,
    reportScopeRel: computedReportScopeRel, commonDir: computedCommonDir,
  });
  writeReport(report, computedReportRoot);
  process.exit(PVT_EXIT_PASS);
}

// ---------------------------------------------------------------------------
// Execution helpers
// ---------------------------------------------------------------------------

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

    child.stdout.on("data", (d) => { out += d.toString("utf8"); });
    child.stderr.on("data", (d) => { err += d.toString("utf8"); });

    child.on("close", (code) => {
      resolve({ rel, file: norm(testFile), code: code ?? 1, out, err, durationMs: Math.max(0, Math.round(performance.now() - t0)), tags: tagsForPath(testFile) });
    });
  });
}

// ---------------------------------------------------------------------------
// Run
// ---------------------------------------------------------------------------

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

// ---------------------------------------------------------------------------
// Final report
// ---------------------------------------------------------------------------

console.log(gray(`Summary JS: ${counts({ pass: passed, fail: failed, skip: skipped })}`));

const exitCode = failed > 0 ? PVT_EXIT_FAIL : (passed === 0 && skipped > 0 ? PVT_EXIT_SKIP : PVT_EXIT_PASS);
const report = buildReport({
  startedAt: suiteStartedAt,
  startedMs: suiteStartedMs,
  tests: entries,
  passed,
  failed,
  skipped,
  exitCode,
  reportRoot: computedReportRoot,
  moduleScope: computedModuleScope,
  reportScopeRel: computedReportScopeRel,
  commonDir: computedCommonDir,
});
writeReport(report, computedReportRoot);

process.exit(exitCode);
