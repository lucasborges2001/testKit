/**
 * TestKit — JS bootstrap (Node/ESM)
 *
 * Se pre-carga por el runner via:
 *   node --import file://.../test/front/_support/bootstrap.mjs <testfile>
 *
 * Objetivo:
 * - Determinismo opcional (TEST_NOW / TEST_RAND_SEED)
 * - Defaults de runtime (TZ)
 * - Un lugar único para hooks comunes, sin repetir en cada test.
 */

// TZ determinista (opt-in):
// - Si seteás TEST_TZ, lo aplicamos.
// - Si no, dejamos el default del entorno.
if (process.env.TEST_TZ && !process.env.TZ) {
  process.env.TZ = process.env.TEST_TZ;
}

// Freeze time (opt-in):
// - Si seteás TEST_NOW (ISO/parseable por Date.parse), y TEST_FREEZE_TIME=1 (default 1),
//   reemplazamos Date para que new Date() y Date.now() sean deterministas.
const FREEZE = (process.env.TEST_FREEZE_TIME || "1") === "1";
if (FREEZE && process.env.TEST_NOW) {
  const ms = Date.parse(process.env.TEST_NOW);
  if (!Number.isNaN(ms)) {
    const OriginalDate = Date;

    function MockDate(...args) {
      if (!(this instanceof MockDate)) {
        // Date() como función devuelve string
        return new OriginalDate(ms).toString();
      }
      if (args.length === 0) return new OriginalDate(ms);
      // @ts-ignore
      return new OriginalDate(...args);
    }

    // @ts-ignore
    MockDate.now = () => ms;
    // @ts-ignore
    MockDate.parse = OriginalDate.parse.bind(OriginalDate);
    // @ts-ignore
    MockDate.UTC = OriginalDate.UTC.bind(OriginalDate);
    MockDate.prototype = OriginalDate.prototype;

    // @ts-ignore
    globalThis.Date = MockDate;
  }
}

// Random seed determinista (opt-in):
// - Si seteás TEST_RAND_SEED, sobreescribimos Math.random() con un PRNG simple.
if (process.env.TEST_RAND_SEED) {
  let seed = parseInt(process.env.TEST_RAND_SEED, 10);
  if (!Number.isFinite(seed)) seed = 1;
  if (seed < 0) seed = -seed;

  // mulberry32
  let a = seed >>> 0;
  const rand = () => {
    a |= 0;
    a = (a + 0x6d2b79f5) | 0;
    let t = Math.imul(a ^ (a >>> 15), 1 | a);
    t = (t + Math.imul(t ^ (t >>> 7), 61 | t)) ^ t;
    return ((t ^ (t >>> 14)) >>> 0) / 4294967296;
  };

  Math.random = rand;
}
