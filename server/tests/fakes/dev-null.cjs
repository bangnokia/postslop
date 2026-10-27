// A stand-in for the site's web requests, with fake accounts only. Never deploy it.
const values = new Map();
let scenario = '';
let checks = 0;
const state = { get: async (key) => values.get(key), set: async (key, value) => { values.set(key, value); } };
const send = (res, code, value, headers = {}) => { res.writeHead(code, { 'Content-Type': 'application/json', ...headers }); res.end(typeof value === 'string' ? value : JSON.stringify(value)); };
const read = async (req) => {
  if (req.body !== undefined) return req.body;
  const chunks = []; for await (const chunk of req) chunks.push(chunk);
  return Buffer.concat(chunks);
};
function fields(body, type) {
  if (body && typeof body === 'object' && !Buffer.isBuffer(body)) return body;
  const raw = Buffer.isBuffer(body) ? body.toString('latin1') : String(body);
  if (!type?.includes('multipart/form-data')) return Object.fromEntries(new URLSearchParams(raw));
  const boundary = /boundary=(?:"([^"]+)"|([^;]+))/.exec(type);
  const result = {};
  for (const part of raw.split('--' + (boundary?.[1] || boundary?.[2]))) {
    const name = /name="([^"]+)"/.exec(part)?.[1];
    const offset = part.indexOf('\r\n\r\n');
    if (name && offset >= 0) result[name] = part.slice(offset + 4).replace(/\r\n$/, '');
  }
  return result;
}
module.exports = async (req, res) => {
  const bodyPromise = read(req);
  const url = new URL(req.url, 'http://fixture');
  const body = await bodyPromise;
  const posts = await state.get('dev-null.posts') || [];
  if (req.method === 'POST' && url.pathname === '/__reset') {
    ({ scenario = '' } = body.length ? JSON.parse(body) : {});
    checks = 0;
    await state.set('dev-null.posts', []);
    return send(res, 200, { reset: true });
  }
  if (req.method === 'GET' && url.pathname === '/__stats') return send(res, 200, { posts, checks });
  if (!req.headers.cookie?.includes('dev_null_session=fixture-session')) return send(res, 401, { error: 'Unauthenticated' });
  const identity = scenario === 'legacy' ? { username: 'tester' } : { handle: 'tester' };
  const page = { component: 'user-profile/edit', version: 'v1', props: { auth: { user: { id: 'u1', ...identity } }, errors: {} } };
  if (req.method === 'GET' && url.pathname === '/settings/profile') {
    checks += 1;
    if (scenario === 'blocked') return send(res, 403, { private: 'fixture-session' });
    if (scenario === 'missing-handle') delete page.props.auth.user.handle;
    if (scenario === 'missing-user') page.props.auth.user = null;
    if (scenario === 'wrong-handle') page.props.auth.user = { id: 'u2', handle: 'someone_else', username: 'tester' };
    if (posts.length) page.flash = { committed: { id: posts.at(-1).id, body: posts.at(-1).body } };
    return send(res, 200, page, { 'Set-Cookie': 'XSRF-TOKEN=fixture-xsrf; Path=/; SameSite=Lax' });
  }
  if (req.method === 'POST' && url.pathname === '/commits') {
    if (req.headers['x-xsrf-token'] !== 'fixture-xsrf' || req.headers['x-inertia-version'] !== 'v1') return send(res, 419, { error: 'Invalid CSRF token' });
    const form = fields(body, req.headers['content-type']);
    if (typeof form.body !== 'string') return send(res, 422, { error: 'Missing body', fields: Object.keys(form), bodyType: typeof body });
    const count = Object.keys(form).filter(key => /^images\[\d+\]$/.test(key)).length;
    const images = Array.from({ length: count }, (_, i) => ({ id: 'img-' + i, description: form['image_descriptions[' + i + ']'] || form.image_descriptions?.[i] || '' }));
    const id = '00000000-0000-4000-8000-' + String(posts.length + 1).padStart(12, '0');
    posts.push({ id, body: form.body, user: scenario === 'wrong-author' ? { handle: 'someone_else' } : identity, images, status: 'pending' });
    await state.set('dev-null.posts', posts);
    return send(res, 303, '', { Location: '/settings/profile' });
  }
  if (req.method === 'GET' && /^\/@tester\/commits\//.test(url.pathname)) {
    const commit = posts.find(item => item.id === url.pathname.split('/').at(-1));
    return commit ? send(res, 200, { ...page, props: { ...page.props, commit } }) : send(res, 404, {});
  }
  return send(res, 501, { error: 'Unmodeled dev-null request', method: req.method, path: url.pathname });
};
