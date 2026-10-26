import {bundle} from '@remotion/bundler';
import {renderStill, selectComposition} from '@remotion/renderer';
import fs from 'fs';
import path from 'path';
const scenes = JSON.parse(fs.readFileSync('src/scenes.json', 'utf8'));
const only = process.argv[2] ? process.argv[2].split(',') : null;
const browserExecutable = '/opt/pw-browsers/chromium_headless_shell-1243/chrome-headless-shell-linux64/chrome-headless-shell';
const serveUrl = await bundle({entryPoint: path.resolve('src/index.ts')});
fs.mkdirSync('stills', {recursive: true});
for (const s of scenes) {
  if (only && !only.includes(s.id)) continue;
  const composition = await selectComposition({serveUrl, id: s.id, inputProps: {id: s.id}, browserExecutable});
  for (const [i, t] of s.frames.entries()) {
    const out = `stills/${s.id}-${'AB'[i]}.png`;
    await renderStill({composition, serveUrl, output: out, frame: Math.round(t * 30), inputProps: {id: s.id}, browserExecutable});
    console.log(out);
  }
}
