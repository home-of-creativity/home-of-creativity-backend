import { defineConfig } from "vite";
import react from "@vitejs/plugin-react";

const apiProxyTarget = process.env.VITE_API_PROXY ?? "http://127.0.0.1:8000";
const apiProxy = {
  "/api": {
    target: apiProxyTarget,
    changeOrigin: true,
    timeout: 180_000,
    proxyTimeout: 180_000,
  },
};

export default defineConfig({
  base: "/dashboard/",
  appType: "spa",
  plugins: [react()],
  css: {
    postcss: {
      plugins: [],
    },
  },
  build: {
    rollupOptions: {
      output: {
        manualChunks: {
          charts: ["recharts"],
          motion: ["framer-motion"],
          radix: ["@radix-ui/react-switch", "@radix-ui/react-tabs", "@radix-ui/react-tooltip", "@radix-ui/react-popover"],
          lottie: ["lottie-react"],
          forms: ["react-hook-form", "@hookform/resolvers", "zod"],
          pickers: ["react-day-picker", "date-fns", "react-dropzone"],
          command: ["cmdk"],
          toast: ["sonner"],
          skeleton: ["react-loading-skeleton"],
        },
      },
    },
  },
  server: {
    host: "0.0.0.0",
    port: 5173,
    allowedHosts: true,
    proxy: apiProxy,
  },
  preview: {
    host: "0.0.0.0",
    port: 5173,
    allowedHosts: true,
    proxy: apiProxy,
  },
});
