#!/usr/bin/env node
/**
 * Runner de tests JS (Node, ESM).
 *
 * - Ejecuta cada `*.test.mjs` en proceso separado.
 * - Consume una selección precomputada desde PHP cuando está disponible.
 * - Soporta loader ESM para redirigir imports test/front -> <TK_PUBLIC_DIR>.
 * - Soporta paralelismo por archivos (TEST_JOBS).
 * - Escribe <suite>_latest.json + <suite>_YYYYmmdd_HHmmss.json y rota (máx configurable por TEST_REPORT_KEEP).
 * - Mantiene `runs_latest.json` como índice compacto de corridas recientes.
 */

import { spawn, spawnSync } from "node:child_process";
import crypto from "node:crypto";
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
const requireTests = (process.env.TEST_JS_REQUIRE_TESTS || process.env.TEST_REQUIRE_TESTS || "0") === "1";
const jobs = Math.max(1, parseInt(process.env.TEST_JOBS || "1", 10) || 1);
const useLoader = (process.env.TEST_USE_PUBLIC_LOADER || "1") === "1";
const slowThresholdMs = Math.max(1, parseInt(process.env.TEST_SLOW_THRESHOLD_MS || "1500", 10) || 1500);
const slowTop = Math.max(1, parseInt(process.env.TEST_SLOW_TOP || "10", 10) || 10);
const perfMaxMs = Math.max(0, parseInt(process.env.TEST_PERF_MAX_MS || "0", 10) || 0);
const perfWarnMs = Math.max(0, parseInt(process.env.TEST_PERF_WARN_MS || "0", 10) || 0);
const reportKeep = Math.max(1, parseInt(process.env.TEST_REPORT_KEEP || "5", 10) || 5);
const runsIndexKeep = Math.max(1, parseInt(process.env.TEST_RUNS_INDEX_KEEP || String(reportKeep), 10) || reportKeep);
const envRunId = (process.env.TEST_RUN_ID || "").trim();
const envMetaRunId = (process.env.TEST_META_RUN_ID || "").trim();

const envReportRoot = process.env.TESTKIT_REPORT_ROOT || "";
const envModuleScope = process.env.TESTKIT_SELECTED_MODULE_SCOPE || "";
const envReportScopeRel = process.env.TESTKIT_REPORT_SCOPE_REL || "";
const legacyReportFile = process.env.TESTKIT_REPORT_FILE || "";
const selectedTestsFile = process.env.TESTKIT_SELECTED_TESTS_FILE || "";

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

function buildRunId() {
  const stamp = new Date().toISOString().replace(/[-:]/g, "").replace(".000Z", "Z").replace(".","").replace("T", "T");
  return `${stamp}_${crypto.randomBytes(3).toString("hex")}`;
}

