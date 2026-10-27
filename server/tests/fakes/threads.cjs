// Synthetic Threads web protocol. No real credentials or requests. Never deploy it.
const { createHash } = require('node:crypto');
let posts = [];
let uploads = new Map();
let scenario = '';
let submissions = 0;
const send = (res, code, value, headers = {}) => {
  res.writeHead(code, { 'Content-Type': 'application/json', ...headers });
  res.end(typeof value === 'string' ? value : JSON.stringify(value));
};
module.exports = async (req, res) => {
  const chunks = [];
  for await (const chunk of req) chunks.push(chunk);
  const bytes = Buffer.concat(chunks);
  const path = new URL(req.url, 'http://fixture').pathname;
  if (req.method === 'POST' && path === '/__reset') {
    ({ scenario = '' } = bytes.length ? JSON.parse(bytes) : {});
    posts = []; uploads = new Map(); submissions = 0;
    return send(res, 200, { reset: true });
  }
  if (path === '/__stats') return send(res, 200, { posts, uploads: [...uploads.values()], submissions });
  const cookies = Object.fromEntries((req.headers.cookie || '').split('; ').map((part) => {
    const at = part.indexOf('='); return [part.slice(0, at), part.slice(at + 1)];
  }));
  if (cookies.sessionid !== 'fixture-threads-session' || cookies.ds_user_id !== '12345' || scenario === 'expired') {
    return send(res, 302, '', { Location: 'https://example.invalid/login' });
  }
  if (cookies.foreign || cookies.expired || cookies.scoped) return send(res, 400, { error: 'Unrelated cookies forwarded' });
  if (path === '/' && req.method === 'GET') {
    if (scenario === 'session-cleared') return send(res, 302, '', {
      Location: 'https://example.invalid/?private=fixture-threads-session',
      'Set-Cookie': 'sessionid=deleted; Max-Age=-1; Expires=Thu, 01 Jan 1970 00:00:01 GMT; Path=/',
    });
    if (scenario === 'session-expired-date') return send(res, 302, '', {
      Location: 'https://example.invalid/',
      'Set-Cookie': 'sessionid=deleted; Expires=Thu, 01 Jan 1970 00:00:01 GMT; Path=/',
    });
    if (scenario === 'check-blocked') return send(res, 403, 'fixture-threads-session');
    if (scenario === 'check-rate-limit') return send(res, 429, 'fixture-threads-session');
    if (scenario === 'page-changed') return send(res, 200, '<html>fixture-threads-session</html>');
    return send(res, 200, '<script>["LSD",[],{"token":"fixture-lsd"}],["DTSGInitData",[],{"token":"fixture-dtsg"}],["CurrentUserInitialData",[],{"USER_ID":"12345"}]</script>', { 'Content-Type': 'text/html' });
  }
  if (req.headers['x-csrftoken'] !== 'fixture-threads-csrf' || req.headers['x-ig-app-id'] !== '238260118697367'
    || req.headers['sec-fetch-site'] !== 'same-origin') return send(res, 403, { status: 'fail' });
  const form = Object.fromEntries(new URLSearchParams(bytes.toString()));
  if (path === '/graphql/query') {
    if (scenario === 'query-changed') return send(res, 200, { errors: [{ message: 'Unknown persisted query' }] });
    if (form.fb_dtsg !== 'fixture-dtsg' || form.lsd !== 'fixture-lsd' || req.headers['x-fb-lsd'] !== 'fixture-lsd') return send(res, 403, { status: 'fail' });
    if (form.fb_api_req_friendly_name === 'BarcelonaFeedTimelineViewerQuery') {
      if (scenario === 'missing-viewer') return send(res, 200, { data: { viewer: null } });
      return send(res, 200, { data: { viewer: { user: { id: scenario === 'wrong-viewer' ? '67890' : '12345', text_post_app_is_private: false, hide_like_and_view_counts: false } } } });
    }
    if (form.fb_api_req_friendly_name === 'BarcelonaUserDialogQuery' && JSON.parse(form.variables).userID === '12345') {
      if (scenario === 'missing-profile') return send(res, 200, { data: { user: null } });
      return send(res, 200, { data: { user: { pk: scenario === 'wrong-profile-id' ? '67890' : '12345', username: scenario === 'wrong-handle' ? 'someone_else' : 'tester' } } });
    }
    return send(res, 400, { errors: [{ message: 'Unmodeled account query' }] });
  }
  if (req.method === 'POST' && path.startsWith('/rupload_igphoto/')) {
    if (scenario === 'upload-failed') return send(res, 503, 'Upload interrupted');
    const params = JSON.parse(req.headers['x-instagram-rupload-params'] || '{}');
    const id = params.upload_id;
    if (params.is_threads !== '1' || params.media_type !== 1 || !params.upload_media_height || !params.upload_media_width
      || !['image/png', 'image/jpeg', 'image/webp'].includes(req.headers['content-type']) || !bytes.length
      || req.headers['x-entity-length'] !== String(bytes.length) || req.headers.offset !== '0'
      || req.headers['x-entity-name'] !== `fb_uploader_${id}` || path !== `/rupload_igphoto/fb_uploader_${id}`) {
      return send(res, 422, { status: 'fail' });
    }
    uploads.set(id, { id, sidecar: params.is_sidecar, sha256: createHash('sha256').update(bytes).digest('hex') });
    return send(res, 200, { status: 'ok', upload_id: id });
  }
  if (req.method === 'POST' && /^\/api\/v1\/media\/configure_(text_only_post|text_post_app_feed|text_post_app_sidecar)\/$/.test(path)) {
    submissions += 1;
    const sidecar = path.endsWith('/configure_text_post_app_sidecar/');
    const single = path.endsWith('/configure_text_post_app_feed/');
    const input = sidecar ? JSON.parse(bytes.toString()) : form;
    const info = JSON.parse(input.text_post_app_info || '{}');
    const children = sidecar ? input.children_metadata : single ? [input] : [];
    if (typeof input.caption !== 'string' || info.text_with_entities?.text !== input.caption || !info.self_thread_context_id
      || children.some((child) => !uploads.has(child.upload_id))
      || (sidecar && !req.headers['content-type'].startsWith('text/plain'))
      || (!sidecar && !req.headers['content-type'].startsWith('application/x-www-form-urlencoded'))
      || (!single && !sidecar && input.publish_mode !== 'text_post')) return send(res, 422, { status: 'fail' });
    if (scenario === 'rate-limit') return send(res, 429, { status: 'fail', message: 'fixture-threads-session' });
    if (scenario === 'rejected') return send(res, 200, { status: 'fail', message: 'fixture-threads-session' });
    const media = {
      code: 'Fixture_' + (posts.length + 1), user: { pk: '12345', username: 'tester' },
      caption: { text: input.caption }, media_type: sidecar ? 8 : single ? 1 : 19,
    };
    const childMedia = children.map((child) => ({ media_type: 1, accessibility_caption: child.custom_accessibility_caption }));
    if (sidecar) media.carousel_media = childMedia;
    if (single) media.accessibility_caption = childMedia[0].accessibility_caption;
    posts.push({ text: input.caption, children, media: structuredClone(media) });
    if (scenario === 'unknown') return send(res, 502, 'Lost reply after accepting the post');
    if (scenario === 'timeout') return send(res, 408, 'Timeout after accepting the post');
    if (scenario === 'disconnect') return req.socket.destroy();
    if (scenario === 'malformed') return send(res, 200, '<html>Unexpected response</html>');
    if (scenario === 'wrong-author') media.user.username = 'someone_else';
    if (scenario === 'wrong-text') media.caption.text = 'A different post';
    if (scenario === 'missing-image') media.media_type = 19;
    if (scenario === 'missing-alt') delete media.accessibility_caption;
    if (scenario === 'unsafe-code') media.code = '../elsewhere?utm_source=tracking';
    return send(res, 200, { status: 'ok', media });
  }
  return send(res, 501, { error: 'Unmodeled Threads request' });
};
