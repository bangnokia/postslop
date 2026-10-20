import assert from 'node:assert/strict';
import { spawn } from 'node:child_process';
import { randomUUID } from 'node:crypto';
import { mkdtemp, readFile, rm, stat, writeFile } from 'node:fs/promises';
import { createServer } from 'node:http';
import { tmpdir } from 'node:os';
import { join } from 'node:path';
import { fileURLToPath } from 'node:url';
import test from 'node:test';
import { runWorker } from '../x-worker.mjs';
import { xFixture } from './fixture.mjs';

process.env.POSTSLOP_TEST_MODE = '1';
const imageBytes = Buffer.from('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+aB1cAAAAASUVORK5CYII=', 'base64');

async function withFixture(options, callback) {
  const directory = await mkdtemp(join(tmpdir(), 'postslop-worker-'));
  const fixture = xFixture(options);
  const server = createServer(fixture.handler);
  await new Promise((resolve) => server.listen(0, '127.0.0.1', resolve));
  const sessionPath = join(directory, 'x.json');
  await writeFile(sessionPath, JSON.stringify({ cookies: ['auth_token', 'ct0'].map((name) => ({
    name, value: 'fixture-only', domain: '.x.com', path: '/', expires: -1, secure: true, httpOnly: true, sameSite: 'Lax',
  })) }), { mode: 0o600 });
  const input = {
    operation: 'publish', requestId: randomUUID(), text: 'A fixture post\nwith a second line',
    images: [{ filename: 'clipboard.png', mimeType: 'image/png', data: imageBytes.toString('base64'), alt: 'A white pixel' }],
    sessionPath, profilePath: join(directory, 'profile'), expectedHandle: 'bangnokia',
    baseUrl: `http://127.0.0.1:${server.address().port}${options.prefix ?? ''}`,
  };
  try { await callback(input, fixture); }
  finally { await new Promise((resolve) => server.close(resolve)); await rm(directory, { recursive: true, force: true }); }
}

test('publishes text, clipboard image bytes and alt text through the browser exactly once', async () => {
  await withFixture({ prefix: '/x-web' }, async (input, fixture) => {
    const status = await runWorker({ ...input, operation: 'status' });
    assert.deepEqual(status, { id: 'x', label: 'X', available: true, handle: '@bangnokia' });
    const result = await runWorker(input);
    assert.deepEqual(result, { platform: 'x', status: 'published', url: 'https://x.com/bangnokia/status/100001' });
    assert.equal(fixture.posts.length, 1);
    assert.equal(fixture.posts[0].text, input.text);
    assert.equal(fixture.posts[0].images[0].alt, 'A white pixel');
    assert.deepEqual(fixture.posts[0].images[0].data, [...imageBytes]);
    assert.deepEqual(await runWorker(input), result);
    assert.equal(fixture.posts.length, 1);
    assert.equal((await stat(input.profilePath)).mode & 0o777, 0o700);
    assert.equal((await stat(join(input.profilePath, 'postslop-session.json'))).mode & 0o777, 0o600);
    const journal = JSON.parse(await readFile(join(input.profilePath, 'postslop-requests', `${input.requestId}.json`), 'utf8'));
    assert.equal(journal.submitted, true);
  });
});

test('prepareOnly fills the composer but cannot click Post or create a submission journal', async () => {
  await withFixture({}, async (input, fixture) => {
    assert.equal((await runWorker({ ...input, prepareOnly: true })).status, 'prepared');
    assert.equal(fixture.posts.length, 0);
    await assert.rejects(stat(join(input.profilePath, 'postslop-requests')), { code: 'ENOENT' });
    assert.equal((await stat(join(input.profilePath, 'last-prepared.png'))).mode & 0o777, 0o600);
  });
});

test('CLI preserves an emoji split between UTF-8 stdin chunks', async () => {
  await withFixture({}, async (input, fixture) => {
    const text = 'Clipboard photo 🦀 with UTF-8';
    const payload = Buffer.from(JSON.stringify({ ...input, text }));
    const split = payload.indexOf(Buffer.from('🦀')) + 2;
    const child = spawn(process.execPath, [fileURLToPath(new URL('../x-worker.mjs', import.meta.url))], {
      env: { ...process.env, POSTSLOP_TEST_MODE: '1' }, stdio: ['pipe', 'pipe', 'pipe'],
    });
    let stdout = '';
    child.stdout.setEncoding('utf8');
    child.stdout.on('data', (chunk) => { stdout += chunk; });
    child.stderr.resume();
    const completed = new Promise((resolve, reject) => {
      const deadline = setTimeout(() => { child.kill('SIGKILL'); reject(new Error('Fixture worker deadline exceeded.')); }, 30000);
      child.on('error', (error) => { clearTimeout(deadline); reject(error); });
      child.on('exit', (code) => { clearTimeout(deadline); resolve(code); });
    });
    child.stdin.write(payload.subarray(0, split));
    await new Promise((resolve) => setTimeout(resolve, 30));
    child.stdin.end(payload.subarray(split));
    assert.equal(await completed, 0);
    assert.equal(JSON.parse(stdout).status, 'published');
    assert.equal(fixture.posts[0].text, text);
  });
});

