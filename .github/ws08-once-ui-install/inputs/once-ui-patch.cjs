'use strict';
const fs = require('node:fs');
const path = require('node:path');
const crypto = require('node:crypto');
const {spawnSync} = require('node:child_process');
const root = process.cwd();
const mode = process.argv[2];
const fail = message => { throw new Error(message); };
const hash = file => crypto.createHash('sha256').update(fs.readFileSync(file)).digest('hex');

const containedPath = (base, name) => {
  const target = path.resolve(base, name);
  const relative = path.relative(base, target);
  if (!relative || relative === '..' || relative.startsWith('..' + path.sep) || path.isAbsolute(relative)) {
    fail('Path outside intended tree: ' + name);
  }
  return target;
};

const physicalEntry = (entry, kind) => {
  const stat = fs.lstatSync(entry);
  if (stat.isSymbolicLink()) fail('Unsupported link or junction: ' + entry);
  if (kind === 'directory' ? !stat.isDirectory() : !stat.isFile()) fail('Expected physical ' + kind + ': ' + entry);
  if (stat.isFile() && stat.nlink !== 1) fail('Unsupported hardlinked file: ' + entry);
  if (fs.realpathSync(entry) !== path.resolve(entry)) {
    fail('Unsupported path spelling or aliased ancestor: ' + entry);
  }
};

// Check each parent before following it, not just the resolved leaf's containment.
const ensurePhysicalPath = (target, kind) => {
  const relative = path.relative(root, containedPath(root, target));
  const parts = relative.split(path.sep);
  let current = root;
  for (const [index, part] of parts.entries()) {
    current = path.join(current, part);
    physicalEntry(current, index === parts.length - 1 ? kind : 'directory');
  }
};

const physicalTree = (dir) => {
  ensurePhysicalPath(dir, 'directory');
  let entries;
  try {
    entries = fs.readdirSync(dir);
  } catch (err) {
    fail('Failed to enumerate physical directory: ' + dir + ': ' + err.message);
  }
  for (const name of entries) {
    const entry = path.join(dir, name);
    const stat = fs.lstatSync(entry);
    if (stat.isDirectory() && !stat.isSymbolicLink()) physicalTree(entry);
    else ensurePhysicalPath(entry, 'file');
  }
};

try {
  if (!['apply', 'verify'].includes(mode)) fail('Expected apply or verify');
  const patchDir = path.join(root, 'patches/once-ui');
  const identityFile = path.join(patchDir, 'once-ui-source-identity.json');
  const core = path.join(root, 'node_modules/@once-ui-system/core');
  const tool = path.join(root, 'node_modules/patch-package');

  physicalEntry(root, 'directory');
  ensurePhysicalPath(path.join(root, 'package.json'), 'file');
  ensurePhysicalPath(path.join(core, 'package.json'), 'file');
  ensurePhysicalPath(path.join(tool, 'package.json'), 'file');
  ensurePhysicalPath(path.join(tool, 'index.js'), 'file');
  // The pinned tool eagerly loads local modules beyond discovery/apply entry files.
  physicalTree(path.join(tool, 'dist'));
  physicalTree(patchDir);
  ensurePhysicalPath(identityFile, 'file');

  const identity = JSON.parse(fs.readFileSync(identityFile, 'utf8'));
  const patchFile = containedPath(patchDir, identity.patch_file);
  ensurePhysicalPath(patchFile, 'file');
  const targets = Object.entries(identity.files).map(([name, expected]) => {
    const target = containedPath(core, name);
    ensurePhysicalPath(target, 'file');
    return {name, expected, target};
  });

  if (JSON.parse(fs.readFileSync(path.join(core, 'package.json'), 'utf8')).version !== identity.version) fail('Core version mismatch');
  if (JSON.parse(fs.readFileSync(path.join(tool, 'package.json'), 'utf8')).version !== identity.tool_version) fail('Tool version mismatch');
  if (hash(patchFile) !== identity.patch_sha256) fail('Patch checksum mismatch');

  // Preserve discovery parity with pinned patch-package 8.0.1 after the link walk.
  const toolPatchFs = require(path.join(tool, 'dist/patchFs.js'));
  const discoveredPatches = toolPatchFs.getPatchFiles(patchDir).sort();
  const allowedPatch = identity.patch_file;
  if (discoveredPatches.length !== 1 || discoveredPatches[0] !== allowedPatch) {
    fail(`Patch set mismatch. Expected exactly [${allowedPatch}], found [${discoveredPatches.join(', ')}]. Remove unexpected patches before apply/verify.`);
  }

  const states = targets.map(({target, expected}) => {
    const actual = hash(target);
    return actual === expected.after_sha256 ? 'final' : actual === expected.before_sha256 ? 'original' : 'unknown';
  });
  if (states.every(s => s === 'final')) {
    console.log('VERIFIED: exact accepted Once UI patch (already applied)');
  } else {
    if (mode === 'verify') fail('Accepted Once UI patch missing or modified');
    if (!states.every(s => s === 'original')) fail('Mixed or unknown source state; no files written');
    const result = spawnSync(process.execPath, [
      path.join(tool, 'index.js'),
      '--error-on-fail',
      '--patch-dir', 'patches/once-ui'
    ], {cwd: root, stdio: 'inherit'});
    if (result.error || result.status !== 0) fail('patch-package failed; discard/recreate dependency state before retry');
    for (const {name, expected, target} of targets) {
      if (hash(target) !== expected.after_sha256) fail('Output hash mismatch: ' + name);
    }
    console.log('APPLIED AND VERIFIED: exact accepted Once UI patch');
  }
} catch (error) {
  console.error('ONCE UI PATCH REFUSED: ' + error.message);
  process.exitCode = 1;
}
