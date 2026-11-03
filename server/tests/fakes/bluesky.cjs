// A stand-in for the site's web requests, with fake accounts only. Never deploy it.
// It also stands in for Bluesky's video service: service auth on the PDS, uploadVideo and getJobStatus.
const { createHash } = require('node:crypto');
const values = new Map();
const state = { get: async (key) => values.get(key), set: async (key, value) => { values.set(key, value); } };
const send = (res, code, value) => { res.writeHead(code, { 'Content-Type': 'application/json' }); res.end(JSON.stringify(value)); };
const read = async (req) => {
  if (req.body !== undefined) return req.body;
  const chunks = []; for await (const chunk of req) chunks.push(chunk);
  const raw = Buffer.concat(chunks);
  return req.headers['content-type']?.includes('application/json') ? (raw.length ? JSON.parse(raw.toString()) : {}) : raw;
};
module.exports = async (req, res) => {
  const bodyPromise = read(req);
  const url = new URL(req.url, 'http://fixture');
  const body = await bodyPromise;
  const posts = await state.get('bluesky.posts') || [];
  const blobs = await state.get('bluesky.blobs') || [];
  const uploads = await state.get('bluesky.uploads') || [];
  const authorizations = await state.get('bluesky.authorizations') || [];
  const jobs = await state.get('bluesky.jobs') || {};
  const scenario = await state.get('bluesky.scenario') || '';
  if (req.method === 'POST' && url.pathname === '/__reset') {
    await state.set('bluesky.posts', []); await state.set('bluesky.blobs', []);
    await state.set('bluesky.uploads', []); await state.set('bluesky.authorizations', []); await state.set('bluesky.jobs', {});
    await state.set('bluesky.scenario', typeof body?.scenario === 'string' ? body.scenario : '');
    return send(res, 200, { reset: true });
  }
  if (req.method === 'GET' && url.pathname === '/__stats') {
    const polls = Object.values(jobs).reduce((sum, job) => sum + job.polls, 0);
    return send(res, 200, { posts, blobs, uploads, authorizations, polls });
  }
  // The video service takes the PDS's service token for uploads, and job checks need no token.
  if (req.method === 'POST' && url.pathname === '/xrpc/app.bsky.video.uploadVideo') {
    if (req.headers.authorization !== 'Bearer fixture-service-token') return send(res, 401, { error: 'AuthMissing' });
    const did = url.searchParams.get('did');
    const name = url.searchParams.get('name');
    const mimeType = req.headers['content-type'];
    if (did !== 'did:plc:fixture' || !name || !['video/mp4', 'video/quicktime'].includes(mimeType)
      || !Buffer.isBuffer(body) || body.toString('ascii', 4, 8) !== 'ftyp') return send(res, 400, { error: 'InvalidRequest' });
    uploads.push({ did, name, mimeType, size: body.length, sha256: createHash('sha256').update(body).digest('hex') });
    await state.set('bluesky.uploads', uploads);
    // The message repeats the token, so a test can prove the publisher never echoes the service's words.
    if (scenario === 'video-refused') return send(res, 400, { error: 'unconfirmed_email', message: 'Confirm your email first (fixture-service-token)' });
    const jobId = 'fixture-job-' + uploads.length;
    jobs[jobId] = { polls: 0, size: body.length };
    await state.set('bluesky.jobs', jobs);
    if (scenario === 'video-exists') return send(res, 409, { error: 'already_exists', message: 'This video was uploaded before', jobId });
    const job = { did, jobId, state: 'JOB_STATE_CREATED' };
    return send(res, 200, scenario === 'video-lexicon' ? { jobStatus: job } : job);
  }
  if (req.method === 'GET' && url.pathname === '/xrpc/app.bsky.video.getJobStatus') {
    const jobId = url.searchParams.get('jobId');
    const job = jobs[jobId];
    if (!job) return send(res, 404, { error: 'NotFound' });
    job.polls += 1;
    await state.set('bluesky.jobs', jobs);
    const status = { jobId, did: 'did:plc:fixture' };
    if (scenario === 'video-failed') return send(res, 200, { jobStatus: { ...status, state: 'JOB_STATE_FAILED', error: 'Encoding failed' } });
    // One processing step comes before completion, so the publisher has to wait for it.
    if (scenario === 'video-stuck' || job.polls < 2) return send(res, 200, { jobStatus: { ...status, state: 'JOB_STATE_ENCODING', progress: 50 } });
    const blob = { $type: 'blob', ref: { $link: 'bafyvideo' + jobId.replace(/[^a-z0-9]/g, '') }, mimeType: 'video/mp4', size: job.size };
    if (!blobs.some((known) => known.ref.$link === blob.ref.$link)) { blobs.push(blob); await state.set('bluesky.blobs', blobs); }
    return send(res, 200, { jobStatus: { ...status, state: 'JOB_STATE_COMPLETED', progress: 100, blob } });
  }
  if (req.headers.authorization !== 'Bearer fixture-access') return send(res, 401, { error: 'ExpiredToken' });
  if (req.method === 'GET' && url.pathname === '/xrpc/com.atproto.server.getSession') return send(res, 200, { did: 'did:plc:fixture', handle: 'tester.test', active: true });
  if (req.method === 'GET' && url.pathname === '/xrpc/com.atproto.server.getServiceAuth') {
    const authorization = { aud: url.searchParams.get('aud'), lxm: url.searchParams.get('lxm'), exp: Number(url.searchParams.get('exp')) };
    authorizations.push(authorization); await state.set('bluesky.authorizations', authorizations);
    const now = Date.now() / 1000;
    if (authorization.aud !== `did:web:${req.headers.host}` || authorization.lxm !== 'com.atproto.repo.uploadBlob'
      || !(authorization.exp > now && authorization.exp <= now + 3600)) return send(res, 400, { error: 'InvalidRequest' });
    return send(res, 200, { token: 'fixture-service-token' });
  }
  if (req.method === 'POST' && url.pathname === '/xrpc/com.atproto.repo.uploadBlob') {
    const mimeType = req.headers['content-type'];
    if (!['image/png','image/jpeg','image/webp'].includes(mimeType)) return send(res, 400, { error: 'InvalidMimeType' });
    const blob = { $type: 'blob', ref: { $link: 'bafyfixture' + blobs.length }, mimeType, size: Buffer.isBuffer(body) ? body.length : String(body).length };
    blobs.push(blob); await state.set('bluesky.blobs', blobs);
    return send(res, 200, { blob });
  }
  if (req.method === 'POST' && url.pathname === '/xrpc/com.atproto.repo.createRecord') {
    if (body.repo !== 'did:plc:fixture' || body.collection !== 'app.bsky.feed.post' || body.record?.$type !== 'app.bsky.feed.post') return send(res, 400, { error: 'InvalidRecord' });
    const images = body.record.embed?.images || [];
    if (images.some(image => !blobs.some(blob => blob.ref.$link === image.image?.ref?.$link) || typeof image.alt !== 'string')) return send(res, 400, { error: 'BlobNotFound' });
    const embed = body.record.embed;
    if (embed?.$type === 'app.bsky.embed.video') {
      const ratio = embed.aspectRatio;
      if (!blobs.some((blob) => blob.ref.$link === embed.video?.ref?.$link && blob.mimeType === 'video/mp4')
        || !Number.isInteger(ratio?.width) || ratio.width < 1 || !Number.isInteger(ratio?.height) || ratio.height < 1
        || (embed.alt !== undefined && typeof embed.alt !== 'string')) return send(res, 400, { error: 'BlobNotFound' });
    }
    const key = '3ltest23456' + String.fromCharCode(97 + Math.floor(posts.length / 26)) + String.fromCharCode(97 + posts.length % 26);
    const uri = 'at://did:plc:fixture/app.bsky.feed.post/' + key;
    posts.push({ uri, record: body.record }); await state.set('bluesky.posts', posts);
    return send(res, 200, { uri, cid: 'bafyfixturepost' + posts.length });
  }
  return send(res, 501, { error: 'Unmodeled Bluesky website request', method: req.method, path: url.pathname });
};
