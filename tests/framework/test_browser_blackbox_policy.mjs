#!/usr/bin/env node
import assert from "node:assert/strict";
import fs from "node:fs";
import os from "node:os";
import path from "node:path";

import {
  assertMutationAllowed,
  assertTargetUrl,
  sanitizeText,
  sanitizeUrl,
  writeBrowserArtifact,
} from "../../utils/js/browser/blackbox.mjs";

const observe = {
  baseURL: "https://plc.example.test/webvisu/webvisu.htm",
  targetId: "fixture-plc",
  tlsPolicy: "allow_self_signed_for_explicit_target",
  actionMode: "observe_only",
  artifactsDir: fs.mkdtempSync(path.join(os.tmpdir(), "testkit-browser-policy-")),
};

assert.throws(
  () => assertMutationAllowed(observe, "login"),
  (error) => error?.code === "TESTKIT_BROWSER_OBSERVE_ONLY"
);
assert.equal(
  assertTargetUrl(observe, "/webvisu/login"),
  "https://plc.example.test/webvisu/login"
);
assert.throws(
  () => assertTargetUrl(observe, "https://other.example.test/"),
  /destino bloqueado/
);

const mutating = { ...observe, actionMode: "mutating_ui" };
assert.doesNotThrow(() => assertMutationAllowed(mutating, "login"));

assert.equal(
  sanitizeUrl("https://user:pass@example.test/a?token=abc&view=main"),
  "https://example.test/a?token=%5BREDACTED%5D&view=main"
);
assert.equal(
  sanitizeText("Authorization: Bearer-secret password=hunter2"),
  "Authorization: [REDACTED] password=[REDACTED]"
);

const artifact = writeBrowserArtifact(observe, {
  status: "PASS",
  current_url: sanitizeUrl("https://plc.example.test/?session=secret&view=login"),
  steps: [],
});
const payload = JSON.parse(fs.readFileSync(artifact, "utf8"));
assert.equal(payload.schema, "testkit.browser-run.v1");
assert.equal(payload.tls_policy, "allow_self_signed_for_explicit_target");
assert.equal(payload.action_mode, "observe_only");
assert.match(payload.current_url, /%5BREDACTED%5D/);
assert.ok(!fs.readFileSync(artifact, "utf8").includes("secret"));

fs.rmSync(observe.artifactsDir, { recursive: true, force: true });
console.log("OK browser black-box policy");
