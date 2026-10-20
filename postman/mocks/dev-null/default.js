const http = require('http');
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
http.createServer(async (req, res) => {
  const bodyPromise = read(req);
  const url = new URL(req.url, 'http://fixture');
  const body = await bodyPromise;
  const posts = await pm.state.get('dev-null.posts') || [];
  if (req.method === 'POST' && url.pathname === '/__reset') { await pm.state.set('dev-null.posts', []); return send(res, 200, { reset: true }); }
  if (req.method === 'GET' && url.pathname === '/__stats') return send(res, 200, { posts });
  if (!req.headers.cookie?.includes('dev_null_session=fixture-session')) return send(res, 401, { error: 'Unauthenticated' });
  const page = { component: 'Settings/Profile', version: 'v1', props: { auth: { user: { id: 'u1', username: 'tester' } }, errors: {} } };
  if (req.method === 'GET' && url.pathname === '/settings/profile') {
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
    posts.push({ id, body: form.body, user: { username: 'tester' }, images, status: 'approved' });
    await pm.state.set('dev-null.posts', posts);
    return send(res, 303, '', { Location: '/settings/profile' });
  }
  if (req.method === 'GET' && /^\/@tester\/commits\//.test(url.pathname)) {
    const commit = posts.find(item => item.id === url.pathname.split('/').at(-1));
    return commit ? send(res, 200, { ...page, props: { ...page.props, commit } }) : send(res, 404, {});
  }
  return send(res, 501, { error: 'Unmodeled dev-null request', method: req.method, path: url.pathname });
}).listen(process.env.PORT);
