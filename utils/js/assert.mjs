// test/utils/js/assert.mjs
// Test helpers (sin dependencias externas).
// Exit codes por archivo:
//   0 = PASS
//   1 = FAIL
//   2 = SKIP
//
// Colores ANSI (consistentes con runners):
//   NO_COLOR=1 / FORCE_COLOR=1

import { green, red, yellow, gray, dim } from "./ui.mjs";

export class TestSkip extends Error {}
export class TestFail extends Error {}

export function t_skip(reason) {
  throw new TestSkip(reason);
}

export function t_assert(cond, msg = "assert failed") {
  if (!cond) throw new TestFail(msg);
}

export function t_eq(a, b, msg = "") {
  if (a !== b) {
    const m = msg || "expected strict equality";
    // Message stays readable even if NO_COLOR=1 (ui.mjs will no-op)
    throw new TestFail(`${m} | ${red("got")}=${JSON.stringify(a)} ${green("expected")}=${JSON.stringify(b)}`);
  }
}

export function t_ne(a, b, msg = "") {
  if (a === b) {
    const m = msg || "expected not equal";
    throw new TestFail(`${m} | ${red("value")}=${JSON.stringify(a)}`);
  }
}

export function t_contains(needle, haystack, msg = "") {
  if (!String(haystack).includes(String(needle))) {
    const m = msg || "expected substring not found";
    throw new TestFail(`${m} | ${yellow("needle")}=${JSON.stringify(needle)}`);
  }
}

export function t_match(re, text, msg = "") {
  const r = typeof re === "string" ? new RegExp(re) : re;
  if (!r.test(String(text))) {
    const m = msg || "pattern did not match";
    throw new TestFail(`${m} | ${yellow("pattern")}=${String(r)}`);
  }
}

export async function t_case(name, fn) {
  await fn();
  process.stdout.write(`  - ${green("PASS")}: ${gray(name)}\n`);
}

export function t_print_fail(e) {
  const msg = e?.message || String(e);
  process.stderr.write(`${red("FAIL")}: ${msg}\n`);

  // Stack is useful but noisy; keep it dim.
  if (e?.stack) process.stderr.write(dim(e.stack) + "\n");
}

export function t_print_skip(e) {
  const msg = e?.message || String(e);
  process.stdout.write(`${yellow("SKIP")}: ${dim(msg)}\n`);
}