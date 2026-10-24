// Fake X, Bluesky, dev-null.sh, LinkedIn and Threads on one local port, for publishing checks
// that run the real server without touching a real account:
//
//   node tests/fakes/server.mjs [--port=4900] [--browser]
//
// Each site lives under its own path: /x, /bluesky, /dev-null, /linkedin, /threads. --browser serves
// X's website composer for the background browser worker instead of its HTTP API.
// Every site also answers /__reset and /__stats.
import { createServer } from 'node:http';
import { createRequire } from 'node:module';
import { xFixture } from '../../browser-worker/test/fixture.mjs';

const require = createRequire(import.meta.url);
const option = (name) => process.argv.find((word) => word.startsWith(`--${name}`))?.split('=')[1];
const port = Number(option('port') ?? 4900);
const browser = process.argv.includes('--browser');
const website = xFixture({ handle: 'tester', prefix: '/x' });
const sites = {
  x: browser
    ? async (request, response) => {
        if (request.url === '/__stats') {
          response.setHeader('Content-Type', 'application/json');
          return response.end(JSON.stringify({ posts: website.posts }));
        }
        // The website fixture builds its own links under /x, so it sees the full path.
        request.url = '/x' + request.url;
        return website.handler(request, response);
      }
    : require('./x.cjs'),
  bluesky: require('./bluesky.cjs'),
  'dev-null': require('./dev-null.cjs'),
  linkedin: require('./linkedin.cjs'),
  threads: require('./threads.cjs'),
};

const server = createServer(async (request, response) => {
  const [, site, ...rest] = request.url.split('/');
  const handler = sites[site];
  if (!handler) {
    response.writeHead(404, { 'Content-Type': 'application/json' });
    return response.end(JSON.stringify({ error: 'Use /x, /bluesky, /dev-null, /linkedin or /threads.' }));
  }
  request.url = '/' + rest.join('/');
  try {
    await handler(request, response);
  } catch (error) {
    if (!response.headersSent) response.writeHead(500, { 'Content-Type': 'application/json' });
    response.end(JSON.stringify({ error: `The ${site} fake failed: ${error.message}` }));
  }
});
server.listen(port, '127.0.0.1', () => {
  process.stdout.write(`Fake sites on http://127.0.0.1:${server.address().port} (/x${browser ? ' website' : ''}, /bluesky, /dev-null, /linkedin, /threads)\n`);
});
