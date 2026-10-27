import { defineConfig } from "vite";
import react from "@vitejs/plugin-react";
import tailwindcss from "@tailwindcss/vite";
export default defineConfig({
  base: "./",
  publicDir: "static",
  plugins: [react(), tailwindcss()],
  build: { outDir: "public", emptyOutDir: false },
  server: {
    port: 5174,
    strictPort: true,
    cors: false,
    // Preserve the browser-facing Host so recovery can verify same-origin POSTs.
    proxy: { "/api": { target: "http://127.0.0.1:8081", changeOrigin: false } },
  },
});
