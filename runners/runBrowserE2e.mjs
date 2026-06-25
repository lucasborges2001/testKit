#!/usr/bin/env node
import fs from "node:fs";
import { createRequire } from "node:module";
import path from "node:path";
import { pathToFileURL } from "node:url";

import { browserConfig } from "../utils/js/browser/env.mjs";

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
    ignoreHTTPSErrors: true,
  };
  if (config.video !== "off") {
    contextOptions.recordVideo = { dir: config.artifactsDir };
  }

  const context = await browser.newContext(contextOptions);
  context.setDefaultTimeout(config.timeoutMs);
  context.setDefaultNavigationTimeout(config.timeoutMs);
  const page = await context.newPage();
  const apiRequest = await request.newContext({ baseURL: config.baseURL, ignoreHTTPSErrors: true });

  if (shouldStartTrace(config.trace)) {
    await context.tracing.start({ screenshots: true, snapshots: true, sources: true });
  }

  let failed = false;
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
      },
    });
  } catch (error) {
    failed = true;
    if (config.screenshot !== "off") {
      const screenshot = path.join(config.artifactsDir, `failure-${Date.now()}.png`);
      await page.screenshot({ path: screenshot, fullPage: true }).catch(() => {});
      console.error(`[browser-e2e] screenshot: ${screenshot}`);
    }
    console.error(`[browser-e2e] FAIL ${path.relative(process.cwd(), specPath)}`);
    console.error(error && error.stack ? error.stack : String(error));
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
    await apiRequest.dispose().catch(() => {});
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
  console.error(error && error.stack ? error.stack : String(error));
  process.exit(3);
});
