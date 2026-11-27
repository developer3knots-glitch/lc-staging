import fs from 'fs';
import path from 'path';
import { fileURLToPath } from 'url';

const __dirname = path.dirname(fileURLToPath(import.meta.url));
const srcRoot = path.join(__dirname, 'src');
fs.mkdirSync(srcRoot, { recursive: true });

const raw = fs.readFileSync(path.join(__dirname, 'embed-app.js'), 'utf8');
const si = raw.indexOf('var LC_WIDGET_BUILD');
const ei = raw.lastIndexOf('})();');
if (si < 0 || ei < 0) {
  console.error('markers missing', si, ei);
  process.exit(1);
}

const body = raw.slice(si, ei).replace(/\s+$/, '');
const lines = body.split(/\n/);
console.log('body lines', lines.length);

// Body starts at original line 10 (1-based).
function atOrig(L) {
  return L - 10;
}

const parts = [
  ['01-boot-core.js', 10, 435, 'Boot, flags, identity, navigation helpers'],
  ['02-presence.js', 436, 1203, 'Page tracker, presence, Left website'],
  ['03-widget-dom.js', 1204, 2013, 'buildWidget DOM, storage, maximize'],
  ['04-screens.js', 2014, 3540, 'Resume, prechat, idle, settings sync helpers'],
  ['05-realtime.js', 3541, 4551, 'WS handlers, message.new, conversation.updated'],
  ['06-routing.js', 4552, 5281, 'Entry routing, start chat, prechat submit'],
  ['07-panel-lifecycle.js', 5282, 7151, 'Panel open/close, ended, new chat, initChat'],
  ['08-chat-ui.js', 7152, 8120, 'Waiting, typing, avatars, reactions'],
  ['09-messages.js', 8121, 9102, 'appendMessage, poll, attachments'],
  ['10-send-boot.js', 9103, 9450, 'Send path + bootWidget'],
];

const manifest = [];
for (const [name, from, to, desc] of parts) {
  const a = atOrig(from);
  const b = atOrig(to);
  const chunk = lines.slice(a, b + 1).join('\n') + '\n';
  const header = `/* LC widget module: ${name} — ${desc} */\n`;
  fs.writeFileSync(path.join(srcRoot, name), header + chunk);
  manifest.push({
    file: name,
    from,
    to,
    desc,
    lines: chunk.split('\n').length,
  });
  console.log(name, `orig ${from}-${to}`, 'outLines', chunk.split('\n').length);
}

fs.writeFileSync(path.join(srcRoot, 'modules.json'), JSON.stringify(manifest, null, 2));
console.log('split done → widget/src/');
