/**
 * TAGS: unit
 * SCOPE: unit
 */

import path from 'node:path';
import { pathToFileURL } from 'node:url';

const testkitRoot = process.env.TESTKIT_ROOT || path.resolve(process.cwd(), 'testkit');
const assertLib = await import(pathToFileURL(path.join(testkitRoot, 'utils', 'js', 'assert.mjs')).href);
const { t_case, t_eq, TestSkip, t_print_fail, t_print_skip } = assertLib;

try {
  await t_case('js unit template', async () => {
    t_eq(1 + 1, 2, 'math sanity');
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
