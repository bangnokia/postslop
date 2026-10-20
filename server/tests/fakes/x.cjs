// A stand-in for the site's web requests, with fake accounts only. Never deploy it.
const values = new Map();
const state = { get: async (key) => values.get(key), set: async (key, value) => { values.set(key, value); } };
const send = (res, code, value, headers = {}) => {
  res.writeHead(code, { 'Content-Type': 'application/json', ...headers });
  res.end(typeof value === 'string' ? value : JSON.stringify(value));
};
const read = async (req) => {
  if (req.body !== undefined) return req.body;
  const chunks = [];
  for await (const chunk of req) chunks.push(chunk);
  const raw = Buffer.concat(chunks);
  return req.headers['content-type']?.includes('application/json') ? (raw.length ? JSON.parse(raw.toString()) : {}) : raw;
};
module.exports = async (req, res) => {
  const bodyPromise = read(req);
  const url = new URL(req.url, 'http://fixture');
  const body = await bodyPromise;
  const posts = await state.get('x.posts') || [];
  const media = await state.get('x.media') || {};
  if (req.method === 'POST' && url.pathname === '/__reset') {
    await state.set('x.posts', []); await state.set('x.media', {});
    return send(res, 200, { reset: true });
  }
  if (req.method === 'GET' && url.pathname === '/__stats') return send(res, 200, { posts, media });
  if (req.method === 'GET' && url.pathname === '/main.js') {
    if (req.headers.cookie || req.headers.authorization) return send(res, 400, { error: 'Credentials forwarded to public bundle' });
    return send(res, 200, 'const token="Bearer AAAAAfixture";const operation={queryId:"fixtureCreate",operationName:"CreateTweet",operationType:"mutation",metadata:{featureSwitches:["fixture_enabled"],fieldToggles:[]}};', { 'Content-Type': 'application/javascript' });
  }
  if (!req.headers.cookie?.includes('auth_token=fixture-auth')) return send(res, 401, { errors: [{ code: 32 }] });
  if (req.method === 'GET' && url.pathname === '/home') {
    const state = { session: { user_id: '123' }, entities: { users: { entities: { '123': { screen_name: 'tester' } } } }, featureSwitch: { defaultConfig: { fixture_enabled: { value: true } }, user: { config: {} } } };
    return send(res, 200, `<script>window.__INITIAL_STATE__=${JSON.stringify(state)};window.__META_DATA__={};</script>`, { 'Content-Type': 'text/html' });
  }
  if (req.headers['x-csrf-token'] !== 'fixture-csrf' || req.headers.authorization !== 'Bearer AAAAAfixture') return send(res, 403, { errors: [{ code: 353 }] });
  if (url.pathname === '/i/media/upload.json') {
    const command = url.searchParams.get('command');
    const id = url.searchParams.get('media_id');
    if (req.method === 'POST' && command === 'INIT') {
      if (url.searchParams.get('media_category') !== 'tweet_image' || Number(url.searchParams.get('total_bytes')) < 1) return send(res, 400, { error: 'Invalid media declaration' });
      const next = String(1000 + Object.keys(media).length);
      media[next] = { mimeType: url.searchParams.get('media_type'), bytes: Number(url.searchParams.get('total_bytes')), appended: false, finalized: false };
      await state.set('x.media', media);
      return send(res, 200, { media_id_string: next, expires_after_secs: 86400 });
    }
    if (!media[id]) return send(res, 404, { error: 'Unknown media' });
    if (req.method === 'POST' && command === 'APPEND') {
      if (!req.headers['content-type']?.includes('multipart/form-data') || url.searchParams.get('segment_index') !== '0') return send(res, 400, { error: 'Expected multipart segment 0' });
      media[id].appended = true; await state.set('x.media', media);
      return send(res, 204, '');
    }
    if (req.method === 'POST' && command === 'FINALIZE') {
      if (!media[id].appended) return send(res, 400, { error: 'Missing image bytes' });
      media[id].finalized = true; await state.set('x.media', media);
      return send(res, 200, { media_id_string: id });
    }
  }
  if (req.method === 'POST' && url.pathname === '/i/api/1.1/media/metadata/create.json') {
    if (!media[body.media_id]?.finalized || !body.alt_text?.text) return send(res, 400, { error: 'Invalid image description' });
    media[body.media_id].alt = body.alt_text.text; await state.set('x.media', media);
    return send(res, 200, {});
  }
  if (req.method === 'POST' && url.pathname === '/i/api/graphql/fixtureCreate/CreateTweet') {
    const variables = body.variables;
    if (body.queryId !== 'fixtureCreate' || body.features?.fixture_enabled !== true || !Array.isArray(variables?.media?.media_entities)) return send(res, 400, { errors: [{ code: 200 }] });
    if (variables.media.media_entities.some(item => !media[item.media_id]?.finalized)) return send(res, 400, { errors: [{ code: 324 }] });
    if (variables.tweet_text.includes('[simulate:rate-limit]')) return send(res, 429, { errors: [{ code: 88 }] });
    const id = String(9000 + posts.length);
    posts.push({ id, text: variables.tweet_text, media: variables.media.media_entities });
    await state.set('x.posts', posts);
    if (variables.tweet_text.includes('[simulate:unknown]')) return send(res, 503, '<html>Response lost after accepting</html>', { 'Content-Type': 'text/html' });
    return send(res, 200, { data: { create_tweet: { tweet_results: { result: { rest_id: id, legacy: { id_str: id } } } } } });
  }
  return send(res, 501, { error: 'Unmodeled X website request', method: req.method, path: url.pathname });
};
