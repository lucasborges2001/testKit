/**
 * TAGS: integration,contract,critical
 * SCOPE: integration
 */

import path from 'node:path';
import { pathToFileURL } from 'node:url';

const testkitRoot = process.env.TESTKIT_ROOT || path.resolve(process.cwd(), 'testkit');
const assertLib = await import(pathToFileURL(path.join(testkitRoot, 'utils', 'js', 'assert.mjs')).href);
const { t_case, t_assert, t_eq, TestSkip, t_print_fail, t_print_skip } = assertLib;

try {
  await t_case('js contract template', async () => {
    const payload = { ok: true, code: 'OK' };
    t_assert(payload.ok === true, 'expected ok=true');
    t_eq(payload.code, 'OK', 'expected stable code');
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
