import { defineConfig } from "vite";
import react from "@vitejs/plugin-react";
import tailwindcss from "@tailwindcss/vite";
import fs from "node:fs";
import path from "node:path";
import { fileURLToPath } from "node:url";

const rootDir = path.dirname(fileURLToPath(import.meta.url));

// public/ also holds api/, uploads/ and .htaccess, so emptyOutDir must stay off.
// Only the hashed bundle folder is cleared, which keeps stale builds out of the
// served site and the cloud release ZIP.
function cleanHashedAssets() {
  return {
    name: "nearby-clean-hashed-assets",
    apply: "build",
    buildStart() {
      fs.rmSync(path.join(rootDir, "public", "assets"), {
        recursive: true,
        force: true,
      });
    },
  };
}

export default defineConfig({
  base: "./",
  publicDir: "static",
  plugins: [react(), tailwindcss(), cleanHashedAssets()],
  build: {
    outDir: "public",
    emptyOutDir: false,
    rolldownOptions: {
      output: {
        // React and the router change rarely; a separate chunk stays cached
        // across application releases.
        codeSplitting: {
          groups: [
            {
              name: "vendor",
              test: /node_modules[\\/](react|react-dom|react-router|react-router-dom|scheduler)[\\/]/,
            },
          ],
        },
      },
    },
  },
  server: {
    port: 5174,
    strictPort: true,
    cors: false,
    // Preserve the browser-facing Host so recovery can verify same-origin POSTs.
    proxy: { "/api": { target: "http://127.0.0.1:8081", changeOrigin: false } },
  },
});
