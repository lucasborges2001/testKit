// test/utils/js/ui.mjs
// UI helpers for Node test runners.
//
// Env (prioridad):
// - PVT_COLOR=0|1|auto  (default: auto)
// - NO_COLOR=1          disable ANSI
// - FORCE_COLOR=1       force ANSI even when not TTY

function enabled() {
  const pvt = process.env.PVT_COLOR;
  if (pvt && String(pvt).trim() !== "") {
    const v = String(pvt).trim().toLowerCase();
    if (["0", "false", "off", "no"].includes(v)) return false;
    if (["1", "true", "on", "yes"].includes(v)) return true;
    // auto => fallback
  }

  const no = process.env.NO_COLOR;
  if (no && no !== "0") return false;

  const force = process.env.FORCE_COLOR;
  if (force && force !== "0") return true;

  return !!process.stdout.isTTY;
}

const ON = enabled();

export function ansi(s, code) {
  if (!ON) return s;
  return `\u001b[${code}m${s}\u001b[0m`;
}

export const dim = (s) => ansi(s, "90");
export const bold = (s) => ansi(s, "1");
export const red = (s) => ansi(s, "31");
export const green = (s) => ansi(s, "32");
export const yellow = (s) => ansi(s, "33");
export const blue = (s) => ansi(s, "34");
export const cyan = (s) => ansi(s, "36");
export const gray = (s) => ansi(s, "37");

export function banner(title) {
  const line = "=".repeat(78);
  console.log("\n" + dim(line));
  console.log(bold(cyan(title)));
  console.log(dim(line) + "\n");
}

export function testHead(relPath) {
  return `${bold(cyan("==>"))} ${gray(relPath)}`;
}

export function statusLabel(status) {
  const u = String(status).toUpperCase();
  if (u === "PASS" || u === "OK") return green(u);
  if (u === "SKIP") return yellow(u);
  if (u === "FAIL" || u === "ERROR") return red(u);
  return u;
}

export function counts({ pass, fail, skip }) {
  const p = green(String(pass));
  const f = fail > 0 ? red(String(fail)) : green("0");
  const s = skip > 0 ? yellow(String(skip)) : green("0");
  return `PASS=${p} FAIL=${f} SKIP=${s}`;
}
