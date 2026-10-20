import { firefox } from 'playwright';
import { createHash } from 'node:crypto';
import { chmod, mkdir, open, readFile, rename, rm, stat, writeFile } from 'node:fs/promises';
import { dirname, isAbsolute, join, resolve } from 'node:path';
import { fileURLToPath } from 'node:url';

process.umask(0o077);

const appRoot = resolve(dirname(fileURLToPath(import.meta.url)), '..');
const maximumImageBytes = 1024 * 1024;
const maximumInputBytes = 6 * 1024 * 1024;
const allowedImageTypes = new Set(['image/png', 'image/jpeg', 'image/webp']);

class WorkerError extends Error {
  constructor(message, code = 'unavailable') {
    super(message);
    this.name = 'WorkerError';
    this.code = code;
  }
}

async function lockProfile(profilePath) {
  const path = join(profilePath, 'postslop.lock');
  try {
    const handle = await open(path, 'wx', 0o600);
    await handle.writeFile(String(process.pid));
    await handle.close();
    return () => rm(path, { force: true });
  } catch (error) {
    if (error.code !== 'EEXIST') throw error;
    const pid = Number(await readFile(path, 'utf8'));
    let alive = true;
    try { if (Number.isInteger(pid) && pid > 0) process.kill(pid, 0); }
    catch (error) { if (error.code === 'ESRCH') alive = false; }
    if (!alive || ((!Number.isInteger(pid) || pid < 1) && Date.now() - (await stat(path)).mtimeMs > 90000)) {
      await rm(path);
      return lockProfile(profilePath);
    }
    throw new WorkerError('Postslop is already checking or publishing to X. Wait for that operation to finish.', 'busy');
  }
}

async function readJson(path) {
  try {
    return JSON.parse(await readFile(path, 'utf8'));
  } catch (error) {
    if (error.code === 'ENOENT') return null;
    throw new WorkerError('Postslop could not read its private X session state.');
  }
}

async function privateJson(path, value) {
  await mkdir(dirname(path), { recursive: true, mode: 0o700 });
  const temporary = `${path}.${process.pid}.tmp`;
  await writeFile(temporary, JSON.stringify(value), { mode: 0o600 });
  await rename(temporary, path);
  await chmod(path, 0o600);
}

