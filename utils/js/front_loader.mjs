/**
 * /test/utils/js/front_loader.mjs
 *
 * Loader ESM para tests de frontend ejecutados en Node (ej: /test/front/runFrontTest.mjs).
 *
 * -----------------------------------------------------------------------------
 * 1) Objetivo (sin tocar el proyecto)
 * -----------------------------------------------------------------------------
 * Este loader existe para que los tests puedan importar código real del proyecto
 * (ej: /public_html/**) sin modificar esos archivos.
 *
 * Problema que resuelve:
 * - Node trata *.js como CommonJS por defecto si no hay "type":"module".
 * - El frontend real usa ESM (export/import) en archivos *.js dentro de /public_html.
 * - En tests, al importar esos .js desde Node, aparece: "Unexpected token 'export'".
 *
 * Solución:
 * - En resolve(): mantenemos aliases estables y redirecciones de paths “fake”.
 * - En load(): cuando el archivo es /<repo>/<TK_PUBLIC_DIR>/../*.js y parece ESM,
 *   devolvemos format="module" para que Node lo parsee como ESM.
 *
 * -----------------------------------------------------------------------------
 * 2) Rutas relevantes (relativas al root del repo)
 * -----------------------------------------------------------------------------
 * - Este archivo:         /test/utils/js/front_loader.mjs
 * - Runner frontend:      /test/front/runFrontTest.mjs
 * - Helpers tests JS:     /test/utils/js/*.mjs
 * - Front real del repo:  /public_html/** (configurable con TK_PUBLIC_DIR)
 * - Back real del repo:   /back/**        (configurable con TK_BACK_DIR)
 *
 * -----------------------------------------------------------------------------
 * 3) Config (env)
 * -----------------------------------------------------------------------------
 * - TK_PUBLIC_DIR (default: "public_html")  -> carpeta real del front
 * - TK_BACK_DIR   (default: "back")         -> carpeta real del back
 * - TEST_IMPORT_DEBUG=1                     -> imprime redirecciones / overrides
 */

import fs from "node:fs";
import { readFile } from "node:fs/promises";
import path from "node:path";
import { fileURLToPath, pathToFileURL } from "node:url";

/* =============================================================================
 * 0) Flags & Directorios configurables
 * ============================================================================= */

const DEBUG = (process.env.TEST_IMPORT_DEBUG || "0") === "1";
const BACK_DIR = process.env.TK_BACK_DIR || "back";
const PUBLIC_DIR = process.env.TK_PUBLIC_DIR || "public_html";

/* =============================================================================
 * 1) Derivación de repoRoot y raíces “reales” / “fake”
 * ============================================================================= */

// Este loader vive en: <repo>/test/utils/js/front_loader.mjs
const __filename = fileURLToPath(import.meta.url);
const __dirname = path.dirname(__filename);
const repoRoot = path.resolve(__dirname, "..", "..", "..");

// Zonas “fake” donde suelen caer imports mal resueltos (tests)
const testFrontRoot = path.join(repoRoot, "test", "front");
const testPublicHtmlRoot = path.join(repoRoot, "test", "public_html");
const rootUtils = path.join(repoRoot, "utils"); // alias histórico: utils/* -> test/utils/*

// Zonas reales (configurables)
const publicRoot = path.join(repoRoot, PUBLIC_DIR);
const backRoot = path.join(repoRoot, BACK_DIR);
const testUtilsRoot = path.join(repoRoot, "test", "utils");

/* =============================================================================
 * 2) Helpers de normalización y clasificación de specifiers
 * ============================================================================= */

const isWin = process.platform === "win32";
const normCmp = (p) => (isWin ? p.toLowerCase() : p);

const testFrontRootCmp = normCmp(testFrontRoot + path.sep);
const testPublicHtmlRootCmp = normCmp(testPublicHtmlRoot + path.sep);
const rootUtilsCmp = normCmp(rootUtils + path.sep);

function isFileUrl(u) {
  return typeof u === "string" && u.startsWith("file:");
}

