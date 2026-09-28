import { defineConfig } from 'vite'
import react from '@vitejs/plugin-react'

export default defineConfig({
  plugins: [react()],
  /* Deployed under an Apache sub-path (e.g. /lake-shore-id-editor/).
   * Relative base keeps /assets/* and public files resolvable. */
  base: "./",
})
