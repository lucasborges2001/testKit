import fs from "node:fs";
import path from "node:path";

const SENSITIVE_QUERY_KEYS = /(?:pass(?:word)?|token|secret|authorization|auth|cookie|session|key|credential)/i;
const SENSITIVE_TEXT = [
  /(authorization\s*[:=]\s*)([^\s,;]+)/gi,
  /((?:pass(?:word)?|token|secret|cookie|session|credential)\s*[:=]\s*)([^\s,;]+)/gi,
];

export function sanitizeText(value) {
  let sanitized = String(value ?? "");
  for (const pattern of SENSITIVE_TEXT) {
    sanitized = sanitized.replace(pattern, "$1[REDACTED]");
  }
  return sanitized;
}

export function sanitizeUrl(value) {
  try {
    const url = new URL(String(value));
    url.username = "";
    url.password = "";
    for (const key of [...url.searchParams.keys()]) {
      if (SENSITIVE_QUERY_KEYS.test(key)) {
        url.searchParams.set(key, "[REDACTED]");
      }
    }
    return url.toString();
  } catch {
    return sanitizeText(value);
  }
}

export function targetOrigin(baseURL) {
  return new URL(baseURL).origin;
}

export function allowsInvalidTls(config) {
  return config.tlsPolicy === "allow_self_signed_for_explicit_target";
}

export function artifactReference(config, artifactPath) {
  const root = path.resolve(config.artifactsDir);
  const absolute = path.resolve(artifactPath);
  const relative = path.relative(root, absolute);
  if (relative === "" || relative === ".." || relative.startsWith(`..${path.sep}`) || path.isAbsolute(relative)) {
    throw new Error(`Artifact fuera del directorio permitido: ${absolute}`);
  }
  return relative.split(path.sep).join("/");
}

export function assertTargetUrl(config, pathOrUrl) {
  const candidate = new URL(String(pathOrUrl), config.baseURL);
  if (allowsInvalidTls(config) && candidate.origin !== targetOrigin(config.baseURL)) {
    throw new Error(
      `TLS self-signed limitado al target explicito ${targetOrigin(config.baseURL)}; destino bloqueado: ${candidate.origin}`
    );
  }
  return candidate.toString();
}

export function assertMutationAllowed(config, label = "accion mutante") {
  if (config.actionMode !== "mutating_ui") {
    const error = new Error(`TESTKIT_BROWSER_OBSERVE_ONLY bloquea ${label}`);
    error.code = "TESTKIT_BROWSER_OBSERVE_ONLY";
    throw error;
  }
}

function slug(value) {
  return String(value || "step")
    .toLowerCase()
    .replace(/[^a-z0-9]+/g, "-")
    .replace(/^-+|-+$/g, "")
    .slice(0, 64) || "step";
}

export function createScopedApiRequest(apiRequest, config) {
  if (!allowsInvalidTls(config)) {
    return apiRequest;
  }

  const wrapped = {};
  for (const method of ["get", "post", "put", "patch", "delete", "head", "fetch"]) {
    wrapped[method] = (url, ...args) => apiRequest[method](assertTargetUrl(config, url), ...args);
  }
  for (const method of ["storageState", "dispose"]) {
    if (typeof apiRequest[method] === "function") {
      wrapped[method] = apiRequest[method].bind(apiRequest);
    }
  }
  return wrapped;
}

export function createBlackBoxRuntime({ page, config }) {
  const steps = [];
  let sensitiveFailure = false;

  return {
    steps,
    get sensitiveFailure() {
      return sensitiveFailure;
    },
    assertMutationAllowed(label) {
      assertMutationAllowed(config, label);
    },
    assertTargetUrl(pathOrUrl) {
      return assertTargetUrl(config, pathOrUrl);
    },
    async step(name, options = {}, run) {
      if (typeof run !== "function") {
        throw new Error(`Browser step ${name} requiere callback.`);
      }
      const mutating = options.mutating === true;
      const sensitive = options.sensitive === true;
      if (mutating) {
        assertMutationAllowed(config, name);
      }

      const started = Date.now();
      const row = {
        name: sanitizeText(name),
        mutating,
        sensitive,
        status: "RUNNING",
        duration_ms: 0,
        screenshot: null,
      };
      steps.push(row);

      try {
        const result = await run({ page });
        row.status = "PASS";
        if (options.screenshot === true && !sensitive) {
          const screenshot = path.join(config.artifactsDir, `step-${String(steps.length).padStart(2, "0")}-${slug(name)}.png`);
          await page.screenshot({ path: screenshot, fullPage: true });
          row.screenshot = artifactReference(config, screenshot);
        }
        return result;
      } catch (error) {
        row.status = "FAIL";
        if (sensitive) {
          sensitiveFailure = true;
        }
        throw error;
      } finally {
        row.duration_ms = Date.now() - started;
      }
    },
  };
}

export function writeBrowserArtifact(config, payload) {
  fs.mkdirSync(config.artifactsDir, { recursive: true });
  const artifactPath = path.join(config.artifactsDir, "browser-run.json");
  const safe = {
    schema: "testkit.browser-run.v1",
    target_id: sanitizeText(config.targetId),
    target_origin: targetOrigin(config.baseURL),
    tls_policy: config.tlsPolicy,
    action_mode: config.actionMode,
    ...payload,
  };
  fs.writeFileSync(artifactPath, `${JSON.stringify(safe, null, 2)}\n`, { mode: 0o600 });
  return artifactPath;
}
