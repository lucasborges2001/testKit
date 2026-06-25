const PHP_NOISE = [
  /PHP (Warning|Fatal error|Parse error|Notice|Deprecated)/i,
  /Stack trace:/i,
  /Uncaught (Throwable|Exception|Error)/i,
];

export function assertNoPhpNoise(text, label = "documento") {
  const value = String(text || "");
  for (const pattern of PHP_NOISE) {
    if (pattern.test(value)) {
      throw new Error(`${label} contiene ruido PHP visible: ${pattern}`);
    }
  }
}

export function attachBrowserAudit(page) {
  const consoleErrors = [];
  const httpErrors = [];

  page.on("console", (msg) => {
    if (msg.type() === "error") {
      consoleErrors.push(msg.text());
    }
  });

  page.on("response", (response) => {
    if (response.status() >= 500) {
      httpErrors.push(`${response.status()} ${response.url()}`);
    }
  });

  return {
    consoleErrors,
    httpErrors,
    assertClean() {
      if (consoleErrors.length > 0) {
        throw new Error(`Errores de consola detectados:\n${consoleErrors.join("\n")}`);
      }
      if (httpErrors.length > 0) {
        throw new Error(`Responses HTTP >= 500 detectadas:\n${httpErrors.join("\n")}`);
      }
    },
  };
}

export async function assertVisible(locator, message) {
  if (!(await locator.first().isVisible().catch(() => false))) {
    throw new Error(message);
  }
}
