const http = require('node:http');
// Local fixture contract matches browser-worker/test/fixture.mjs. Never deploy this mock.
function xFixture({ handle = 'bangnokia', failure = null, prefix = '' } = {}) {
  const posts = [];
  const handler = async (request, response) => {
    const path = new URL(request.url, 'http://localhost').pathname.slice(prefix.length);
    if (path === '/home') {
      response.setHeader('Content-Type', 'text/html');
      response.end(`<a data-testid="AppTabBar_Profile_Link" href="/${handle}">Profile</a>${failure === 'challenge' ? '<div role="dialog"><h1>Verify your identity</h1><p>Account check required.</p></div>' : ''}`);
      return;
    }
    if (path === '/compose/post') {
      response.setHeader('Content-Type', 'text/html');
      response.end(`<!doctype html><html><body>
        <section aria-label="Home composer">
          <textarea data-testid="tweetTextarea_0"></textarea><input type="file">
          <button data-testid="tweetButton">Post</button>
        </section>
        <div role="dialog" aria-label="Post composer" id="composer">
        ${failure === 'ambiguous-composer' ? '<textarea data-testid="tweetTextarea_0"></textarea>' : ''}
        <div contenteditable="true" role="textbox" aria-label="Post text" data-testid="tweetTextarea_0" style="min-height:60px; white-space:pre-wrap"></div>
        <div id="placeholder">What's happening?</div>
        <input type="file" accept="image/*" multiple hidden>
        <div data-testid="attachments" hidden></div>
        <a href="${prefix}/compose/post/media" id="description" hidden>Add description</a>
        <div id="editor" role="dialog" hidden>
          <label>Description<textarea id="alt"></textarea></label>
          <button id="save">Save</button>
        </div>
        <button data-testid="tweetButton">Post</button>
        </div>
        <script>
          let files = [], activeImage = 0, alts = [];
          const composer = document.querySelector('#composer');
          const editable = composer.querySelector('[contenteditable="true"]');
          editable.oninput = () => { document.querySelector('#placeholder').hidden = Boolean(editable.textContent); };
          const attachments = composer.querySelector('[data-testid="attachments"]');
          const editor = document.querySelector('#editor');
          const alt = document.querySelector('#alt');
          function edit(index) { activeImage = index; alt.value = alts[index] || ''; editor.hidden = false; }
          composer.querySelector('input').onchange = (event) => {
            files = Array.from(event.target.files); attachments.hidden = false;
            document.querySelector('#description').hidden = false;
            attachments.replaceChildren(...files.map((file, index) => {
              const editButton = document.createElement('button');
              editButton.textContent = 'Edit media'; editButton.onclick = () => edit(index);
              return editButton;
            }));
          };
          document.querySelector('#description').onclick = (event) => { event.preventDefault(); edit(0); };
          document.querySelector('#save').onclick = () => { alts[activeImage] = alt.value; editor.hidden = true; };
          composer.querySelector('[data-testid="tweetButton"]').onclick = async () => {
            const text = composer.querySelector('[data-testid="tweetTextarea_0"]').innerText;
            const images = await Promise.all(files.map(async (file, index) => ({
              filename: file.name, mimeType: file.type, bytes: file.size,
              data: Array.from(new Uint8Array(await file.arrayBuffer())), alt: alts[index] || ''
            })));
            const variables = {
              tweet_text: ${JSON.stringify(failure)} === 'mismatched-text' ? 'Unexpected text' : text,
              media: { media_entities: ${JSON.stringify(failure)} === 'mismatched-media' ? [] : images.map((_, index) => ({ media_id: String(index + 1), tagged_users: [] })) }
            };
            const send = () => fetch('${prefix}/i/api/graphql/fixture/CreateTweet', {
              method: 'POST', headers: { 'Content-Type': 'application/json' },
              body: JSON.stringify({ variables, fixtureImages: images })
            });
            if (${JSON.stringify(failure)} === 'application-retry') send().then(() => send()).catch(() => {});
            else send().catch(() => {});
          };
        </script></body></html>`);
      return;
    }
    if (path === '/i/api/graphql/fixture/CreateTweet' && request.method === 'POST') {
      let raw = '';
      for await (const chunk of request) raw += chunk;
      const body = JSON.parse(raw);
      posts.push({ text: body.variables.tweet_text, images: body.fixtureImages });
      if (failure === 'lost-response') {
        response.destroy();
        return;
      }
      if (failure === 'partial-response') {
        response.writeHead(200, { 'Content-Type': 'application/json', 'Content-Length': '10000' });
        response.write('{"data":');
        setTimeout(() => response.destroy(), 50);
        return;
      }
      response.setHeader('Content-Type', 'application/json');
      if (failure === 'rejected' || failure === 'duplicate' || failure === 'application-retry') {
        response.end(JSON.stringify({ errors: [{ code: failure === 'duplicate' ? 187 : 226, message: 'Test rejection' }] }));
        return;
      }
      if (failure === 'server-error') {
        response.statusCode = 503;
        response.end(JSON.stringify({ errors: [{ code: 131, message: 'Test server error' }] }));
        return;
      }
      response.end(JSON.stringify({ data: { create_tweet: { tweet_results: { result: {
        rest_id: String(100000 + posts.length),
        legacy: { full_text: posts.at(-1).text, extended_entities: { media: posts.at(-1).images.map((image) => ({ type: 'photo', ext_alt_text: image.alt })) } },
        core: { user_results: { result: { legacy: { screen_name: handle } } } },
      } } } } }));
      return;
    }
    response.statusCode = 404;
    response.end();
  };
  return { handler, posts };
}

const fixture = xFixture({ handle: 'tester', prefix: '/x' });
http.createServer(async (request, response) => {
  try {
    const website = fixture;
    if (request.url === '/__stats') {
      response.setHeader('Content-Type', 'application/json');
      response.end(JSON.stringify({ posts: website.posts }));
      return;
    }
    const source = {
      url: '/x' + request.url,
      method: request.method,
      headers: request.headers,
      async *[Symbol.asyncIterator]() {
        if (request.body !== undefined) yield JSON.stringify(request.body);
        else for await (const chunk of request) yield chunk;
      },
    };
    await website.handler(source, response);
  } catch {
    response.writeHead(501, { 'Content-Type': 'application/json' });
    response.end(JSON.stringify({ error: 'The local X browser fixture could not serve this request.' }));
  }
}).listen(process.env.PORT);