function isSpecial(specifier) {
  // Builtins / URLs especiales => que lo resuelva Node
  return (
    specifier.startsWith("node:") ||
    specifier.startsWith("data:") ||
    specifier.startsWith("file:") ||
    specifier.startsWith("http:") ||
    specifier.startsWith("https:")
  );
}

function isBare(specifier) {
  // “bare specifier” (react, lodash, etc.) => que lo resuelva Node
  return !specifier.startsWith(".") && !specifier.startsWith("/") && !specifier.startsWith("file:");
}

/* =============================================================================
 * 3) Helpers de resolución a archivo existente
 * ============================================================================= */

function pickExistingTarget(p) {
  // 1) archivo exacto
  if (fs.existsSync(p) && fs.statSync(p).isFile()) return p;

  // 2) si omitió extensión
  if (!path.extname(p)) {
    for (const ext of [".mjs", ".js", ".cjs", ".json"]) {
      const pp = p + ext;
      if (fs.existsSync(pp) && fs.statSync(pp).isFile()) return pp;
    }
  }

  // 3) si es directorio, index.*
  if (fs.existsSync(p) && fs.statSync(p).isDirectory()) {
    for (const idx of ["index.mjs", "index.js", "index.cjs"]) {
      const pp = path.join(p, idx);
      if (fs.existsSync(pp) && fs.statSync(pp).isFile()) return pp;
    }
  }

  return null;
}

/* =============================================================================
 * 4) Aliases estables del TestKit (portable entre repos)
 * ============================================================================= *
 * Permitimos importar desde root con rutas “lógicas”:
 * - public_html/...  -> <repo>/<TK_PUBLIC_DIR>/...
 * - back/...         -> <repo>/<TK_BACK_DIR>/...
 * - test/...         -> <repo>/test/...
 * - utils/...        -> <repo>/test/utils/...   (alias deliberado para helpers)
 */

function mapAliasToReal(specifier) {
  // Permitimos "/public_html/..." y "public_html/..."
  const s = specifier.replace(/^\/+/, "");

  if (s.startsWith("public_html/")) {
    return path.join(publicRoot, s.substring("public_html/".length));
  }
  if (s.startsWith("back/")) {
    return path.join(backRoot, s.substring("back/".length));
  }
  if (s.startsWith("test/")) {
    return path.join(repoRoot, s);
  }

  // Alias deliberado: utils/* -> test/utils/*
  if (s.startsWith("utils/")) {
    return path.join(repoRoot, "test", s);
  }

  return null;
}

/* =============================================================================
 * 5) Redirección de paths “mal resueltos” hacia el árbol real
 * ============================================================================= *
 * Caso típico:
 * - Un import relativo termina apuntando bajo /test/front/... o /test/public_html/...
 * - Nosotros lo redirigimos a /<TK_PUBLIC_DIR>/... manteniendo el path relativo.
 */

function mapMisresolvedPathToReal(candidatePath) {
  const c = normCmp(candidatePath);

  // 1) Si cayó bajo /test/front => map a /<TK_PUBLIC_DIR> manteniendo rel
  if (c.startsWith(testFrontRootCmp)) {
    const rel = path.relative(testFrontRoot, candidatePath);
    return path.join(publicRoot, rel);
  }

  // 2) Si cayó bajo /test/public_html => map a /<TK_PUBLIC_DIR> manteniendo rel
  if (c.startsWith(testPublicHtmlRootCmp)) {
    const rel = path.relative(testPublicHtmlRoot, candidatePath);
    return path.join(publicRoot, rel);
  }

  // 3) Si cayó bajo /utils (no existe en la estructura actual),
  //    lo interpretamos como alias a /test/utils
  if (c.startsWith(rootUtilsCmp)) {
    const rel = path.relative(rootUtils, candidatePath);
    return path.join(testUtilsRoot, rel);
  }

  return null;
}