function validateInput(input) {
  if (!input || !['status', 'publish'].includes(input.operation)) {
    throw new WorkerError('Choose a valid X worker operation.');
  }
  if (input.prepareOnly !== undefined && typeof input.prepareOnly !== 'boolean') {
    throw new WorkerError('The X preparation option must be a boolean.');
  }
  if (!isAbsolute(input.sessionPath ?? '') || !isAbsolute(input.profilePath ?? '')) {
    throw new WorkerError('The X worker requires absolute private session and profile paths.');
  }
  const testing = process.env.POSTSLOP_TEST_MODE === '1';
  if (!testing && (resolve(input.profilePath) !== join(appRoot, 'storage/browser/x')
    || resolve(input.sessionPath) !== join(appRoot, 'storage/sessions/x.json'))) {
    throw new WorkerError('The X worker only uses Postslop’s private X session and browser profile.');
  }
  if (!/^[A-Za-z0-9_]{1,15}$/.test(input.expectedHandle ?? '')) {
    throw new WorkerError('The expected X account is missing.');
  }
  let baseUrl = 'https://x.com';
  if (input.baseUrl !== undefined && input.baseUrl !== baseUrl) {
    const candidate = new URL(input.baseUrl);
    if (!testing || candidate.protocol !== 'http:' || !['127.0.0.1', 'localhost', '[::1]'].includes(candidate.hostname)) {
      throw new WorkerError('A custom X origin is only allowed for local worker tests.');
    }
    if (candidate.search || candidate.hash || candidate.username || candidate.password) {
      throw new WorkerError('The local X test origin must not contain credentials, a query, or a fragment.');
    }
    baseUrl = `${candidate.origin}${candidate.pathname.replace(/\/$/, '')}`;
  }
  const images = input.images ?? [];
  if (!Array.isArray(images) || images.length > 4) throw new WorkerError('X supports up to four attached images.');
  const text = input.text ?? '';
  if (typeof text !== 'string' || text.length > 25000) throw new WorkerError('The X post text is invalid.');
  if (input.operation === 'publish') {
    if (!/^[0-9a-f]{8}-[0-9a-f]{4}-[1-8][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i.test(input.requestId ?? '')) {
      throw new WorkerError('An X publish request requires a unique request ID.');
    }
    if (!text.trim() && images.length === 0) throw new WorkerError('Write a post or attach an image first.');
  }
  const attachments = images.map((image) => {
    if (!image || typeof image.filename !== 'string' || image.filename.length < 1 || image.filename.length > 200
      || /[\0\/\\]/.test(image.filename) || !allowedImageTypes.has(image.mimeType)
      || typeof image.data !== 'string' || !/^(?:[A-Za-z0-9+/]{4})*(?:[A-Za-z0-9+/]{2}==|[A-Za-z0-9+/]{3}=)?$/.test(image.data)
      || typeof (image.alt ?? '') !== 'string' || (image.alt ?? '').length > 1000) {
      throw new WorkerError('An attached X image or its alt text is invalid.');
    }
    const buffer = Buffer.from(image.data, 'base64');
    if (buffer.length === 0 || buffer.length > maximumImageBytes) throw new WorkerError('Each X image must be between 1 byte and 1 MiB.');
    const signatureMatches = image.mimeType === 'image/png'
      ? buffer.subarray(0, 8).equals(Buffer.from([137, 80, 78, 71, 13, 10, 26, 10]))
      : image.mimeType === 'image/jpeg'
        ? buffer.subarray(0, 3).equals(Buffer.from([255, 216, 255]))
        : buffer.toString('ascii', 0, 4) === 'RIFF' && buffer.toString('ascii', 8, 12) === 'WEBP';
    if (!signatureMatches) throw new WorkerError('An attached X image does not match its declared file type.');
    return { name: image.filename, mimeType: image.mimeType, buffer, alt: image.alt ?? '' };
  });
  return { ...input, baseUrl, text, attachments };
}

async function importSession(context, input) {
  const state = await readJson(input.sessionPath);
  if (!Array.isArray(state?.cookies)) throw new WorkerError('Import your signed-in X session into Postslop first.', 'session_missing');
  const cookies = state.cookies.filter((cookie) => cookie && ['x.com', '.x.com'].includes(cookie.domain)
    && typeof cookie.name === 'string' && typeof cookie.value === 'string'
    && (cookie.expires === -1 || (Number.isFinite(cookie.expires) && cookie.expires > Date.now() / 1000)))
    .map(({ name, value, domain, path, expires, httpOnly, secure, sameSite }) => ({
      name, value, domain, path: typeof path === 'string' ? path : '/', expires,
      httpOnly: Boolean(httpOnly), secure: Boolean(secure),
      sameSite: ['Strict', 'Lax', 'None'].includes(sameSite) ? sameSite : 'Lax',
    }));
  if (!['auth_token', 'ct0'].every((name) => cookies.some((cookie) => cookie.name === name && cookie.value))) {
    throw new WorkerError('The imported X session is missing valid authentication cookies. Refresh it in Postslop.', 'session_missing');
  }
  const fingerprint = createHash('sha256').update(JSON.stringify(cookies
    .filter((cookie) => ['auth_token', 'ct0'].includes(cookie.name))
    .map(({ name, value, domain }) => [name, value, domain]).sort())).digest('hex');
  const metadataPath = join(input.profilePath, 'postslop-session.json');
  const previous = await readJson(metadataPath);
  if (previous?.sourceAuthentication !== fingerprint || previous?.browserEngine !== 'firefox') {
    await context.clearCookies({ domain: /(^|\.)x\.com$/ });
    await context.addCookies(cookies);
    await privateJson(metadataPath, { sourceAuthentication: fingerprint, browserEngine: 'firefox' });
  }
}

async function signedInAccount(page, input) {
  await page.goto(`${input.baseUrl}/home`, { waitUntil: 'domcontentloaded', timeout: 30000 });
  await requireNoChallenge(page);
  const profile = page.locator('a[data-testid="AppTabBar_Profile_Link"]');
  try {
    await profile.waitFor({ state: 'visible', timeout: 20000 });
  } catch {
    await requireNoChallenge(page);
    throw new WorkerError('X did not confirm a signed-in account in Postslop’s background browser. Your session may need refreshing, or X may require an account check.', 'signed_out');
  }
  await requireNoChallenge(page);
  const href = await profile.getAttribute('href');
  const match = /^\/([A-Za-z0-9_]{1,15})\/?$/.exec(href ?? '');
  if (!match || match[1].toLowerCase() !== input.expectedHandle.toLowerCase()) {
    throw new WorkerError('The signed-in X account does not match Postslop’s expected account. Nothing was posted.', 'account_mismatch');
  }
  return match[1];
}

async function requireNoChallenge(page) {
  const challenge = new WorkerError('X requires an account verification check before Postslop can continue. Nothing was posted.', 'challenge');
  if (/^\/(?:account\/access|i\/flow\/(?:challenge|verify))\b/.test(new URL(page.url()).pathname)) throw challenge;
  const frames = page.locator('iframe[src*="captcha" i], iframe[src*="arkoselabs" i], iframe[src*="challenges.cloudflare.com" i]');
  for (const frame of await frames.all()) if (await frame.isVisible()) throw challenge;
  const headings = page.getByRole('dialog').getByRole('heading', {
    name: /^(?:Authenticate your account|Verify your identity|Verify you are human|Verify you're human|Help us keep your account safe|Your account (?:has been|is) locked|Unlock your account)$/i,
  });
  for (const heading of await headings.all()) if (await heading.isVisible()) throw challenge;
}

async function addImages(page, composerDialog, input) {
  const remove = composerDialog.getByRole('button', { name: 'Remove media', exact: true });
  for (let index = 0; index < 4 && await remove.count(); index += 1) await remove.first().click();
  if (await remove.count()) throw new WorkerError('X still has media from an earlier draft. Nothing was posted.', 'image_failed');
  if (!input.attachments.length) return;
  await composerDialog.locator('input[type="file"]').setInputFiles(input.attachments.map(({ name, mimeType, buffer }) => ({ name, mimeType, buffer })));
  const previews = composerDialog.locator('[data-testid="attachments"]');
  await previews.waitFor({ state: 'visible', timeout: 20000 });
  for (let index = 0; index < input.attachments.length; index += 1) {
    const attachment = input.attachments[index];
    if (!attachment.alt) continue;
    const descriptionLink = composerDialog.getByRole('link', { name: 'Add description', exact: true });
    if (index === 0 && await descriptionLink.isVisible()) await descriptionLink.click();
    else await previews.getByRole('button', { name: /^(Edit image|Edit media|Edit)$/i }).nth(index).click();
    const descriptionTab = page.getByRole('tab', { name: /^(Alt text|Add description)$/i });
    if (await descriptionTab.isVisible()) await descriptionTab.click();
    const description = page.getByRole('textbox', { name: /^(Description|Alt text|Image description|Add description)$/i });
    await description.fill(attachment.alt);
    await page.getByRole('button', { name: /^(Save|Done)$/i }).last().click();
    await description.waitFor({ state: 'hidden' });
  }
}

function postResult(body, handle, input) {
  const result = body?.data?.create_tweet?.tweet_results?.result;
  const tweet = result?.tweet ?? result;
  const user = tweet?.core?.user_results?.result;
  const actualHandle = user?.legacy?.screen_name ?? user?.core?.screen_name;
  const id = tweet?.rest_id;
  const fullText = tweet?.note_tweet?.note_tweet_results?.result?.text ?? tweet?.legacy?.full_text;
  if (!/^[0-9]+$/.test(id ?? '') || typeof actualHandle !== 'string'
    || actualHandle.toLowerCase() !== handle.toLowerCase() || typeof fullText !== 'string') return null;
  const media = tweet?.legacy?.extended_entities?.media ?? tweet?.legacy?.entities?.media;
  if (!input.attachments.length && Array.isArray(media) && media.length) return null;
  if (input.attachments.length) {
    if (!Array.isArray(media) || media.length !== input.attachments.length) return null;
    if (input.attachments.some((attachment, index) => attachment.alt && media[index].ext_alt_text !== attachment.alt)) return null;
  }
  // X appends media URLs and expands URLs in its response, so only compare exact plain text.
  if (!/https?:\/\//i.test(input.text)) {
    const expected = input.text.replace(/\r\n/g, '\n').trim();
    const actual = fullText.replace(/(?:^|\s+)https:\/\/t\.co\/\S+$/, '').trim();
    if (actual !== expected) return null;
  }
  return { platform: 'x', status: 'published', url: `https://x.com/${handle}/status/${id}` };
}

async function publish(page, input, handle, markSubmitted, diagnostic) {
  diagnostic.stage = 'compose';
  await page.goto(`${input.baseUrl}/compose/post`, { waitUntil: 'domcontentloaded', timeout: 25000 });
  await requireNoChallenge(page);
  diagnostic.controls = {
    textareas: await page.locator('[data-testid="tweetTextarea_0"]').count(),
    visibleDialogs: await page.locator('[role="dialog"]:visible').count(),
    dialogTextareas: await page.locator('[role="dialog"]:visible [data-testid="tweetTextarea_0"]').count(),
    fileInputs: await page.locator('input[type="file"]').count(),
  };
  const composerDialog = page.locator('[role="dialog"]:visible')
    .filter({ has: page.locator('[data-testid="tweetTextarea_0"]') }).last();
  await composerDialog.waitFor({ state: 'visible', timeout: 15000 });
  const composer = composerDialog.locator('[data-testid="tweetTextarea_0"]');
  await composer.waitFor({ state: 'visible', timeout: 15000 });
  // X's rich-text editor relies on keyboard events to update its state, including its placeholder.
  await composer.click();
  await composer.press('ControlOrMeta+A');
  await composer.press('Backspace');
  const lines = input.text.split(/\r?\n/);
  for (let index = 0; index < lines.length; index += 1) {
    if (index) await composer.press('Shift+Enter');
    await composer.pressSequentially(lines[index]);
  }
  if (input.text.trim()) await composerDialog.getByText(/^What['’]s happening\?$/)
    .waitFor({ state: 'hidden', timeout: 5000 });
  diagnostic.stage = 'images';
  try { await addImages(page, composerDialog, input); }
  catch { throw new WorkerError('Postslop could not attach every image and its alt text in X. Nothing was posted.', 'image_failed'); }
  diagnostic.stage = 'compose';
  const publishButton = composerDialog.locator('[data-testid="tweetButton"]');
  await publishButton.waitFor({ state: 'visible' });
  await composerDialog.locator('[data-testid="tweetButton"]:not([disabled]):not([aria-disabled="true"])')
    .waitFor({ state: 'visible', timeout: 20000 });
  await requireNoChallenge(page);
  diagnostic.stage = 'submit';
  if (input.prepareOnly) {
    await rm(join(input.profilePath, 'last-prepared.png'), { force: true });
    await page.screenshot({ path: join(input.profilePath, 'last-prepared.png'), timeout: 3000 });
    return { platform: 'x', status: 'prepared', code: 'prepared', handle: `@${handle}` };
  }
  let sentCreateTweet = false;
  let guardRejected = false;
  let resolveGuardRejection;
  const guardRejection = new Promise((resolve) => { resolveGuardRejection = resolve; });
  await page.route(/\/graphql\/[^/]+\/CreateTweet(?:\?|$)/, async (route) => {
    if (route.request().method() !== 'POST') return route.continue();
    if (sentCreateTweet || guardRejected) return route.abort('blockedbyclient');
    let variables;
    try { variables = route.request().postDataJSON()?.variables; } catch {}
    const media = variables?.media?.media_entities ?? [];
    if (variables?.tweet_text !== input.text.replace(/\r\n?/g, '\n')
      || !Array.isArray(media) || media.length !== input.attachments.length) {
      guardRejected = true;
      await route.abort('blockedbyclient');
      resolveGuardRejection(null);
      return;
    }
    sentCreateTweet = true;
    await route.continue();
  });
  const responsePromise = page.waitForResponse((response) => {
    const url = new URL(response.url());
    return url.origin === new URL(input.baseUrl).origin && /\/graphql\/[^/]+\/CreateTweet$/.test(url.pathname)
      && response.request().method() === 'POST';
  }, { timeout: process.env.POSTSLOP_TEST_MODE === '1' ? 4000 : 25000 }).catch(() => null);
  // A durable record precedes the only click. A crash or lost response must never cause an automatic duplicate.
  await markSubmitted();
  await publishButton.click({ timeout: 10000 });
  const response = await Promise.race([responsePromise, guardRejection]);
  if (guardRejected && !sentCreateTweet) return {
    platform: 'x', status: 'failed', code: 'composer_unavailable',
    error: 'X prepared different text or attachments than this draft. Postslop blocked the request before it was sent.',
  };
  if (!response) return { platform: 'x', status: 'unknown', code: 'unknown', error: 'X did not confirm the result after submission. Check your X profile before trying again; Postslop will not resubmit this request.' };
  const httpStatus = response.status();
  if (httpStatus >= 500 || httpStatus === 408) return { platform: 'x', status: 'unknown', code: 'unknown', error: 'X returned a server error after submission. The result is uncertain; check your profile before trying again.' };
  let body;
  try { body = await response.json(); } catch { body = null; }
  const success = response.ok() ? postResult(body, handle, input) : null;
  if (success) return success;
  const errors = body?.errors;
  if ((Array.isArray(errors) && errors.length && !body?.data?.create_tweet) || (httpStatus >= 400 && httpStatus < 500)) {
    const codes = (Array.isArray(errors) ? errors : []).map((error) => error.code).filter(Number.isInteger);
    if (codes.includes(187)) return { platform: 'x', status: 'unknown', code: 'unknown', error: 'X reported duplicate content. An earlier network attempt may already have posted it; check your profile before trying again.' };
    const reason = codes.includes(226)
      ? 'X rejected the post as suspected automation (code 226). Postslop stopped; no further attempt was made.'
      : `X rejected this post${codes.length ? ` (code ${codes.join(', ')})` : ''}. Postslop stopped; no further attempt was made.`;
    const code = codes.includes(226) ? 'automation_rejected' : codes.includes(344) || httpStatus === 429 ? 'rate_limited' : 'rejected';
    return { platform: 'x', status: 'failed', code, error: reason };
  }
  return { platform: 'x', status: 'unknown', code: 'unknown', error: 'X did not return a verified post ID for the expected account. Check your profile before trying again.' };
}

export async function runWorker(rawInput) {
  let context;
  let input;
  let submitted = false;
  let journalPath;
  let timedOut = false;
  let deadline;
  let unlock;
  let abort;
  const diagnostic = { stage: 'start', page: null };
  const onTerminate = () => {
    timedOut = true;
    abort?.(new WorkerError('Postslop stopped the background X operation.', submitted ? 'unknown' : 'unavailable'));
  };
  const failure = (message, code) => rawInput?.operation === 'status'
    ? { id: 'x', label: 'X', available: false, code, reason: message }
    : { platform: 'x', status: submitted ? 'unknown' : 'failed', code: submitted ? 'unknown' : code, error: message };
  try {
    try { input = validateInput(rawInput); }
    catch (error) { if (error instanceof WorkerError) error.code = 'invalid_request'; throw error; }
    await mkdir(input.profilePath, { recursive: true, mode: 0o700 });
    await chmod(input.profilePath, 0o700);
    unlock = await lockProfile(input.profilePath);
    if (input.operation === 'publish' && !input.prepareOnly) {
      journalPath = join(input.profilePath, 'postslop-requests', `${input.requestId}.json`);
      const previous = await readJson(journalPath);
      if (previous?.result) return previous.result;
      if (previous?.submitted) return { platform: 'x', status: 'unknown', code: 'unknown', error: 'This request was already submitted to X without a confirmed outcome. Check your X profile; Postslop will not submit it twice.' };
    }
    const work = async () => {
      context = await firefox.launchPersistentContext(join(input.profilePath, 'firefox'), {
        headless: true,
        // Firefox otherwise retries some failed connections internally, below request interception.
        firefoxUserPrefs: { 'network.http.request.max-attempts': 1 },
        acceptDownloads: false, timeout: 20000,
      });
      if (timedOut) {
        await context.close();
        throw new WorkerError('Postslop stopped before preparing the X post. Nothing was posted.');
      }
      context.setDefaultTimeout(12000);
      if (input.prepareOnly) await context.route(/\/graphql\/[^/]+\/CreateTweet(?:\?|$)/, (route) => {
        return route.request().method() === 'POST' ? route.abort('blockedbyclient') : route.continue();
      });
      diagnostic.stage = 'account';
      await importSession(context, input);
      const page = context.pages()[0] ?? await context.newPage();
      diagnostic.page = page;
      const handle = await signedInAccount(page, input);
      if (input.operation === 'status') return { id: 'x', label: 'X', available: true, handle: `@${handle}` };
      return publish(page, input, handle, async () => {
        if (timedOut) throw new WorkerError('The background X operation timed out before submission.');
        await privateJson(journalPath, { submitted: true, at: new Date().toISOString() });
        submitted = true;
      }, diagnostic);
    };
    const timeout = new Promise((_, reject) => {
      abort = reject;
      process.once('SIGTERM', onTerminate);
      deadline = setTimeout(() => {
        timedOut = true;
        reject(new WorkerError(submitted
          ? 'Postslop timed out after submitting to X. Check your profile before trying again; this request will not be resubmitted.'
          : 'Postslop’s background X operation timed out before submission. Nothing was posted.'));
      }, 80000);
    });
    const result = await Promise.race([work(), timeout]);
    if (submitted) await privateJson(journalPath, { submitted: true, result });
    return result;
  } catch (error) {
    const failureCode = error instanceof WorkerError ? error.code
      : diagnostic.stage === 'compose' ? 'composer_unavailable'
        : diagnostic.stage === 'images' ? 'image_failed' : 'unavailable';
    if (input && unlock) {
      const screenshotPath = join(input.profilePath, 'last-error.png');
      await rm(screenshotPath, { force: true }).catch(() => {});
      let screenshotSaved = false;
      if (!submitted && diagnostic.page) {
        try {
          await diagnostic.page.screenshot({ path: screenshotPath, timeout: 3000 });
          screenshotSaved = true;
        } catch {}
      }
      await privateJson(join(input.profilePath, 'last-error.json'), {
        stage: diagnostic.stage,
        errorName: ['WorkerError', 'Error', 'TimeoutError'].includes(error?.name) ? error.name : 'Error',
        code: failureCode,
        submitted,
        screenshotSaved,
        at: new Date().toISOString(),
        ...(diagnostic.controls ? { controls: diagnostic.controls } : {}),
      }).catch(() => {});
    }
    const message = error instanceof WorkerError ? error.message : submitted
      ? 'Postslop lost confirmation after submitting to X. Check your profile before trying again; this request will not be resubmitted.'
      : failureCode === 'composer_unavailable' ? 'Postslop could not prepare X’s composer. Nothing was posted.'
      : 'Postslop could not complete the background X operation. Nothing was posted. Check that the browser worker is installed and X accepts your session.';
    if (!(error instanceof WorkerError)) process.stderr.write(`X worker stopped (${error?.name ?? 'Error'}).\n`);
    return failure(message, failureCode);
  } finally {
    clearTimeout(deadline);
    process.off('SIGTERM', onTerminate);
    if (context) await context.close().catch(() => {});
    if (unlock) await unlock();
  }
}

// Shared only with Postslop's local, read-only published-post verifier.
export { lockProfile, importSession, signedInAccount };

if (process.argv[1] && resolve(process.argv[1]) === fileURLToPath(import.meta.url)) {
  let raw = '';
  let operation;
  let responded = false;
  const respond = (result) => {
    if (responded) return;
    responded = true;
    clearTimeout(hardDeadline);
    process.stdout.write(`${JSON.stringify(result)}\n`, () => process.exit(0));
  };
  const hardDeadline = setTimeout(() => respond(operation === 'status'
    ? { id: 'x', label: 'X', available: false, code: 'unavailable', reason: 'Postslop’s background X check timed out.' }
    : { platform: 'x', status: 'unknown', code: 'unknown', error: 'Postslop could not confirm the X result before its deadline. Check your profile before trying again.' }), 88000);
  try {
    process.stdin.setEncoding('utf8');
    for await (const chunk of process.stdin) {
      raw += chunk;
      if (Buffer.byteLength(raw) > maximumInputBytes) throw new WorkerError('The X request is too large.');
    }
    const input = JSON.parse(raw);
    operation = input.operation;
    respond(await runWorker(input));
  } catch {
    respond({ platform: 'x', status: 'failed', code: 'invalid_request', error: 'Postslop received an invalid X worker request.' });
  }
}
