# Changelog

## 0.1.0

- First Composer release of `bangnokia/postslop`, with a standalone remote account client runnable through cpx.
- Import Chrome or Firefox sessions directly from the user's computer to their Postslop server; share the browser readers with the full local CLI.
- Check and manage remote accounts without a local backend, database, Node installation, or repository checkout.
- Give each web account its own Check again button, import metadata, check timestamp and useful failure details.
- Recognize dev-null.sh's renamed handle field and distinguish Threads session rejection from redirects and unexpected pages.
- Pin the backend to CrabPHP `^0.1`, with production `start`, watched `dev`, lifecycle shortcuts and per-site publishing queues.
- License Postslop under MIT.

Threads publishing remains experimental and has been tested against fake sites. A rejected real Threads session requires reimporting from the user's signed-in browser.