function loadJsonFile(filePath) {
  try {
    if (!fs.existsSync(filePath)) return {};
    const raw = fs.readFileSync(filePath, "utf8");
    if (!raw.trim()) return {};
    const parsed = JSON.parse(raw);
    return parsed && typeof parsed === "object" ? parsed : {};
  } catch {
    return {};
  }
}

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
    integration: ["integration", "integracion"],
    e2e: ["e2e"],
  };

  for (const [tag, tokens] of Object.entries(map)) {
    if (tokens.some((token) => n.includes(`/${token}/`) || new RegExp(`(?:_|-|\\.)${token}\\.test\\.(mjs|js|ts)$`, "i").test(n) || new RegExp(`(?:^|[\\/_\\-.])${token}(?:[\\/_\\-.]|\\.test\\.(mjs|js|ts)$)`, "i").test(n))) {
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
  return Object.fromEntries(Object.entries(out).sort(([a], [b]) => a.localeCompare(b)));
}

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
    error_type: t.perf_violation ? "perf_threshold" : `exit_code_${t.exit_code}`,
    message: t.perf_violation?.message || message,
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

function suiteStatusFor(tests, passed, failed, skipped, listMode) {
  if (listMode) return "listed";
  if (!tests.length) return "no_tests";
  if (failed > 0) return "failed";
  if (passed === 0 && skipped > 0) return "all_skipped";
  return "passed";
}

function noTestsReasonFor(tests) {
  if (tests.length) return null;
  let msg = `no tests matched the current filters (scope=${scope}, category=${category}`;
  if (match) msg += `, match=${match}`;
  msg += ")";
  return msg;
}

function failureKeys(report) {
  const keys = new Set();
  const suiteId = (report?.suite_id || "").trim();

  if (Array.isArray(report?.failures)) {
    for (const failure of report.failures) {
      if (!failure || typeof failure !== "object") continue;
      const testId = String(failure.test_id || failure.file || "").trim();
      if (!testId) continue;
      const errorType = String(failure.error_type || "").trim();
      let key = `${suiteId ? `${suiteId}::` : ""}${testId}`;
      if (errorType) key += `::${errorType}`;
      keys.add(key);
    }
  }

  if (!keys.size && Array.isArray(report?.failed_tests)) {
    for (const failure of report.failed_tests) {
      if (!failure || typeof failure !== "object") continue;
      const testId = String(failure.rel || failure.file || "").trim();
      if (testId) keys.add(`${suiteId ? `${suiteId}::` : ""}${testId}`);
    }
  }

  if (!keys.size && Array.isArray(report?.failed_files)) {
    for (const file of report.failed_files) {
      const value = String(file || "").trim();
      if (value) keys.add(value);
    }
  }

  return [...keys].sort();
}

function failedFilesFromReport(report) {
  const files = new Set();

  if (Array.isArray(report?.failed_files)) {
    for (const file of report.failed_files) {
      const value = String(file || "").trim();
      if (value) files.add(value);
    }
  }

  if (Array.isArray(report?.failures)) {
    for (const failure of report.failures) {
      if (!failure || typeof failure !== "object") continue;
      const value = String(failure.file || "").trim();
      if (value) files.add(value);
    }
  }

  if (Array.isArray(report?.failed_tests)) {
    for (const failure of report.failed_tests) {
      if (!failure || typeof failure !== "object") continue;
      const value = String(failure.rel || failure.file || "").trim();
      if (value) files.add(value);
    }
  }

  return [...files].sort();
}

function topFailureMessagesFromReport(report, limit = 3) {
  const messages = [];

  if (Array.isArray(report?.top_failure_messages)) {
    for (const row of report.top_failure_messages) {
      if (!row || typeof row !== "object") continue;
      const value = String(row.message || "").trim();
      if (value) messages.push(value);
    }
  }

  if (!messages.length && Array.isArray(report?.failures)) {
    for (const failure of report.failures) {
      if (!failure || typeof failure !== "object") continue;
      const value = String(failure.message || "").trim();
      if (value) messages.push(value);
    }
  }

  return [...new Set(messages)].slice(0, Math.max(0, limit));
}

function diffFailures(previous, current) {
  const previousFailures = new Set(failureKeys(previous));
  const currentFailures = new Set(failureKeys(current));
  const newFailures = [...currentFailures].filter((key) => !previousFailures.has(key)).sort();
  const resolvedFailures = [...previousFailures].filter((key) => !currentFailures.has(key)).sort();
  return { newFailures, resolvedFailures };
}

function buildRunsIndexEntry(report, latestPath, tsPath) {
  const filters = report?.filters && typeof report.filters === "object" ? report.filters : {};
  return {
    record_id: `suite::${report.run_id || ""}::${report.suite_id || "front_js"}`,
    kind: "suite",
    run_id: report.run_id || "",
    meta_run_id: report.meta_run_id || null,
    previous_run_id: report.previous_run_id || null,
    suite_id: report.suite_id || "front_js",
    target: report.target || filters.target || null,
    scope: report.scope || filters.scope || null,
    category: report.category || filters.category || null,
    match: report.match || filters.match || null,
    suite_status: report.suite_status || null,
    summary: report.summary || {},
    started_at: report.started_at || null,
    finished_at: report.finished_at || null,
    duration_ms: Number(report.duration_ms || 0),
    selected_module_scope: report.selected_module_scope || "",
    report_scope_rel: report.report_scope_rel || "",
    has_failures: Boolean(report.has_failures || Number(report.fail || 0) > 0),
    failed_files: failedFilesFromReport(report),
    top_failure_messages: topFailureMessagesFromReport(report, 3),
    new_failures_count: Number(report.new_failures_count || 0),
    resolved_failures_count: Number(report.resolved_failures_count || 0),
    report_files: {
      latest: path.basename(latestPath),
      timestamped: path.basename(tsPath),
    },
  };
}

function updateRunsIndex(reportRoot, entry, keep) {
  const indexPath = path.join(reportRoot, "runs_latest.json");
  const existing = loadJsonFile(indexPath);
  let rows = [];

  if (Array.isArray(existing?.runs)) {
    rows = existing.runs.filter((row) => row && typeof row === "object");
  } else if (Array.isArray(existing)) {
    rows = existing.filter((row) => row && typeof row === "object");
  }

  rows = rows.filter((row) => String(row.record_id || "") !== String(entry.record_id || ""));
  rows.unshift(entry);
  rows = rows.slice(0, Math.max(1, keep));

  const payload = {
    updated_at: new Date().toISOString(),
    runs: rows,
  };

  fs.writeFileSync(indexPath, JSON.stringify(payload, null, 2), "utf8");
}

function buildReport({ startedAt, startedMs, tests, passed, failed, skipped, exitCode, reportRoot, moduleScope, reportScopeRel, commonDir, listMode }) {
  const finishedAt = new Date().toISOString();
  const durationMs = Math.max(0, Math.round(performance.now() - startedMs));
  const failedTests = tests.filter((t) => t.status === "fail");
  const slowTests = tests
    .filter((t) => t.duration_ms >= slowThresholdMs)
    .sort((a, b) => b.duration_ms - a.duration_ms)
    .slice(0, slowTop);
  const perfViolations = tests.filter((t) => Boolean(t.perf_violation));
  const failures = failedTests.map(buildFailureEntry);
  const groupedFailures = groupFailures(failures);
  const selectedTestFiles = tests.map((t) => t.rel);
  const suiteStatus = suiteStatusFor(tests, passed, failed, skipped, listMode);
  const runId = envRunId || buildRunId();

  return {
    report_contract_version: 2,
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
    suite_status: suiteStatus,
    no_tests_reason: noTestsReasonFor(tests),
    runner_capabilities: {
      shared_discovery_contract: Boolean(selectedTestsFile),
      perf_thresholds: true,
      fragility_history: false,
      module_scoped_reports: true,
      native_coverage_artifacts: false,
      structured_coverage_diagnostics: false,
      coverage_formats: [],
      suite_engine: "front_js",
    },
    summary: {
      total: tests.length,
      passed,
      failed,
      skipped,
      duration_ms: durationMs,
      suite_status: suiteStatus,
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
    perf_violations: perfViolations,
    fragility_hints: [],
    failure_contract: {
      canonical: "failures",
      legacy_fallback: "failed_tests",
    },
    started_at: startedAt,
    finished_at: finishedAt,
    duration_ms: durationMs,
    exit_code: exitCode,
    run_id: runId,
    meta_run_id: envMetaRunId || envRunId || null,
    run_kind: "suite",
    report_keep: reportKeep,
    runs_index_keep: runsIndexKeep,
    filters: {
      suite: "front_js",
      scope,
      category,
      match,
    },
  };
}

function writeReport(report, reportRoot) {
  try {
    fs.mkdirSync(reportRoot, { recursive: true });
    const now = new Date();
    const ts = now.toISOString().replace(/-/g, "").replace(/:/g, "").replace("T", "_").slice(0, 15);
    const suiteIdSafe = "front_js";
    const latestPath = path.join(reportRoot, `${suiteIdSafe}_latest.json`);
    const tsPath = path.join(reportRoot, `${suiteIdSafe}_${ts}.json`);
    const previous = loadJsonFile(latestPath);
    const delta = diffFailures(previous, report);

    const decorated = {
      ...report,
      previous_run_id: previous?.run_id || previous?.meta_run_id || null,
      new_failures: delta.newFailures,
      resolved_failures: delta.resolvedFailures,
      new_failures_count: delta.newFailures.length,
      resolved_failures_count: delta.resolvedFailures.length,
      report_links: {
        latest: path.basename(latestPath),
        timestamped: path.basename(tsPath),
        runs_index: "runs_latest.json",
      },
    };

    const json = JSON.stringify(decorated, null, 2);

    fs.writeFileSync(latestPath, json, "utf8");
    fs.writeFileSync(tsPath, json, "utf8");
    pruneOldRuns(reportRoot, suiteIdSafe, reportKeep);
    updateRunsIndex(reportRoot, buildRunsIndexEntry(decorated, latestPath, tsPath), runsIndexKeep);

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

function loadSelectedEntries() {
  if (!selectedTestsFile || !fs.existsSync(selectedTestsFile)) {
    return null;
  }

  try {
    const raw = fs.readFileSync(selectedTestsFile, "utf8");
    const parsed = JSON.parse(raw);
    if (!Array.isArray(parsed)) return null;

    return parsed
      .filter((entry) => entry && typeof entry === "object")
      .map((entry) => {
        const rel = norm(String(entry.rel || path.relative(repoRoot, String(entry.file || ""))));
        const file = norm(String(entry.file || path.join(repoRoot, rel)));
        return {
          file,
          rel,
          module: String(entry.module || moduleFromRel(rel)),
          tags: Array.isArray(entry.tags) ? Array.from(new Set(entry.tags.map((t) => String(t).toLowerCase().trim()).filter(Boolean))) : tagsForPath(file),
        };
      })
      .sort((a, b) => a.rel.localeCompare(b.rel));
  } catch (err) {
    console.error(`WARN: no se pudo leer TESTKIT_SELECTED_TESTS_FILE=${selectedTestsFile}: ${err?.message || err}`);
    return null;
  }
}

const testsDirExists = fs.existsSync(testsDir) && fs.statSync(testsDir).isDirectory();
let testEntries = loadSelectedEntries();
if (!testEntries) {
  testEntries = (testsDirExists ? walk(testsDir) : [])
    .filter((p) => p.endsWith(".test.mjs"))
    .filter(matchesScope)
    .filter(matchesCategory)
    .map((p) => {
      const rel = norm(path.relative(repoRoot, p));
      return { file: norm(p), rel, module: moduleFromRel(rel), tags: tagsForPath(p) };
    })
    .sort((a, b) => a.rel.localeCompare(b.rel));

  if (match) {
    testEntries = testEntries.filter((entry) => entry.rel.toLowerCase().includes(match));
  }
}

const testRels = testEntries.map((t) => t.rel);
const computedReportRoot = envReportRoot || resolveReportRootFromRels(testRels);
const computedModuleScope = envModuleScope || (() => {
  const mods = new Set(testRels.map(extractFunctionalModule).filter(Boolean));
  return mods.size === 1 ? [...mods][0] : "";
})();
const computedReportScopeRel = envReportScopeRel || norm(path.relative(repoRoot, computedReportRoot));
const computedCommonDir = commonDirFromRels(testRels);

const suiteStartedAt = new Date().toISOString();
const suiteStartedMs = performance.now();

if (!testEntries.length) {
  const msg = `No se encontraron tests JS en ${testsDir} (scope=${scope}, category=${category}, match=${match || ""}).`;

  banner("FRONT / JS");
  console.log(bold(`Running 0 tests JS (scope=${scope}, category=${category}, failFast=${failFast ? "1" : "0"}, jobs=${jobs})`));
  console.log(dim(`repoRoot:    ${repoRoot}`));
  console.log(dim(`testsDir:    ${testsDir}`));
  console.log(dim(`reportRoot:  ${computedReportRoot}`));

  const exitCode = requireTests ? PVT_EXIT_FAIL : PVT_EXIT_SKIP;
  if (requireTests) console.error(msg);
  else console.log(gray(`SKIP: ${msg}`));

  const report = buildReport({
    startedAt: suiteStartedAt, startedMs: suiteStartedMs,
    tests: [], passed: 0, failed: 0, skipped: 0, exitCode,
    reportRoot: computedReportRoot, moduleScope: computedModuleScope,
    reportScopeRel: computedReportScopeRel, commonDir: computedCommonDir, listMode: false,
  });
  writeReport(report, computedReportRoot);
  process.exit(exitCode);
}

const loaderPath = path.join(testkitRoot, "utils", "js", "front_loader.mjs");
const loaderUrl = pathToFileURL(loaderPath).href;
const bootstrapDefault = path.join(repoRoot, "test", "front", "_support", "bootstrap.mjs");
const bootstrapPath = process.env.TK_FRONT_BOOTSTRAP || bootstrapDefault;
const bootstrapUrl = fs.existsSync(bootstrapPath) ? pathToFileURL(bootstrapPath).href : null;

banner("FRONT / JS");
console.log(bold(`Running ${testEntries.length} tests JS (scope=${scope}, category=${category}, failFast=${failFast ? "1" : "0"}, jobs=${jobs})`));
console.log(dim(`repoRoot:    ${repoRoot}`));
console.log(dim(`testsDir:    ${testsDir}`));
console.log(dim(`reportRoot:  ${computedReportRoot}`));
if (computedModuleScope) console.log(dim(`module:      ${computedModuleScope}`));
if (bootstrapUrl) console.log(dim(`bootstrap:   ${bootstrapPath}`));
if (useLoader) console.log(dim(`loader:      ${loaderPath}`));
if (match) console.log(dim(`match:       ${match}`));
console.log("");

if (listOnly) {
  for (const t of testEntries) {
    console.log(t.rel);
  }
  const listed = testEntries.map((t) => ({
    rel: t.rel,
    file: t.file,
    module: t.module,
    tags: t.tags,
    status: "listed",
    exit_code: 0,
    duration_ms: 0,
    stdout: "",
    stderr: "",
  }));
  const report = buildReport({
    startedAt: suiteStartedAt, startedMs: suiteStartedMs,
    tests: listed, passed: 0, failed: 0, skipped: 0, exitCode: PVT_EXIT_PASS,
    reportRoot: computedReportRoot, moduleScope: computedModuleScope,
    reportScopeRel: computedReportScopeRel, commonDir: computedCommonDir, listMode: true,
  });
  writeReport(report, computedReportRoot);
  process.exit(PVT_EXIT_PASS);
}

function makeArgs(testFile) {
  const args = [];
  if (bootstrapUrl) args.push("--import", bootstrapUrl);
  if (useLoader) args.push("--loader", loaderUrl);
  args.push(testFile);
  return args;
}

function applyPerfBudgets(entry) {
  const tags = Array.isArray(entry.tags) ? entry.tags : [];
  const perfRelevant = category === "perf" || category === "stress" || tags.includes("perf") || tags.includes("stress");

  if (perfMaxMs > 0 && perfRelevant && entry.duration_ms > perfMaxMs) {
    entry.status = "fail";
    entry.exit_code = PVT_EXIT_FAIL;
    entry.perf_violation = {
      max_ms: perfMaxMs,
      actual_ms: entry.duration_ms,
      message: "Tiempo excede threshold de performance.",
    };
  }

  if (perfWarnMs > 0 && entry.duration_ms > perfWarnMs) {
    entry.perf_warning = {
      warn_ms: perfWarnMs,
      actual_ms: entry.duration_ms,
    };
  }

  return entry;
}

function classifyStatus(code) {
  if (code === 0) return "pass";
  if (code === PVT_EXIT_SKIP || code === 2) return "skip";
  return "fail";
}

function countEntry(entry, counters) {
  if (entry.status === "pass") counters.passed += 1;
  else if (entry.status === "skip") counters.skipped += 1;
  else counters.failed += 1;
}

function runOne(entry, workerId) {
  const args = makeArgs(entry.file);
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
      let result = {
        rel: entry.rel,
        file: entry.file,
        module: entry.module,
        tags: entry.tags,
        status: classifyStatus(code ?? 1),
        exit_code: code ?? 1,
        duration_ms: Math.max(0, Math.round(performance.now() - t0)),
        stdout: out,
        stderr: err,
      };
      resolve(applyPerfBudgets(result));
    });
  });
}

