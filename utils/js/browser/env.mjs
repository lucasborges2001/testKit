export function env(name, fallback = "") {
  const value = process.env[name];
  if (value === undefined || String(value).trim() === "") {
    return fallback;
  }
  return String(value).trim();
}

export function requiredEnv(name) {
  const value = env(name);
  if (value === "") {
    throw new Error(`Falta configuracion requerida: ${name}`);
  }
  return value;
}

export function boolEnv(name, fallback = false) {
  const value = env(name);
  if (value === "") {
    return fallback;
  }
  const normalized = value.toLowerCase();
  if (["1", "true", "yes", "on"].includes(normalized)) {
    return true;
  }
  if (["0", "false", "no", "off"].includes(normalized)) {
    return false;
  }
  throw new Error(`${name} invalido: ${value}. Valores: 1|0|true|false|yes|no|on|off`);
}

export function csvEnv(name, fallback = "") {
  return env(name, fallback)
    .split(",")
    .map((item) => item.trim())
    .filter(Boolean);
}

export function browserConfig() {
  return {
    baseURL: requiredEnv("TESTKIT_BROWSER_BASE_URL"),
    headless: boolEnv("TESTKIT_BROWSER_HEADLESS", true),
    trace: env("TESTKIT_BROWSER_TRACE", "retain-on-failure"),
    screenshot: env("TESTKIT_BROWSER_SCREENSHOT", "only-on-failure"),
    video: env("TESTKIT_BROWSER_VIDEO", "off"),
    timeoutMs: Number.parseInt(env("TESTKIT_BROWSER_TIMEOUT_MS", "30000"), 10) || 30000,
    artifactsDir: env("TESTKIT_BROWSER_ARTIFACTS_DIR", "/tmp/testkit-browser-e2e"),
  };
}

export function resolveUrl(baseURL, pathOrUrl) {
  if (/^https?:\/\//i.test(pathOrUrl)) {
    return pathOrUrl;
  }
  return new URL(pathOrUrl, baseURL).toString();
}
