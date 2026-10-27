// @ts-check
import { defineConfig } from 'astro/config';

// https://astro.build/config
export default defineConfig({
  site: 'https://koamishin.github.io',
  base: process.env.GITHUB_ACTIONS ? '/koamishin-portal' : '/',
});

