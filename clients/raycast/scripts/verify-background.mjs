import assert from "node:assert/strict";
import { mkdtempSync } from "node:fs";
import { tmpdir } from "node:os";
import { join } from "node:path";
import { performance } from "node:perf_hooks";
import { PostslopClient } from "../src/client.ts";
import { fixtureClient, fixtureEnv } from "./fixture.mjs";
import {
  DraftStore,
  newDraft,
  retryFailed,
  submissionAction,
} from "../src/draft.ts";
import { queueTask, runTask, taskIsActive } from "../src/background.ts";
const client = fixtureClient();
const status = await client.status();
assert.deepEqual(
  Object.fromEntries(
    status.platforms.map((x) => [x.id, x.handle.replace(/^@/, "")]),
  ),
  {
    x: "tester",
    bluesky: "tester.test",
    "dev-null": "tester",
    linkedin: "tester",
  },
);
const store = new DraftStore(
  mkdtempSync(join(tmpdir(), "postslop-background-")),
);
const draft = { ...newDraft(), text: "Background publishing fixture" };
const start = performance.now();
const queued = queueTask(store, draft, "publish");
const enqueueMs = performance.now() - start;
assert(taskIsActive(store.load()));
assert.equal(store.load().text, draft.text);
const taskId = queued.backgroundTask.id;
const [notice, duplicate] = await Promise.all([
  runTask(store, client, taskId),
  runTask(store, client, taskId),
]);
assert.equal(notice.success, true);
assert.equal(duplicate, undefined);
assert.equal(store.load().result.status, "published");
store.openNewPost();
assert.equal(store.load().text, "");
assert.notEqual(store.load().requestId, draft.requestId);
assert.equal(store.savedPosts()[0].result.status, "published");
assert.equal(store.load().backgroundTask, undefined);
assert.equal(await runTask(store, client, taskId), undefined);
const pending = queueTask(
  store,
  { ...newDraft(), text: "Background post while a new draft is composed" },
  "publish",
);
const pendingResult = runTask(store, client, pending.backgroundTask.id);
const next = store.openNewPost();
store.save({ ...next, text: "Keep my new draft untouched" });
assert.equal((await pendingResult).success, true);
assert.equal(store.load().requestId, next.requestId);
assert.equal(store.load().text, "Keep my new draft untouched");
assert.equal(
  store.savedPosts().find((item) => item.requestId === pending.requestId).result
    .status,
  "published",
);
store.openNewPost();
assert.equal(store.load().text, "");
assert(
  store
    .savedPosts()
    .some((item) => item.text === "Keep my new draft untouched"),
);
const failed = queueTask(
  store,
  {
    ...newDraft(["x", "bluesky"]),
    text: "[simulate:rate-limit] background partial failure",
  },
  "publish",
);
assert.equal(
  (await runTask(store, client, failed.backgroundTask.id)).success,
  false,
);
assert.equal(store.load().text, failed.text);
assert.equal(store.load().backgroundTask, undefined);
assert.equal(submissionAction(store.load()), "Retry Failed");
assert.deepEqual(retryFailed(store.load()).platforms, ["x"]);
const unknown = queueTask(
  store,
  {
    ...newDraft(["x"]),
    text: "[simulate:unknown] background uncertain response",
  },
  "publish",
);
assert.equal(
  (await runTask(store, client, unknown.backgroundTask.id)).success,
  false,
);
assert.equal(submissionAction(store.load()), "Check Result");
const check = queueTask(store, store.load(), "check");
assert.equal(
  (await runTask(store, client, check.backgroundTask.id)).success,
  false,
);
assert.equal(store.load().requestId, unknown.requestId);
assert.equal(store.load().backgroundTask, undefined);
const disconnected = new PostslopClient(
  "/nonexistent-postslop-fixture",
  fixtureEnv,
);
const unavailable = queueTask(
  store,
  { ...newDraft(), text: "Retain this draft when unavailable" },
  "publish",
);
assert.equal(
  (await runTask(store, disconnected, unavailable.backgroundTask.id)).success,
  false,
);
assert.equal(store.load().text, unavailable.text);
assert.equal(store.load().requestId, unavailable.requestId);
assert.equal(store.load().backgroundTask, undefined);
assert(store.load().uncertainty);
console.log(
  `PASS: New Post is the default, background completion preserves a newer draft, partial/unknown/network failures retain saved posts, duplicate workers cannot resubmit, and receipt checks do not republish. Local durable enqueue: ${enqueueMs.toFixed(2)} ms.`,
);