test('composer errors produce only fixed private diagnostics and no submission', async () => {
  await withFixture({ failure: 'ambiguous-composer' }, async (input, fixture) => {
    const result = await runWorker({ ...input, prepareOnly: true });
    assert.equal(result.code, 'composer_unavailable');
    assert.equal(fixture.posts.length, 0);
    const diagnostic = JSON.parse(await readFile(join(input.profilePath, 'last-error.json'), 'utf8'));
    assert.equal(diagnostic.stage, 'compose');
    assert.equal(diagnostic.submitted, false);
    assert.equal(diagnostic.screenshotSaved, true);
    assert.deepEqual(Object.keys(diagnostic).sort(), ['at', 'code', 'controls', 'errorName', 'screenshotSaved', 'stage', 'submitted']);
    assert.equal((await stat(join(input.profilePath, 'last-error.png'))).mode & 0o777, 0o600);
  });
});

test('zero-header response loss sends once and a later app retry stays blocked', async () => {
  await withFixture({ failure: 'lost-response' }, async (input, fixture) => {
    const result = await runWorker(input);
    assert.equal(result.status, 'unknown');
    assert.equal(fixture.posts.length, 1);
    assert.equal((await runWorker(input)).status, 'unknown');
    assert.equal(fixture.posts.length, 1);
  });
});

test('a partially received response remains unknown without another submission', async () => {
  await withFixture({ failure: 'partial-response' }, async (input, fixture) => {
    assert.equal((await runWorker(input)).status, 'unknown');
    assert.equal((await runWorker(input)).status, 'unknown');
    assert.equal(fixture.posts.length, 1);
  });
});

test('an account mismatch prevents publishing', async () => {
  await withFixture({ handle: 'someone_else' }, async (input, fixture) => {
    const result = await runWorker(input);
    assert.equal(result.status, 'failed');
    assert.equal(result.code, 'account_mismatch');
    assert.equal(fixture.posts.length, 0);
  });
});

test('a verification overlay prevents connected status and any publish click', async () => {
  await withFixture({ failure: 'challenge' }, async (input, fixture) => {
    const status = await runWorker({ ...input, operation: 'status' });
    assert.equal(status.available, false);
    assert.equal(status.code, 'challenge');
    const result = await runWorker(input);
    assert.equal(result.status, 'failed');
    assert.equal(result.code, 'challenge');
    assert.equal(fixture.posts.length, 0);
  });
});

test('a website rejection is reported without retrying', async () => {
  await withFixture({ failure: 'rejected' }, async (input, fixture) => {
    const result = await runWorker(input);
    assert.equal(result.status, 'failed');
    assert.equal(result.code, 'automation_rejected');
    assert.match(result.error, /226/);
    assert.equal((await runWorker(input)).status, 'failed');
    assert.equal(fixture.posts.length, 1);
  });
});

test('server errors after submission are uncertain and cannot be retried with the same ID', async () => {
  await withFixture({ failure: 'server-error' }, async (input, fixture) => {
    assert.equal((await runWorker(input)).status, 'unknown');
    assert.equal((await runWorker(input)).status, 'unknown');
    assert.equal(fixture.posts.length, 1);
  });
});

test('duplicate content remains unknown because an earlier network attempt may have posted', async () => {
  await withFixture({ failure: 'duplicate' }, async (input, fixture) => {
    assert.equal((await runWorker(input)).status, 'unknown');
    assert.equal((await runWorker(input)).status, 'unknown');
    assert.equal(fixture.posts.length, 1);
  });
});

test('a second website-level CreateTweet request is blocked', async () => {
  await withFixture({ failure: 'application-retry' }, async (input, fixture) => {
    assert.equal((await runWorker(input)).status, 'failed');
    assert.equal(fixture.posts.length, 1);
  });
});

for (const failure of ['mismatched-text', 'mismatched-media']) {
  test(`${failure} is blocked before any CreateTweet request reaches the website`, async () => {
    await withFixture({ failure }, async (input, fixture) => {
      const result = await runWorker(input);
      assert.equal(result.status, 'failed');
      assert.equal(result.code, 'composer_unavailable');
      assert.equal(fixture.posts.length, 0);
    });
  });
}

test('invalid image boundaries fail before a browser submission', async () => {
  await withFixture({}, async (input, fixture) => {
    const result = await runWorker({ ...input, images: [{ ...input.images[0], data: Buffer.alloc(1024 * 1024 + 1).toString('base64') }] });
    assert.equal(result.status, 'failed');
    assert.equal(fixture.posts.length, 0);
  });
});
