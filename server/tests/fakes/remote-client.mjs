// A local Postslop API stand-in for the distributed client. Synthetic credentials only.
import { createServer } from 'node:http';
let scenario = '';
let requests = [];
const account = (id = 'threads') => ({ id, label: id, handle: 'tester', available: scenario !== 'unavailable', enabled: true, connected: true,
  ...(scenario === 'unavailable' ? { reason: 'Fixture session rejected.' } : {}) });
const send = (res, code, data, headers = {}) => { res.writeHead(code, { 'Content-Type': 'application/json', ...headers }); res.end(JSON.stringify(data)); };
const server = createServer(async (req, res) => {
  const chunks = []; for await (const chunk of req) chunks.push(chunk);
  const raw = Buffer.concat(chunks).toString();
  const url = new URL(req.url, 'http://fixture');
  if (url.pathname === '/__reset') { scenario = JSON.parse(raw || '{}').scenario || ''; requests = []; return send(res, 200, {}); }
  if (url.pathname === '/__stats') return send(res, 200, { requests });
  requests.push({ method: req.method, path: req.url });
  if (scenario === 'redirect') return send(res, 302, {}, { Location: `http://127.0.0.1:${server.address().port}/redirect-target` });
  if (req.headers.authorization !== 'Bearer fixture-client-token') return send(res, 401, { error: { message: 'fixture-client-token' } });
  if (scenario === 'error') return send(res, 500, { error: { message: 'fixture-client-secret fixture-client-token' } });
  if (url.pathname === '/api/v1/posts') return send(res, 200, { posts: [] });
  if (url.pathname === '/api/v1/accounts' && req.method === 'GET') return send(res, 200, { platforms: [account()] });
  if (req.method === 'POST' || req.method === 'PUT' || req.method === 'PATCH') {
    if (req.headers['content-type'] !== 'application/json') return send(res, 415, {});
  }
  if (url.pathname === '/api/v1/accounts/threads/check') return send(res, 200, account());
  if (url.pathname === '/api/v1/accounts/threads' && req.method === 'PUT') {
    const input = JSON.parse(raw);
    if (input.handle !== 'tester' || input.source !== 'firefox' || input.session.cookies.length !== 3
      || !input.session.cookies.some(c => c.name === 'sessionid' && c.value === 'fixture-client-secret')) return send(res, 422, {});
    return send(res, 200, { platforms: [scenario === 'missing-status' ? { id: 'x' } : { ...account(), ...(scenario === 'wrong-handle' ? { handle: 'someone_else' } : {}) }] });
  }
  if (url.pathname === '/api/v1/accounts/threads' && ['PATCH', 'DELETE'].includes(req.method)) return send(res, 200, { platforms: [account()] });
  return send(res, 404, {});
});
server.listen(0, '127.0.0.1', () => console.log(`http://127.0.0.1:${server.address().port}`));
