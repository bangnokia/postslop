import assert from "node:assert/strict";
import { resolve } from "node:path";
import { PostslopClient } from "../src/client.ts";

// The real Postslop command line, on isolated storage, against tests/fakes in the server.
export const server = resolve(import.meta.dirname, "../../../server");
const fakes = process.env.POSTSLOP_SIMULATION_URL || "http://127.0.0.1:4900";
const url = new URL(fakes);
assert.equal(url.hostname, "127.0.0.1", "Only local fake sites are allowed");
const storage = `${server}/storage`;

export const fixtureEnv = {
  DB_DSN: `sqlite:${storage}/test-raycast-database.sqlite`,
  CACHE_STORE: "array",
  POSTSLOP_SESSIONS_PATH: `${storage}/test-sessions`,
  POSTSLOP_POSTS_PATH: `${storage}/test-raycast-posts`,
  POSTSLOP_MEDIA_PATH: `${storage}/test-raycast-media`,
  POSTSLOP_X_TRANSPORT: "http",
  POSTSLOP_X_URL: `${fakes}/x`,
  POSTSLOP_X_UPLOAD_URL: `${fakes}/x`,
  POSTSLOP_X_BUNDLE_URL: `${fakes}/x/main.js`,
  POSTSLOP_BLUESKY_URL: `${fakes}/bluesky`,
  POSTSLOP_DEV_NULL_URL: `${fakes}/dev-null`,
};
export const fakeSites = fakes;
export const fixtureClient = () => new PostslopClient(server, fixtureEnv);
