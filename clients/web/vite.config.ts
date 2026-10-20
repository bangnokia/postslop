import { readFileSync } from "node:fs";
import { resolve } from "node:path";
import react from "@vitejs/plugin-react";
import { defineConfig, type Plugin } from "vite";

const server = resolve(import.meta.dirname, "../../server");
const api = process.env.POSTSLOP_API ?? "http://127.0.0.1:8087";

// In development the page comes from Vite, so it reads the local token the
// PHP server would otherwise put into index.html. The token never leaves this Mac.
function localToken(): Plugin {
  return {
    name: "postslop-local-token",
    apply: "serve",
    transformIndexHtml(html) {
      const token = readFileSync(`${server}/storage/client-token`, "utf8").trim();
      return html.replace("__POSTSLOP_TOKEN__", token);
    },
  };
}

export default defineConfig({
  plugins: [react(), localToken()],
  build: {
    // The PHP server serves the built client from server/public.
    outDir: `${server}/public`,
    emptyOutDir: true,
  },
  server: {
    host: "127.0.0.1",
    proxy: {
      "/api": {
        target: api,
        changeOrigin: true,
        // The API accepts only its own origin; the dev page speaks for it here.
        headers: { Origin: api },
      },
    },
  },
});
