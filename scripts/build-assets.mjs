// Build degli asset in public/assets: CSS (Tailwind), JS (esbuild + Alpine CSP), font self-hosted.
import { execFileSync } from 'node:child_process';
import { mkdirSync, copyFileSync, existsSync } from 'node:fs';
import { build, context } from 'esbuild';

const out = 'public/assets';
const watch = process.argv.includes('--watch');
mkdirSync(`${out}/fonts`, { recursive: true });

// Font: solo latin, i pesi usati.
const fonts = [
  ['inter', 400], ['inter', 500], ['inter', 600], ['inter', 700],
  ['archivo', 600], ['archivo', 700], ['archivo', 800],
];
for (const [family, weight] of fonts) {
  const src = `node_modules/@fontsource/${family}/files/${family}-latin-${weight}-normal.woff2`;
  if (!existsSync(src)) throw new Error(`Font mancante: ${src}`);
  copyFileSync(src, `${out}/fonts/${family}-${weight}.woff2`);
}

const js = { entryPoints: ['assets/js/app.js'], bundle: true, minify: true, format: 'iife', target: 'es2019', outfile: `${out}/app.js`, legalComments: 'none' };
const tw = ['tailwindcss', '-i', 'assets/css/app.css', '-o', `${out}/app.css`, '--minify'];

if (watch) {
  const ctx = await context(js);
  await ctx.watch();
  execFileSync('npx', [...tw, '--watch'], { stdio: 'inherit' });
} else {
  await build(js);
  execFileSync('npx', tw, { stdio: 'inherit' });
  console.log('Asset pronti in public/assets');
}
