import { firefox } from 'playwright';
import { mkdir, readFile, rm } from 'node:fs/promises';
import { dirname, join, resolve } from 'node:path';
import { fileURLToPath } from 'node:url';
import { importSession, lockProfile } from './x-worker.mjs';

process.umask(0o077);
const root = resolve(dirname(fileURLToPath(import.meta.url)), '..');
const input = {
  expectedHandle: 'bangnokia', baseUrl: 'https://x.com',
  session: JSON.parse(await readFile(join(root, 'storage/sessions.json'), 'utf8')).accounts.x.session,
  profilePath: join(root, 'storage/browser/x'),
};
const url = process.argv[2];
let context;
let unlock;
let result;
let timer;
let page;
let phase = 'account';

try {
  const match = /^https:\/\/x\.com\/bangnokia\/status\/([0-9]+)$/.exec(url ?? '');
  if (!match) throw new Error('invalid_url');
  const original = JSON.parse(await readFile(join(root, 'storage/retry-tests/original-native-draft.json'), 'utf8'));
  await mkdir(input.profilePath, { recursive: true, mode: 0o700 });
  unlock = await lockProfile(input.profilePath);
  const verification = async () => {
    context = await firefox.launchPersistentContext(join(input.profilePath, 'firefox'), {
      headless: true, firefoxUserPrefs: { 'network.http.request.max-attempts': 1 },
      acceptDownloads: false, timeout: 20000,
    });
    context.setDefaultTimeout(15000);
    await importSession(context, input);
    page = context.pages()[0] ?? await context.newPage();
    phase = 'article';
    // This reader has no input or submission actions; navigate only to the requested article.
    await page.goto(url, { waitUntil: 'commit', timeout: 25000 });
    const article = page.locator('article[data-testid="tweet"]').filter({
      has: page.locator(`a[href="/bangnokia/status/${match[1]}"]`),
    });
    await article.waitFor({ state: 'visible', timeout: 30000 });
    const accountMatches = await page.locator('a[data-testid="AppTabBar_Profile_Link"]').getAttribute('href') === '/bangnokia';
    phase = 'text';
    const author = article.locator('[data-testid="User-Name"] a[href="/bangnokia"]');
    const authorMatches = await author.count() > 0;
    const actualText = await article.locator('[data-testid="tweetText"]').evaluate((element) => {
      const text = (node) => {
        if (node.nodeType === Node.TEXT_NODE) return node.textContent;
        if (node.nodeName === 'IMG') return node.getAttribute('alt') ?? '';
        if (node.nodeName === 'BR') return '\n';
        return Array.from(node.childNodes, text).join('');
      };
      return text(element);
    });
    const textMatches = actualText.replace(/\r\n?/g, '\n').trim() === original.text.replace(/\r\n?/g, '\n').trim();
    const photos = article.locator('[data-testid="tweetPhoto"]');
    phase = 'photos';
    if (original.images.length) await photos.nth(original.images.length - 1).waitFor({ state: 'visible' });
    const photoCount = await photos.count();
    const photosMatch = photoCount === original.images.length;
    for (const photo of await photos.all()) {
      await photo.scrollIntoViewIfNeeded();
      await photo.locator('img').waitFor({ state: 'visible' });
      await page.waitForFunction((image) => image.complete && image.naturalWidth > 0,
        await photo.locator('img').elementHandle(), { timeout: 10000 });
    }
    const screenshotPath = join(input.profilePath, 'last-published.png');
    phase = 'screenshot';
    await rm(screenshotPath, { force: true });
    await article.screenshot({ path: screenshotPath, timeout: 5000 });
    return { verified: accountMatches && authorMatches && textMatches && photosMatch, url, author: input.expectedHandle, textMatches, photoCount };
  };
  result = await Promise.race([
    verification(),
    new Promise((_, reject) => { timer = setTimeout(() => reject(new Error('verification_timeout')), 75000); }),
  ]);
} catch (error) {
  await rm(join(input.profilePath, 'last-published-check.png'), { force: true }).catch(() => {});
  if (page) await page.screenshot({ path: join(input.profilePath, 'last-published-check.png'), timeout: 3000 }).catch(() => {});
  result = { verified: false, url, phase, errorName: error?.name === 'TimeoutError' ? 'TimeoutError' : 'Error', error: 'Postslop could not verify the published article.' };
} finally {
  clearTimeout(timer);
  if (context) await context.close().catch(() => {});
  if (unlock) await unlock();
}

process.stdout.write(`${JSON.stringify(result)}\n`);
