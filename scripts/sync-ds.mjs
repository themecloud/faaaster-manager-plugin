#!/usr/bin/env node
/**
 * Synchronise le design system « Calm Grid » de Next dans le module cache.
 *
 *   node scripts/sync-ds.mjs [chemin/vers/next]   (défaut : ../next/next)
 *
 * Next reste la source de vérité. Le script extrait UNIQUEMENT les primitives
 * utilisées par la page « Cache Faaaster », les préfixe sous `.fstr-ds` (pour
 * ne pas déborder sur le reste de wp-admin), écarte le thème sombre (wp-admin
 * n'en a pas), et génère les tracés d'icônes lucide utilisés. Fichiers générés :
 *   assets/ds/tokens.css, assets/ds/components.css, class/cache/admin/ds-icons.php
 */
import { readFileSync, writeFileSync, mkdirSync } from 'node:fs';
import { execSync } from 'node:child_process';
import { dirname, join, resolve } from 'node:path';
import { fileURLToPath } from 'node:url';

const root = resolve(dirname(fileURLToPath(import.meta.url)), '..');
const next = resolve(process.argv[2] || join(root, '..', 'next', 'next'));
const SCOPE = '.fstr-ds';

const PRIMITIVES = [
  'page-h', 'section-h', 'eyebrow', 'card', 'card-h', 'card-b', 'kv', 'set-row', 'seg',
  'switch', 'field', 'txt', 'help', 'btn', 'icon-btn', 'link', 'badge', 'sdot', 'alert',
  'meter', 'spinner', 'table', 'pager', 'code', 'mono', 'toolbar',
  // Variantes définies comme classes autonomes.
  'btn-primary', 'btn-secondary', 'btn-ghost', 'btn-danger', 'btn-accent', 'btn-warn', 'btn-sm',
  'alert-info', 'alert-warn', 'alert-danger',
];
const ICONS = [
  'zap', 'refresh-cw', 'trash-2', 'check', 'circle-check', 'triangle-alert', 'circle-alert',
  'info', 'external-link', 'gauge', 'activity', 'plug', 'list-tree', 'scroll-text', 'search',
  'settings', 'x', 'shield-check',
];

let sha = 'inconnu';
try {
  sha = execSync('git rev-parse --short HEAD', { cwd: next }).toString().trim();
} catch {}
const header = (src) =>
  `/* GÉNÉRÉ par scripts/sync-ds.mjs depuis next@${sha} (${src}) — NE PAS ÉDITER. */\n`;

