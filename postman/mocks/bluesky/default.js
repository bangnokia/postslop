const http = require('http');
const send = (res, code, value) => { res.writeHead(code, { 'Content-Type': 'application/json' }); res.end(JSON.stringify(value)); };
const read = async (req) => {
  if (req.body !== undefined) return req.body;
  const chunks = []; for await (const chunk of req) chunks.push(chunk);
  const raw = Buffer.concat(chunks);
  return req.headers['content-type']?.includes('application/json') ? (raw.length ? JSON.parse(raw.toString()) : {}) : raw;
};
http.createServer(async (req, res) => {
  const bodyPromise = read(req);
  const url = new URL(req.url, 'http://fixture');
  const body = await bodyPromise;
  const posts = await pm.state.get('bluesky.posts') || [];
  const blobs = await pm.state.get('bluesky.blobs') || [];
  if (req.method === 'POST' && url.pathname === '/__reset') {
    await pm.state.set('bluesky.posts', []); await pm.state.set('bluesky.blobs', []);
    return send(res, 200, { reset: true });
  }
  if (req.method === 'GET' && url.pathname === '/__stats') return send(res, 200, { posts, blobs });
  if (req.headers.authorization !== 'Bearer fixture-access') return send(res, 401, { error: 'ExpiredToken' });
  if (req.method === 'GET' && url.pathname === '/xrpc/com.atproto.server.getSession') return send(res, 200, { did: 'did:plc:fixture', handle: 'tester.test', active: true });
  if (req.method === 'POST' && url.pathname === '/xrpc/com.atproto.repo.uploadBlob') {
    const mimeType = req.headers['content-type'];
    if (!['image/png','image/jpeg','image/webp'].includes(mimeType)) return send(res, 400, { error: 'InvalidMimeType' });
    const blob = { $type: 'blob', ref: { $link: 'bafyfixture' + blobs.length }, mimeType, size: Buffer.isBuffer(body) ? body.length : String(body).length };
    blobs.push(blob); await pm.state.set('bluesky.blobs', blobs);
    return send(res, 200, { blob });
  }
  if (req.method === 'POST' && url.pathname === '/xrpc/com.atproto.repo.createRecord') {
    if (body.repo !== 'did:plc:fixture' || body.collection !== 'app.bsky.feed.post' || body.record?.$type !== 'app.bsky.feed.post') return send(res, 400, { error: 'InvalidRecord' });
    const images = body.record.embed?.images || [];
    if (images.some(image => !blobs.some(blob => blob.ref.$link === image.image?.ref?.$link) || typeof image.alt !== 'string')) return send(res, 400, { error: 'BlobNotFound' });
    const key = '3ltest23456' + String.fromCharCode(97 + Math.floor(posts.length / 26)) + String.fromCharCode(97 + posts.length % 26);
    const uri = 'at://did:plc:fixture/app.bsky.feed.post/' + key;
    posts.push({ uri, record: body.record }); await pm.state.set('bluesky.posts', posts);
    return send(res, 200, { uri, cid: 'bafyfixturepost' + posts.length });
  }
  return send(res, 501, { error: 'Unmodeled Bluesky website request', method: req.method, path: url.pathname });
}).listen(process.env.PORT);
