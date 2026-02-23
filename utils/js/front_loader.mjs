/**
 * test/utils/js/front_loader.mjs
 *
 * Loader ESM para tests de frontend (Node).
 *
 * Problemas que corrige:
 * - Imports que hoy te quedan mal resueltos como:
 *     /var/www/html/test/public_html/...   (no existe)
 *     /var/www/html/test/front/...         (no existe)
 *     /var/www/html/utils/...              (no existe)
 *
 * Objetivo:
 * - Soportar aliases “desde root”:
 *     - public_html/...  -> <repo>/public_html/...
 *     - back/...         -> <repo>/back/...
 *     - test/...         -> <repo>/test/...
 *     - utils/...        -> <repo>/test/utils/...   (alias deliberado para helpers de tests)
 *
 * - Y además: si Node intenta resolver dentro de test/front o test/public_html,
 *   lo redirigimos a public_html.
 *
 * Debug:
 *   TEST_IMPORT_DEBUG=1 imprime redirecciones.
 */

import fs from "node:fs";
import path from "node:path";
import { fileURLToPath, pathToFileURL } from "node:url";

const DEBUG = (process.env.TEST_IMPORT_DEBUG || "0") === "1";

// Este loader vive en: <repo>/test/utils/js/front_loader.mjs
const __filename = fileURLToPath(import.meta.url);
const __dirname = path.dirname(__filename);
const repoRoot = path.resolve(__dirname, "..", "..", "..");

// Zonas “fake” donde suelen caer imports mal resueltos
const testFrontRoot = path.join(repoRoot, "test", "front");
const testPublicHtmlRoot = path.join(repoRoot, "test", "public_html");
const rootUtils = path.join(repoRoot, "utils");

// Zonas reales
const publicRoot = path.join(repoRoot, "public_html");
const testUtilsRoot = path.join(repoRoot, "test", "utils");

const isWin = process.platform === "win32";
const normCmp = (p) => (isWin ? p.toLowerCase() : p);

const testFrontRootCmp = normCmp(testFrontRoot + path.sep);
const testPublicHtmlRootCmp = normCmp(testPublicHtmlRoot + path.sep);
const rootUtilsCmp = normCmp(rootUtils + path.sep);

function isFileUrl(u) {
  return typeof u === "string" && u.startsWith("file:");
}

function isSpecial(specifier) {
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
  return (
    !specifier.startsWith(".") &&
    !specifier.startsWith("/") &&
    !specifier.startsWith("file:")
  );
}

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

function mapAliasToReal(specifier) {
  // Permitimos "/public_html/..." y "public_html/..."
  const s = specifier.replace(/^\/+/, "");

  if (s.startsWith("public_html/") || s.startsWith("back/") || s.startsWith("test/")) {
    return path.join(repoRoot, s);
  }

  // Alias deliberado: utils/* -> test/utils/*
  if (s.startsWith("utils/")) {
    return path.join(repoRoot, "test", s);
  }

  return null;
}

function mapMisresolvedPathToReal(candidatePath) {
  const c = normCmp(candidatePath);

  // 1) Si cayó bajo test/front => map a public_html manteniendo rel
  if (c.startsWith(testFrontRootCmp)) {
    const rel = path.relative(testFrontRoot, candidatePath);
    return path.join(publicRoot, rel);
  }

  // 2) Si cayó bajo test/public_html => map a public_html manteniendo rel
  if (c.startsWith(testPublicHtmlRootCmp)) {
    const rel = path.relative(testPublicHtmlRoot, candidatePath);
    return path.join(publicRoot, rel);
  }

  // 3) Si cayó bajo <repo>/utils (que NO existe en tu estructura actual),
  //    lo interpretamos como alias a <repo>/test/utils
  if (c.startsWith(rootUtilsCmp)) {
    const rel = path.relative(rootUtils, candidatePath);
    return path.join(testUtilsRoot, rel);
  }

  return null;
}

export async function resolve(specifier, context, nextResolve) {
  // builtins / URLs especiales => Node
  if (isSpecial(specifier)) return nextResolve(specifier, context, nextResolve);

  // bare specifiers => Node
  if (isBare(specifier)) return nextResolve(specifier, context, nextResolve);

  // 0) Si matchea alias directo (public_html/, utils/, etc.), resolvemos nosotros primero
  const aliasMapped = mapAliasToReal(specifier);
  if (aliasMapped) {
    const target = pickExistingTarget(aliasMapped);
    if (target) {
      if (DEBUG) {
        console.log(
          `[loader] alias\n  spec: ${specifier}\n  to:   ${target.replaceAll("\\", "/")}`
        );
      }
      return { url: pathToFileURL(target).href, shortCircuit: true };
    }
    // si no existe, dejamos que Node tire el error estándar (más claro para debugging)
  }

  // 1) Intento normal
  try {
    return await nextResolve(specifier, context, nextResolve);
  } catch (err) {
    // 2) Fallback SOLO si hay parentURL
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

    // 3) Si el candidato cae en zonas “fake”, lo mapeamos al árbol real
    const mapped = mapMisresolvedPathToReal(candidatePath);
    if (!mapped) throw err;

    const target = pickExistingTarget(mapped);
    if (!target) throw err;

    if (DEBUG) {
      console.log(
        `[loader] redirect\n  from: ${candidatePath.replaceAll("\\", "/")}\n  to:   ${target.replaceAll("\\", "/")}`
      );
    }

    return { url: pathToFileURL(target).href, shortCircuit: true };
  }
}