const counters = { passed: 0, failed: 0, skipped: 0 };
const entries = [];

if (jobs <= 1) {
  for (const entry of testEntries) {
    console.log(testHead(entry.rel));

    const t0 = performance.now();
    const r = spawnSync(process.execPath, makeArgs(entry.file), {
      cwd: repoRoot,
      env: { ...process.env, TEST_WORKER_ID: "1", TESTKIT_ROOT: testkitRoot, TK_REPO_ROOT: repoRoot },
      encoding: "utf8",
      maxBuffer: 10 * 1024 * 1024,
    });

    const out = typeof r.stdout === "string" ? r.stdout : "";
    const err = typeof r.stderr === "string" ? r.stderr : "";
    if (out) process.stdout.write(out);
    if (err) process.stderr.write(err);

    let result = {
      rel: entry.rel,
      file: entry.file,
      module: entry.module,
      tags: entry.tags,
      status: classifyStatus(r.status ?? (r.signal ? 128 : 1)),
      exit_code: r.status ?? (r.signal ? 128 : 1),
      duration_ms: Math.max(0, Math.round(performance.now() - t0)),
      stdout: out,
      stderr: err,
    };
    result = applyPerfBudgets(result);
    countEntry(result, counters);
    entries.push(result);

    console.log("");
    if (result.status === "fail" && failFast) break;
  }
} else {
  let next = 0;
  let stop = false;

  async function worker(workerId) {
    while (true) {
      if (stop) return;
      const idx = next++;
      if (idx >= testEntries.length) return;

      const res = await runOne(testEntries[idx], workerId);
      console.log(testHead(res.rel));
      if (res.stdout) process.stdout.write(res.stdout);
      if (res.stderr) process.stderr.write(res.stderr);
      console.log("");

      countEntry(res, counters);
      entries.push(res);
      if (res.status === "fail" && failFast) {
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

console.log(gray(`Summary JS: ${counts({ pass: counters.passed, fail: counters.failed, skip: counters.skipped })}`));

const exitCode = counters.failed > 0 ? PVT_EXIT_FAIL : (counters.passed === 0 && counters.skipped > 0 ? PVT_EXIT_SKIP : PVT_EXIT_PASS);
const report = buildReport({
  startedAt: suiteStartedAt,
  startedMs: suiteStartedMs,
  tests: entries,
  passed: counters.passed,
  failed: counters.failed,
  skipped: counters.skipped,
  exitCode,
  reportRoot: computedReportRoot,
  moduleScope: computedModuleScope,
  reportScopeRel: computedReportScopeRel,
  commonDir: computedCommonDir,
  listMode: false,
});
writeReport(report, computedReportRoot);

process.exit(exitCode);
