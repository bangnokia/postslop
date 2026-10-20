import { resolve } from "node:path";
import react from "@vitejs/plugin-react";
import { defineConfig } from "vite";

const server = resolve(import.meta.dirname, "../../server");
const api = process.env.POSTSLOP_API ?? "http://127.0.0.1:8087";

export default defineConfig({
  plugins: [react()],
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
      },
    },
  },
});
