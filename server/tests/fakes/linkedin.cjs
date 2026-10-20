// A stand-in for linkedin.com's web API (Voyager), with a fake account only. Never deploy it.
let posts = [];
let uploads = new Map();
const send = (res, code, value, headers = {}) => { res.writeHead(code, { 'Content-Type': 'application/json', ...headers }); res.end(typeof value === 'string' ? value : JSON.stringify(value)); };
const read = async (req) => { const chunks = []; for await (const chunk of req) chunks.push(chunk); return Buffer.concat(chunks); };
module.exports = async (req, res) => {
  const body = await read(req);
  const url = new URL(req.url, 'http://fixture');
  if (req.method === 'POST' && url.pathname === '/__reset') { posts = []; uploads = new Map(); return send(res, 200, { reset: true }); }
  if (req.method === 'GET' && url.pathname === '/__stats') return send(res, 200, { posts, uploads: [...uploads.values()] });
  // li_at signs in; JSESSIONID's value, without its quotes, must come back as the csrf-token header.
  if (!req.headers.cookie?.includes('li_at=fixture-li-at') || !req.headers.cookie?.includes('JSESSIONID="ajax:fixture"')) return send(res, 302, '', { Location: '/login' });
  if (req.headers['csrf-token'] !== 'ajax:fixture') return send(res, 403, { status: 403, message: 'CSRF check failed.' });
  if (req.method === 'GET' && url.pathname === '/voyager/api/me') {
    return send(res, 200, { data: { plainId: 1, '*miniProfile': 'urn:li:fs_miniProfile:fixture' }, included: [{ entityUrn: 'urn:li:fs_miniProfile:fixture', publicIdentifier: 'tester', firstName: 'Test' }] });
  }
  if (req.method === 'POST' && url.pathname === '/voyager/api/voyagerVideoDashMediaUploadMetadata' && url.searchParams.get('action') === 'upload') {
    const input = JSON.parse(body.toString());
    if (input.mediaUploadType !== 'IMAGE_SHARING' || !(input.fileSize > 0)) return send(res, 400, { message: 'Bad upload metadata' });
    const id = String(uploads.size + 1);
    uploads.set(id, { urn: 'urn:li:digitalmediaAsset:fixture' + id, filename: input.filename, bytes: 0 });
    return send(res, 200, { data: { value: { urn: 'urn:li:digitalmediaAsset:fixture' + id, singleUploadUrl: `http://${req.headers.host}/linkedin/dms-uploads/${id}`, singleUploadHeaders: { 'media-type-family': 'STILLIMAGE' }, type: 'SINGLE' } } });
  }
  if (req.method === 'PUT' && url.pathname.startsWith('/dms-uploads/')) {
    const upload = uploads.get(url.pathname.split('/').at(-1));
    if (!upload || req.headers['media-type-family'] !== 'STILLIMAGE' || body.length === 0) return send(res, 400, { message: 'Bad upload' });
    upload.bytes = body.length;
    return send(res, 201, '');
  }
  if (req.method === 'POST' && url.pathname === '/voyager/api/contentcreation/normShares') {
    const share = JSON.parse(body.toString());
    const text = share.commentaryV2?.text;
    if (typeof text !== 'string' || share.postState !== 'PUBLISHED') return send(res, 422, { message: 'Bad share' });
    for (const item of share.media) {
      const upload = [...uploads.values()].find((entry) => entry.urn === item.mediaUrn);
      if (item.category !== 'IMAGE' || !upload?.bytes) return send(res, 422, { message: 'Unknown media' });
    }
    if (text.startsWith('[simulate:rate-limit]')) return send(res, 429, { message: 'Too many requests' });
    const urn = 'urn:li:share:70000000000000' + String(posts.length + 1).padStart(5, '0');
    posts.push({ urn, text, media: share.media });
    // A post that was saved, but whose answer never arrives intact.
    if (text.startsWith('[simulate:unknown]')) return send(res, 502, 'Bad gateway');
    return send(res, 201, { data: { status: { urn, mainToastText: 'Post successful.' } } });
  }
  return send(res, 501, { error: 'Unmodeled LinkedIn request', method: req.method, path: url.pathname });
};
