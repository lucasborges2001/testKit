/**
 * TAGS: perf,smoke,slow
 * SCOPE: integration
 */

import path from 'node:path';
import { pathToFileURL } from 'node:url';
import { performance } from 'node:perf_hooks';

const testkitRoot = process.env.TESTKIT_ROOT || path.resolve(process.cwd(), 'testkit');
const assertLib = await import(pathToFileURL(path.join(testkitRoot, 'utils', 'js', 'assert.mjs')).href);
const { t_case, t_assert, TestSkip, t_print_fail, t_print_skip } = assertLib;

try {
  await t_case('js perf smoke template', async () => {
    const maxMs = Number(process.env.TEST_PERF_MAX_MS || 800);
    const t0 = performance.now();

    await new Promise((resolve) => setTimeout(resolve, 10));

    const elapsed = Math.round(performance.now() - t0);
    t_assert(elapsed <= maxMs, `perf threshold exceeded | elapsed_ms=${elapsed} max_ms=${maxMs}`);
  });
  process.exit(0);
} catch (e) {
  if (e instanceof TestSkip) {
    t_print_skip(e);
    process.exit(2);
  }
  t_print_fail(e);
  process.exit(1);
}