/* =============================================================================
 * 6) Soporte ESM “sin tocar public_html”
 * ============================================================================= *
 * Node por defecto interpreta *.js como CJS (si no hay type=module).
 * En el front real, muchos archivos dentro de /public_html/.../*.js usan `export`.
 *
 * Estrategia:
 * - Si el archivo está dentro de /<TK_PUBLIC_DIR>/ y termina en .js:
 *   - leemos el source
 *   - si “parece ESM” => lo devolvemos con format="module"
 *   - si no => dejamos el comportamiento default (CJS) para no romper requires.
 */

function isPublicHtmlJsUrl(url) {
  if (!isFileUrl(url)) return false;
  if (!url.endsWith(".js")) return false;

  const p = fileURLToPath(url);
  const rootCmp = normCmp(publicRoot + path.sep);
  return normCmp(p).startsWith(rootCmp);
}

function looksLikeEsm(source) {
  // Heurística intencionalmente simple: suficiente para detectar ESM real.
  // Evita forzar ESM en .js que sean claramente CJS.
  return (
    /\bexport\s+(?:\{|\*|default|function|const|let|var|class)\b/.test(source) ||
    /\bimport\s+[\s\S]*?\s+from\s+["']/.test(source)
  );
}

/* =============================================================================
 * 7) Hook: resolve()
 * ============================================================================= *
 * - Aplica aliases estables primero (public_html/, back/, test/, utils/)
 * - Si falla el resolve normal, intenta redirección desde paths “fake”.
 */

export async function resolve(specifier, context, nextResolve) {
  // builtins / URLs especiales => Node
  if (isSpecial(specifier)) return nextResolve(specifier, context, nextResolve);

  // bare specifiers => Node
  if (isBare(specifier)) return nextResolve(specifier, context, nextResolve);

  // (A) Alias directo (portable)
  const aliasMapped = mapAliasToReal(specifier);
  if (aliasMapped) {
    const target = pickExistingTarget(aliasMapped);
    if (target) {
      if (DEBUG) {
        console.log(
          `[loader] alias\n  spec: ${specifier}\n  to:   ${target.replaceAll("\\\\", "/")}`
        );
      }
      return { url: pathToFileURL(target).href, shortCircuit: true };
    }
    // si no existe, dejamos que Node tire el error estándar
  }

  // (B) Intento normal
  try {
    return await nextResolve(specifier, context, nextResolve);
  } catch (err) {
    // (C) Fallback SOLO si hay parentURL
    if (!context?.parentURL) throw err;

    // Construimos candidato como URL (incluye casos relativos)
    let candidateUrl;
    try {
      candidateUrl = new URL(specifier, context.parentURL).href;
    } catch {
      throw err;
    }

    if (!isFileUrl(candidateUrl)) throw err;

    const candidatePath = fileURLToPath(candidateUrl);

    // Si el candidato cae en zonas “fake”, lo mapeamos al árbol real
    const mapped = mapMisresolvedPathToReal(candidatePath);
    if (!mapped) throw err;

    const target = pickExistingTarget(mapped);
    if (!target) throw err;

    if (DEBUG) {
      console.log(
        `[loader] redirect\n  from: ${candidatePath.replaceAll("\\\\", "/")}\n  to:   ${target.replaceAll("\\\\", "/")}`
      );
    }

    return { url: pathToFileURL(target).href, shortCircuit: true };
  }
}

/* =============================================================================
 * 8) Hook: load()
 * ============================================================================= *
 * - Si el archivo es /<TK_PUBLIC_DIR>/../*.js y “parece ESM”, forzamos format=module.
 * - No aplicamos esto fuera de public_html para evitar efectos colaterales.
 */

export async function load(url, context, nextLoad) {
  if (isPublicHtmlJsUrl(url)) {
    const filename = fileURLToPath(url);
    const source = await readFile(filename, "utf8");

    if (looksLikeEsm(source)) {
      if (DEBUG) {
        console.log(
          `[loader] esm\n  file: ${filename.replaceAll("\\\\", "/")}\n  as:   module`
        );
      }
      return { format: "module", source, shortCircuit: true };
    }
  }

  return nextLoad(url, context);
}