import { defineConfig } from 'vite';
import vue from '@vitejs/plugin-vue';

export default defineConfig({
  plugins: [vue()],
  define: { 'process.env.NODE_ENV': '"production"' },
  esbuild: { pure: ['console.log'] },
  build: {
    outDir: '../assets/dist',
    emptyOutDir: true,
    lib: { entry: 'src/index.js', name: 'wmWpMap', formats: ['iife'], fileName: () => 'wp-package-map.js' },
    rollupOptions: { output: { assetFileNames: 'wp-package-map[extname]' } }
  }
});
