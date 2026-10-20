import assert from "node:assert/strict";
import {
  mkdtempSync,
  readFileSync,
  statSync,
  writeFileSync,
  existsSync,
} from "node:fs";
import { tmpdir } from "node:os";
import { join, resolve } from "node:path";
import { PostslopClient } from "../src/client.ts";
import {
  DraftStore,
  newDraft,
  retryFailed,
  submissionAction,
  validateResult,
  fullyPublished,
  cleanPostURL,
} from "../src/draft.ts";
import { importImage } from "../src/images.ts";

const project = resolve(import.meta.dirname, "../..");
const baseURL = process.env.POSTSLOP_TEST_URL || "http://127.0.0.1:8091";
const simulation =
  process.env.POSTSLOP_SIMULATION_URL || "http://127.0.0.1:4900";
for (const value of [baseURL, simulation]) {
  const url = new URL(value);
  assert.equal(
    url.hostname,
    "127.0.0.1",
    "Only isolated loopback fixtures are allowed",
  );
  assert.notEqual(url.port, "8087", "Never use the production Postslop server");
}
const client = new PostslopClient(
  project,
  baseURL,
  join(project, "storage/test-client-token"),
);
const status = await client.status();
assert(status.publishingEnabled);
assert(status.platforms.every((item) => item.available));
assert.deepEqual(
  Object.fromEntries(
    status.platforms.map((item) => [item.id, item.handle.replace(/^@/, "")]),
  ),
  { x: "tester", bluesky: "tester.test", "dev-null": "tester" },
  "Refusing to send fixture posts to real accounts",
);

const support = mkdtempSync(join(tmpdir(), "postslop-raycast-"));
const store = new DraftStore(support);
const source = join(support, "source.png");
writeFileSync(
  source,
  Buffer.from(
    "iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAusB9Wl2nWQAAAAASUVORK5CYII=",
    "base64",
  ),
  { mode: 0o600 },
);
const image = await importImage(
  join(project, "raycast/assets/image-helper"),
  support,
  source,
);
image.alt = "Raycast integration fixture";
assert(image.bytes <= 1048576);
assert.equal(statSync(image.path).mode & 0o777, 0o600);

async function counts() {
  return Object.fromEntries(
    await Promise.all(
      ["x", "bluesky", "dev-null"].map(async (id) => {
        const response = await fetch(`${simulation}/${id}/__stats`);
        assert(response.ok);
        return [id, (await response.json()).posts.length];
      }),
    ),
  );
}
const before = await counts();
const draft = {
  ...newDraft(),
  text: "Raycast local integration: image and alt text",
  images: [image],
  submitted: true,
};
store.save(draft);
assert.equal(store.load().requestId, draft.requestId);
assert.equal(statSync(store.path).mode & 0o777, 0o600);
assert.equal(statSync(support).mode & 0o777, 0o700);
const result = await client.publish(draft);
validateResult(draft, result);
assert(fullyPublished(result));
const after = await counts();
for (const id of draft.platforms) assert.equal(after[id], before[id] + 1);
assert.deepEqual(await client.publish(store.load()), result);
assert.deepEqual(await client.check(draft.requestId), result);
assert.deepEqual(
  await counts(),
  after,
  "Repeated request IDs and recovery cannot duplicate posts",
);

const failed = {
  ...newDraft(["x", "bluesky"]),
  text: "[simulate:rate-limit] Raycast keeps successful destinations out of retries",
  submitted: true,
};
const partial = await client.publish(failed);
validateResult(failed, partial);
assert.equal(partial.status, "partial");
store.save({ ...failed, result: partial });
const retry = retryFailed(store.load());
assert.deepEqual(retry.platforms, ["x"]);
assert.notEqual(retry.requestId, failed.requestId);
assert.deepEqual(retry.previouslyPublished, ["bluesky"]);
const beforeRetry = await counts();
await client.publish(retry);
assert.deepEqual(
  await counts(),
  beforeRetry,
  "A failed-only retry must not resend to Bluesky",
);

const uncertain = {
  ...newDraft(["x"]),
  text: "[simulate:unknown] Raycast preserves receipt recovery",
  submitted: true,
};
const unknown = await client.publish(uncertain);
validateResult(uncertain, unknown);
assert.equal(unknown.status, "unknown");
store.save({ ...uncertain, result: unknown });
const restored = store.load();
assert.equal(submissionAction(restored), "Check Result");
assert.throws(() => retryFailed(restored));
const beforeRecovery = await counts();
assert.deepEqual(await client.check(restored.requestId), unknown);
assert.deepEqual(await client.publish(restored), unknown);
assert.deepEqual(await counts(), beforeRecovery);
assert.throws(() =>
  validateResult(draft, { ...result, requestId: uncertain.requestId }),
);
assert.throws(() =>
  validateResult(draft, { ...result, results: result.results.slice(1) }),
);

store.savePlatforms(["bluesky"]);
const cleared = newDraft(store.preferredPlatforms());
store.save(cleared);
store.removeImages([image, { ...image, path: source }]);
assert.equal(store.load().text, "");
assert.deepEqual(store.load().images, []);
assert.deepEqual(store.load().platforms, ["bluesky"]);
assert.notEqual(store.load().requestId, draft.requestId);
assert(!existsSync(image.path));
assert(existsSync(source), "Source images must never be deleted");
assert.equal(
  cleanPostURL("https://x.com/test/status/1?utm_source=fixture&x=1"),
  "https://x.com/test/status/1?x=1",
);
assert.equal(cleanPostURL("javascript:alert(1)"), undefined);
writeFileSync(store.path, "{broken draft", { mode: 0o600 });
assert.throws(() => store.load(), /preserved/);
assert.equal(readFileSync(store.path, "utf8"), "{broken draft");
console.log(
  "PASS: actual Raycast client and image helper against isolated Postslop + Postman upstreams; image publish, duplicate suppression, failed-only retries, uncertain recovery, draft persistence, private permissions, and safe image cleanup.",
);