// ---- Mini analyseur CSS : blocs de premier niveau, @-règles imbriquées ----
function stripComments(css) {
  return css.replace(/\/\*[\s\S]*?\*\//g, '');
}

function parseBlocks(css) {
  const blocks = [];
  let i = 0;
  while (i < css.length) {
    const open = css.indexOf('{', i);
    if (open === -1) break;
    const prelude = css.slice(i, open).trim();
    let depth = 1;
    let j = open + 1;
    while (j < css.length && depth > 0) {
      if (css[j] === '{') depth++;
      else if (css[j] === '}') depth--;
      j++;
    }
    blocks.push({ prelude, body: css.slice(open + 1, j - 1) });
    i = j;
  }
  return blocks;
}

function splitSelectors(prelude) {
  const out = [];
  let depth = 0;
  let cur = '';
  for (const ch of prelude) {
    if (ch === '(' || ch === '[') depth++;
    if (ch === ')' || ch === ']') depth--;
    if (ch === ',' && depth === 0) {
      out.push(cur.trim());
      cur = '';
    } else cur += ch;
  }
  if (cur.trim()) out.push(cur.trim());
  return out;
}

const primitiveRe = new RegExp(`^\\.(?:${PRIMITIVES.map((p) => p.replace(/-/g, '\\-')).join('|')})(?![\\w-])`);
const usedKeyframes = new Set();

function keepRule(prelude, body) {
  if (prelude.includes('data-theme')) return null;
  const kept = splitSelectors(prelude).filter((s) => primitiveRe.test(s));
  if (!kept.length) return null;
  for (const m of body.matchAll(/animation(?:-name)?\s*:\s*([\w-]+)/g)) usedKeyframes.add(m[1]);
  return `${kept.map((s) => `${SCOPE} ${s}`).join(',\n')} {${body}}\n`;
}

function extract(css) {
  let out = '';
  const keyframes = [];
  for (const { prelude, body } of parseBlocks(stripComments(css))) {
    if (prelude.startsWith('@keyframes')) {
      keyframes.push({ name: prelude.split(/\s+/)[1], text: `${prelude} {${body}}\n` });
    } else if (prelude.startsWith('@media') || prelude.startsWith('@supports')) {
      const inner = parseBlocks(body)
        .map((b) => keepRule(b.prelude, b.body))
        .filter(Boolean)
        .join('');
      if (inner) out += `${prelude} {\n${inner}}\n`;
    } else if (!prelude.startsWith('@')) {
      const rule = keepRule(prelude, body);
      if (rule) out += rule;
    }
  }
  for (const k of keyframes) if (usedKeyframes.has(k.name)) out += k.text;
  return out;
}

// ---- Tokens : seul le bloc :root (thème clair), porté sur .fstr-ds ----
const tokensCss = stripComments(readFileSync(join(next, 'styles/ds/tokens.css'), 'utf8'));
const rootBlock = parseBlocks(tokensCss).find((b) => b.prelude === ':root');
if (!rootBlock) throw new Error(':root introuvable dans styles/ds/tokens.css');
// Polices : aucun fichier embarqué ni appel à Google Fonts depuis wp-admin. On
// garde les familles du DS (si elles sont installées sur le poste) et on se
// replie sur la pile standard du tableau de bord WordPress (common.css).
const WP_UI_STACK = '-apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Oxygen-Sans, Ubuntu, Cantarell, "Helvetica Neue", sans-serif';
const WP_MONO_STACK = 'Consolas, Monaco, monospace';
function withWpFallback(value, stack) {
  const named = value.split(',').map((f) => f.trim()).filter((f) => /^['"]/.test(f));
  return [...named, stack].join(', ');
}
const rootBody = rootBlock.body.replace(/(--font-(ui|display|mono)\s*:\s*)([^;]+);/g, (_, prop, kind, value) =>
  `${prop}${withWpFallback(value, kind === 'mono' ? WP_MONO_STACK : WP_UI_STACK)};`);
const tokens = header('styles/ds/tokens.css') + `${SCOPE} {${rootBody}}\n`;

const components = header('styles/ds/components.css') +
  extract(readFileSync(join(next, 'styles/ds/components.css'), 'utf8'));

// ---- Icônes : fragments JSX de components/ds/icons.tsx → SVG ----
const iconsSrc = readFileSync(join(next, 'components/ds/icons.tsx'), 'utf8');
const icons = {};
for (const name of ICONS) {
  const key = name.includes('-') ? `"${name}"` : name;
  // Deux formes : `name: (\n <>…</>\n ),` ou, pour un seul tracé, `name: <path … />,`
  const multi = iconsSrc.indexOf(`\n  ${key}: (`);
  const single = iconsSrc.indexOf(`\n  ${key}: <`);
  if (multi !== -1) {
    const end = iconsSrc.indexOf('\n  ),', multi);
    icons[name] = iconsSrc.slice(multi, end).replace(/^[\s\S]*?\(/, '').replace(/<\/?>/g, '');
  } else if (single !== -1) {
    const end = iconsSrc.indexOf('/>,', single);
    icons[name] = iconsSrc.slice(single, end + 2).replace(/^[\s\S]*?: /, '');
  } else {
    throw new Error(`icône absente de icons.tsx : ${name}`);
  }
  icons[name] = icons[name].replace(/\s+/g, ' ').trim();
  if (/[{}]/.test(icons[name])) throw new Error(`icône non statique (JSX dynamique) : ${name}`);
}
const php = `<?php

/* GÉNÉRÉ par scripts/sync-ds.mjs depuis next@${sha} (components/ds/icons.tsx) — NE PAS ÉDITER. */

/** Icône lucide du DS Faaaster (tracés de components/ds/icons.tsx). */
function faaaster_ds_icon($name, $size = 16)
{
    static $paths = ${JSON.stringify(icons, null, 4).replace(/\n/g, '\n    ').replace(/"([^"]+)":/g, "'$1' =>").replace(/^\{/, 'array(').replace(/\}$/, ')')};
    if (!isset($paths[$name])) {
        return '';
    }
    return '<svg class="lucide" xmlns="http://www.w3.org/2000/svg" width="' . (int) $size . '" height="' . (int) $size
        . '" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"'
        . ' stroke-linejoin="round" aria-hidden="true">' . $paths[$name] . '</svg>';
}
`;

mkdirSync(join(root, 'assets/ds'), { recursive: true });
writeFileSync(join(root, 'assets/ds/tokens.css'), tokens);
writeFileSync(join(root, 'assets/ds/components.css'), components);
writeFileSync(join(root, 'class/cache/admin/ds-icons.php'), php);
console.log(`DS synchronisé depuis next@${sha} : ${components.split('\n').length} lignes de composants, ${ICONS.length} icônes.`);
