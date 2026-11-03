// A stand-in for linkedin.com's web API (Voyager), with a fake account only. Never deploy it.
const { createHash } = require('node:crypto');

// Videos arrive in parts this size unless the scenario asks for a single upload.
const PART_BYTES = 65536;
let posts = [];
let uploads = new Map();
let scenario = '';
const send = (res, code, value, headers = {}) => { res.writeHead(code, { 'Content-Type': 'application/json', ...headers }); res.end(typeof value === 'string' ? value : JSON.stringify(value)); };
const read = async (req) => { const chunks = []; for await (const chunk of req) chunks.push(chunk); return Buffer.concat(chunks); };
const stored = (upload) => ({ urn: upload.urn, filename: upload.filename, kind: upload.kind, bytes: upload.bytes, parts: upload.parts?.length ?? 0, complete: upload.complete, sha256: upload.data ? createHash('sha256').update(upload.data).digest('hex') : null });
module.exports = async (req, res) => {
  const body = await read(req);
  const url = new URL(req.url, 'http://fixture');
  if (req.method === 'POST' && url.pathname === '/__reset') {
    posts = []; uploads = new Map();
    try { scenario = JSON.parse(body.toString() || '{}').scenario ?? ''; } catch { scenario = ''; }
    return send(res, 200, { reset: true, scenario });
  }
  if (req.method === 'GET' && url.pathname === '/__stats') return send(res, 200, { posts, uploads: [...uploads.values()].map(stored) });
  // li_at signs in; JSESSIONID's value, without its quotes, must come back as the csrf-token header.
  if (!req.headers.cookie?.includes('li_at=fixture-li-at') || !req.headers.cookie?.includes('JSESSIONID="ajax:fixture"')) return send(res, 302, '', { Location: '/login' });
  if (req.headers['csrf-token'] !== 'ajax:fixture') return send(res, 403, { status: 403, message: 'CSRF check failed.' });
  if (req.method === 'GET' && url.pathname === '/voyager/api/me') {
    return send(res, 200, { data: { plainId: 1, '*miniProfile': 'urn:li:fs_miniProfile:fixture' }, included: [{ entityUrn: 'urn:li:fs_miniProfile:fixture', publicIdentifier: 'tester', firstName: 'Test' }] });
  }
  if (req.method === 'POST' && url.pathname === '/voyager/api/voyagerVideoDashMediaUploadMetadata' && url.searchParams.get('action') === 'upload') {
    const input = JSON.parse(body.toString());
    if (!['IMAGE_SHARING', 'VIDEO_SHARING'].includes(input.mediaUploadType) || !(input.fileSize > 0)) return send(res, 400, { message: 'Bad upload metadata' });
    const id = String(uploads.size + 1);
    const base = `http://${req.headers.host}/linkedin/dms-uploads/${id}`;
    if (input.mediaUploadType === 'IMAGE_SHARING') {
      uploads.set(id, { urn: 'urn:li:digitalmediaAsset:fixture' + id, filename: input.filename, kind: 'image', bytes: 0, complete: false });
      return send(res, 200, { data: { value: { urn: 'urn:li:digitalmediaAsset:fixture' + id, singleUploadUrl: base, singleUploadHeaders: { 'media-type-family': 'STILLIMAGE' }, type: 'SINGLE' } } });
    }
    if (scenario === 'video-refused') return send(res, 400, { message: 'Video uploads are unavailable' });
    const urn = 'urn:li:digitalmediaAsset:fixtureVideo' + id;
    const artifact = `urn:li:digitalmediaMediaArtifact:(${urn},urn:li:digitalmediaMediaArtifactClass:uploaded-video)`;
    const upload = { urn, artifact, filename: input.filename, kind: 'video', size: input.fileSize, bytes: 0, complete: false, chunks: [], parts: [] };
    uploads.set(id, upload);
    if (scenario === 'single-video') {
      return send(res, 200, { data: { value: { urn, mediaArtifactUrn: artifact, singleUploadUrl: base, singleUploadHeaders: { 'media-type-family': 'VIDEO' }, type: 'SINGLE' } } });
    }
    for (let first = 0; first < input.fileSize; first += PART_BYTES) {
      upload.parts.push({ firstByte: first, lastByte: Math.min(first + PART_BYTES, input.fileSize) - 1 });
    }
    // A gap between parts, or an address off LinkedIn, must stop the upload before any byte is sent.
    if (scenario === 'gapped-parts' && upload.parts.length > 1) upload.parts[1].firstByte += 1;
    const partUploadRequests = upload.parts.map((range, index) => ({
      url: scenario === 'foreign-host' && index === 0 ? `http://example.invalid/dms-uploads/${id}/parts/0` : `${base}/parts/${index}`,
      byteRange: range, headers: { 'Content-Type': 'application/octet-stream' }, urlExpiresAt: Date.now() + 3600000,
    }));
    return send(res, 200, { data: { value: { urn, mediaArtifactUrn: artifact, type: 'MULTIPART', singleUploadUrl: null, multipartMetadata: { partUploadRequests } } } });
  }
  const part = /^\/dms-uploads\/([0-9]+)\/parts\/([0-9]+)$/.exec(url.pathname);
  if (req.method === 'PUT' && part) {
    const upload = uploads.get(part[1]);
    const range = upload?.parts[Number(part[2])];
    if (!range || req.headers['content-type'] !== 'application/octet-stream' || body.length !== range.lastByte - range.firstByte + 1) return send(res, 400, { message: 'Bad part' });
    if (scenario === 'part-fails' && Number(part[2]) === 1) return send(res, 500, { message: 'Part storage failed' });
    upload.chunks[Number(part[2])] = body;
    upload.bytes += body.length;
    return send(res, 200, '', scenario === 'missing-etag' ? {} : { ETag: `"fixture-${part[1]}-${part[2]}"` });
  }
  if (req.method === 'POST' && url.pathname === '/voyager/api/voyagerVideoDashMediaUploadMetadata' && url.searchParams.get('action') === 'completeMultipartUpload') {
    const input = JSON.parse(body.toString());
    const upload = [...uploads.values()].find((entry) => entry.artifact === input.mediaArtifactUrn);
    const answers = input.partUploadResponses;
    if (scenario === 'complete-fails' || !upload || !Array.isArray(answers) || answers.length !== upload.parts.length
      || answers.some((answer, index) => answer?.headers?.ETag !== `"fixture-${[...uploads.keys()].find((key) => uploads.get(key) === upload)}-${index}"` || answer.httpStatusCode !== 200)) {
      return send(res, 400, { message: 'Bad multipart completion' });
    }
    upload.data = Buffer.concat(upload.chunks);
    upload.complete = upload.data.length === upload.size;
    return send(res, upload.complete ? 200 : 400, upload.complete ? {} : { message: 'Missing parts' });
  }
  if (req.method === 'PUT' && /^\/dms-uploads\/[0-9]+$/.test(url.pathname)) {
    const upload = uploads.get(url.pathname.split('/').at(-1));
    const family = upload?.kind === 'video' ? 'VIDEO' : 'STILLIMAGE';
    if (!upload || req.headers['media-type-family'] !== family || body.length === 0) return send(res, 400, { message: 'Bad upload' });
    upload.bytes = body.length;
    if (upload.kind === 'video') {
      upload.data = body;
      upload.complete = body.length === upload.size;
    }
    return send(res, 201, '');
  }
  if (req.method === 'POST' && url.pathname === '/voyager/api/contentcreation/normShares') {
    const share = JSON.parse(body.toString());
    const text = share.commentaryV2?.text;
    if (typeof text !== 'string' || share.postState !== 'PUBLISHED') return send(res, 422, { message: 'Bad share' });
    for (const item of share.media) {
      const upload = [...uploads.values()].find((entry) => entry.urn === item.mediaUrn);
      const ready = item.category === 'IMAGE' ? upload?.kind === 'image' && upload.bytes : item.category === 'VIDEO' && upload?.kind === 'video' && upload.complete;
      if (!ready) return send(res, 422, { message: 'Unknown media' });
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
