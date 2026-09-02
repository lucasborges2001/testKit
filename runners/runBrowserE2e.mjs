#!/usr/bin/env node
import fs from "node:fs";
import { createRequire } from "node:module";
import path from "node:path";
import { pathToFileURL } from "node:url";

import { browserConfig } from "../utils/js/browser/env.mjs";
import {
  allowsInvalidTls,
  createBlackBoxRuntime,
  createScopedApiRequest,
  sanitizeText,
  sanitizeUrl,
  targetOrigin,
  writeBrowserArtifact,
} from "../utils/js/browser/blackbox.mjs";

async function loadPlaywright() {
  try {
    return await import("@playwright/test");
  } catch (error) {
    try {
      const require = createRequire(import.meta.url);
      return require("@playwright/test");
    } catch (requireError) {
      throw new Error(
        "No se pudo cargar @playwright/test. Rebuild del contenedor testkit o instalacion Playwright requerida. " +
        `${error.message}; ${requireError.message}`
      );
    }
  }
}

function resolveSpec() {
  const spec = process.argv[2] || process.env.TESTKIT_BROWSER_SPEC || "";
  if (spec.trim() === "") {
    throw new Error("Falta spec browser. Usar argv[2] o TESTKIT_BROWSER_SPEC.");
  }

  const absolute = path.resolve(process.env.TK_REPO_ROOT || process.cwd(), spec);
  if (!fs.existsSync(absolute)) {
    throw new Error(`No existe spec browser: ${absolute}`);
  }
  return absolute;
}

function shouldStartTrace(traceMode) {
  return ["on", "retain-on-failure"].includes(traceMode);
}

function shouldKeepTrace(traceMode, failed) {
  return traceMode === "on" || (traceMode === "retain-on-failure" && failed);
}

async function main() {
  const started = Date.now();
  const specPath = resolveSpec();
  const config = browserConfig();
  fs.mkdirSync(config.artifactsDir, { recursive: true });

  const { chromium, request, expect } = await loadPlaywright();
  const browser = await chromium.launch({ headless: config.headless });
  const contextOptions = {
    baseURL: config.baseURL,
    ignoreHTTPSErrors: allowsInvalidTls(config),
  };
  if (config.video !== "off") {
    contextOptions.recordVideo = { dir: config.artifactsDir };
  }

  const context = await browser.newContext(contextOptions);
  context.setDefaultTimeout(config.timeoutMs);
  context.setDefaultNavigationTimeout(config.timeoutMs);

  if (allowsInvalidTls(config)) {
    const allowedOrigin = targetOrigin(config.baseURL);
    await context.route("**/*", async (route) => {
      let origin = "";
      try {
        origin = new URL(route.request().url()).origin;
      } catch {
        await route.abort("blockedbyclient");
        return;
      }
      if (origin !== allowedOrigin) {
        await route.abort("blockedbyclient");
        return;
      }
      await route.continue();
    });
  }

  const page = await context.newPage();
  const rawApiRequest = await request.newContext({
    baseURL: config.baseURL,
    ignoreHTTPSErrors: allowsInvalidTls(config),
  });
  const apiRequest = createScopedApiRequest(rawApiRequest, config);
  const runtime = createBlackBoxRuntime({ page, config });

  const consoleErrors = [];
  const networkFailures = [];
  page.on("console", (msg) => {
    if (msg.type() === "error") {
      consoleErrors.push(sanitizeText(msg.text()));
    }
  });
  page.on("requestfailed", (req) => {
    networkFailures.push({
      url: sanitizeUrl(req.url()),
      error: sanitizeText(req.failure()?.errorText || "request_failed"),
    });
  });

  if (shouldStartTrace(config.trace)) {
    await context.tracing.start({ screenshots: true, snapshots: true, sources: true });
  }

  let failed = false;
  let failureMessage = null;
  let failureScreenshot = null;
  try {
    const mod = await import(pathToFileURL(specPath).href);
    const run = mod.default || mod.run;
    if (typeof run !== "function") {
      throw new Error(`El spec ${specPath} debe exportar default async function o run()`);
    }

    await run({
      page,
      request: apiRequest,
      expect,
      config,
      testkit: {
        specPath,
        repoRoot: process.env.TK_REPO_ROOT || process.cwd(),
        artifactsDir: config.artifactsDir,
        step: runtime.step.bind(runtime),
        assertMutationAllowed: runtime.assertMutationAllowed.bind(runtime),
        assertTargetUrl: runtime.assertTargetUrl.bind(runtime),
      },
    });
  } catch (error) {
    failed = true;
    failureMessage = sanitizeText(error && error.message ? error.message : String(error));
    if (config.screenshot !== "off" && !runtime.sensitiveFailure) {
      const screenshot = path.join(config.artifactsDir, `failure-${Date.now()}.png`);
      const captured = await page.screenshot({ path: screenshot, fullPage: true }).then(() => true).catch(() => false);
      if (captured) {
        failureScreenshot = screenshot;
        console.error(`[browser-e2e] screenshot: ${screenshot}`);
      }
    }
    console.error(`[browser-e2e] FAIL ${path.relative(process.cwd(), specPath)}`);
    console.error(sanitizeText(error && error.stack ? error.stack : String(error)));
  } finally {
    if (shouldStartTrace(config.trace)) {
      if (shouldKeepTrace(config.trace, failed)) {
        const tracePath = path.join(config.artifactsDir, `trace-${Date.now()}.zip`);
        await context.tracing.stop({ path: tracePath }).catch(() => {});
        console.error(`[browser-e2e] trace: ${tracePath}`);
      } else {
        await context.tracing.stop().catch(() => {});
      }
    }

    const title = await page.title().catch(() => "");
    const currentUrl = page.url();
    const artifactPath = writeBrowserArtifact(config, {
      timestamp: new Date().toISOString(),
      phase: "browser_e2e",
      status: failed ? "FAIL" : "PASS",
      duration_ms: Date.now() - started,
      spec: path.relative(process.env.TK_REPO_ROOT || process.cwd(), specPath),
      page_title: sanitizeText(title),
      current_url: sanitizeUrl(currentUrl),
      console_errors: consoleErrors,
      network_failures: networkFailures,
      failure: failureMessage,
      failure_screenshot: failureScreenshot,
      failure_screenshot_suppressed_sensitive: failed && runtime.sensitiveFailure,
      steps: runtime.steps,
    });
    console.error(`[browser-e2e] artifact: ${artifactPath}`);

    await rawApiRequest.dispose().catch(() => {});
    await context.close().catch(() => {});
    await browser.close().catch(() => {});
  }

  if (failed) {
    process.exit(1);
  }

  console.log(`[browser-e2e] OK ${path.relative(process.cwd(), specPath)} time_ms=${Date.now() - started}`);
}

main().catch((error) => {
  console.error("[browser-e2e] ERROR");
  console.error(sanitizeText(error && error.stack ? error.stack : String(error)));
  process.exit(3);
});
