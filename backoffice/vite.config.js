import react from '@vitejs/plugin-react';
import { defineConfig } from 'vite';

export default defineConfig({
  plugins: [react()],
  publicDir: false,
  server: {
    proxy: {
      '/backoffice': {
        target: 'http://127.0.0.1:8000',
        changeOrigin: true
      }
    }
  }
});
