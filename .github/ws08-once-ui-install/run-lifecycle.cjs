'use strict';

/**
 * WS-08: Ubuntu CI Install Lifecycle Runner Script (Remediated Snapshots & Early Provenance)
 * 
 * Executes full physical install lifecycle verification on GitHub Actions (ubuntu-latest):
 * 1. Early metadata initialization (TASK_OWNERSHIP.json) & early ci-summary.json written at intake.
 * 2. Raw initial scoped checkout snapshot (checkout-initial-snapshot.json) persisted immediately.
 * 3. Exact measured intake input hashes (intake-input-hashes.json) persisted before checksum assertion.
 * 4. Strict input checksum assertion against exact accepted baselines (fail-closed with full provenance).
 * 5. Isolated physical scratch copies in RUNNER_TEMP with conflict-checked manifest script updates.
 * 6. Incremental lockfile integrity persistence (lockfile-verifications.json) across both copies at all stages.
 * 7. Scenario A (normal): npm ci -> automatic postinstall -> explicit verify -> typecheck -> build (with enforced prebuild gate ordering) -> repeat apply/verify with full target hash retention.
 * 8. Scenario B (ignored-scripts): npm ci --ignore-scripts -> pre-apply measured originals -> negative verify refusal gate (enforcing exit 1, no signal/error, refusal banner) -> target immutability assertion before recovery.
 * 9. Scenario C (recovery): explicit apply -> explicit verify (with separate logs and no log overwriting) -> post-recovery measured finals -> lockfile integrity assertion.
 * 10. Raw final scoped checkout snapshot (checkout-final-snapshot.json) persisted before comparisons.
 * 11. Comprehensive scoped checkout integrity verification (checkout-integrity.json) based on actual measurement without hardcoded claims; missing required config is rejected.
 * 12. Fail-closed error handling with early failure summary (ci-summary.json) generation on any failure.
 */

const fs = require('node:fs');
const path = require('node:path');
const crypto = require('node:crypto');
const os = require('node:os');
const { spawnSync } = require('node:child_process');

const EXPECTED = {
  helper_sha256: 'ed9c8a1202a9f3c8892d2f50b88d157b9defb4d37021dbf36e7f14efe7a88e73',
  approved_patch_sha256: '8738f5c16076bfce1a4c72c7206c2eca04e429796a16f5423424afd45be97891',
  identity_sha256: '744a34fbc07b101030846974602f5855a5dead7f628233fb739dd8211de8470b',
  frontend_package_json_sha256: '61de8012c454de332b54f6e393b2972ff3f323e9067bfce612115f0922c0ac3c',
  frontend_package_lock_sha256: '4bd4f21c8ab404c4557664bb28b144371c71c14759a9cde627f1dbd25cb11894'
};

const SCRIPTS_TO_ADD = {
  postinstall: 'node scripts/once-ui-patch.cjs apply',
  'verify:once-ui': 'node scripts/once-ui-patch.cjs verify',
  prebuild: 'npm run verify:once-ui'
};

const CANONICAL_INPUTS = {
  canonical_helper: { relativePath: 'scripts/once-ui-patch.cjs', expectedKey: 'helper_sha256' },
  canonical_patch: { relativePath: 'patches/once-ui/@once-ui-system+core+2.0.0.patch', expectedKey: 'approved_patch_sha256' },
  canonical_identity: { relativePath: 'patches/once-ui/once-ui-source-identity.json', expectedKey: 'identity_sha256' }
};

const REQUIRED_CHECKOUT_FILES = [
  'canonical_package_json',
  'canonical_package_lock',
  'next_config',
  'tsconfig',
  'input_helper',
  'input_patch',
  'input_identity',
  ...Object.keys(CANONICAL_INPUTS)
];

const sha256 = filePath => {
  const data = fs.readFileSync(filePath);
  return crypto.createHash('sha256').update(data).digest('hex');
};

const assertPhysicalFile = (root, relativePath) => {
  let current = root;
  const parts = relativePath.split('/');
  for (const [index, part] of parts.entries()) {
    current = path.join(current, part);
    const stat = fs.lstatSync(current);
    const leaf = index === parts.length - 1;
    if (stat.isSymbolicLink() || (leaf ? !stat.isFile() || stat.nlink !== 1 : !stat.isDirectory())) {
      throw new Error(`Unsupported physical input path: ${current}`);
    }
    if (fs.realpathSync(current) !== path.resolve(current)) {
      throw new Error(`Unsupported path spelling or aliased input: ${current}`);
    }
  }
};

const verifyCanonicalInputs = (frontendDir, expectedHashes) => {
  for (const { relativePath, expectedKey } of Object.values(CANONICAL_INPUTS)) {
    assertPhysicalFile(frontendDir, relativePath);
    if (sha256(path.join(frontendDir, relativePath)) !== expectedHashes[expectedKey]) {
      throw new Error(`Canonical input checksum mismatch: ${relativePath}`);
    }
  }
};

const sanitize = text => {
  if (typeof text !== 'string') return '';
  return text
    .replace(/(ghp_[a-zA-Z0-9]+|npm_[a-zA-Z0-9]+|Bearer\s+[^\s]+)/gi, '[REDACTED_SECRET]')
    .replace(/(password|secret|token)\s*[:=]\s*["']?[^"'\s\n]+/gi, '$1=[REDACTED]');
};

const measureTargets = (targetBaseDir, identityFiles) => {
  const measurements = {};
  for (const [relPath, exp] of Object.entries(identityFiles)) {
    const fullPath = path.join(targetBaseDir, 'node_modules/@once-ui-system/core', relPath);
    if (!fs.existsSync(fullPath)) {
      measurements[relPath] = {
        exists: false,
        actual_sha256: null,
        matches_expected_before: false,
        matches_expected_after: false
      };
    } else {
      const h = sha256(fullPath);
      measurements[relPath] = {
        exists: true,
        actual_sha256: h,
        matches_expected_before: h === exp.before_sha256,
        matches_expected_after: h === exp.after_sha256
      };
    }
  }
  return measurements;
};

const validateTargetImmutability = (beforeTargets, afterTargets, contextLabel) => {
  const mismatches = [];
  const targetKeys = Object.keys(beforeTargets);
  for (const relPath of targetKeys) {
    const before = beforeTargets[relPath];
    const after = afterTargets[relPath];
    if (!after) {
      mismatches.push(`${relPath}: missing in after-targets`);
      continue;
    }
    if (before.exists !== after.exists) {
      mismatches.push(`${relPath}: existence changed from ${before.exists} to ${after.exists}`);
      continue;
    }
    if (before.actual_sha256 !== after.actual_sha256) {
      mismatches.push(`${relPath}: hash mutated from ${before.actual_sha256} to ${after.actual_sha256}`);
      continue;
    }
  }
  if (mismatches.length > 0) {
    throw new Error(`Target immutability assertion failed during ${contextLabel}:\n  ${mismatches.join('\n  ')}`);
  }
  return {
    ok: true,
    targets_checked: targetKeys.length,
    identical: true
  };
};

const recordLockfileVerification = (lockfilePath, expectedHash, stageLabel, evidenceDir) => {
  const verificationsFile = path.join(evidenceDir, 'lockfile-verifications.json');
  let currentRecords = {};
  if (fs.existsSync(verificationsFile)) {
    try {
      currentRecords = JSON.parse(fs.readFileSync(verificationsFile, 'utf8'));
    } catch (_) {}
  }

  const fileExists = fs.existsSync(lockfilePath);
  let actualHash = null;
  let readError = null;
  if (fileExists) {
    try {
      actualHash = sha256(lockfilePath);
    } catch (err) {
      readError = err.message;
    }
  }

  const matches = (actualHash === expectedHash);
  const record = {
    stage: stageLabel,
    path: lockfilePath,
    timestamp: new Date().toISOString(),
    exists: fileExists,
    actual_sha256: actualHash,
    expected_sha256: expectedHash,
    matches_baseline: matches,
    status: fileExists ? (matches ? 'PASS' : 'MISMATCH') : 'MISSING',
    error: readError || (!fileExists ? 'File does not exist' : (matches ? null : `Hash mismatch: ${actualHash} != ${expectedHash}`))
  };

  currentRecords[stageLabel] = record;
  fs.writeFileSync(verificationsFile, JSON.stringify(currentRecords, null, 2));

  if (!fileExists) {
    throw new Error(`Lockfile integrity check failed at stage "${stageLabel}": file does not exist at ${lockfilePath}`);
  }
  if (!matches) {
    throw new Error(`Lockfile integrity check failed at stage "${stageLabel}": actual ${actualHash} != expected ${expectedHash}`);
  }

  return record;
};

const validatePrebuildGate = stdout => {
  if (typeof stdout !== 'string') {
    throw new Error('validatePrebuildGate: stdout must be a string');
  }
  const prebuildIdx = stdout.indexOf('frontend@0.1.0 prebuild');
  const verifyCmdIdx = stdout.indexOf('verify:once-ui');
  const helperVerifiedIdx = stdout.indexOf('VERIFIED: exact accepted Once UI patch');
  const buildCmdIdx = stdout.indexOf('frontend@0.1.0 build');
  const nextBuildIdx = stdout.indexOf('next build');

  const hasPrebuildMarker = prebuildIdx !== -1 || verifyCmdIdx !== -1;
  if (!hasPrebuildMarker) {
    throw new Error('Prebuild gate validation failed: prebuild script execution marker missing from stdout.');
  }

  if (helperVerifiedIdx === -1) {
    throw new Error('Prebuild gate validation failed: helper verification confirmation ("VERIFIED: exact accepted Once UI patch") missing from stdout.');
  }

  const buildStartIdx = buildCmdIdx !== -1 ? buildCmdIdx : nextBuildIdx;
  if (buildStartIdx === -1) {
    throw new Error('Prebuild gate validation failed: build/next build marker missing from stdout.');
  }

  const prebuildStart = prebuildIdx !== -1 ? prebuildIdx : verifyCmdIdx;
  if (prebuildStart > helperVerifiedIdx) {
    throw new Error(`Prebuild gate validation failed: prebuild marker at ${prebuildStart} appears after helper verification at ${helperVerifiedIdx}.`);
  }

  if (helperVerifiedIdx > buildStartIdx) {
    throw new Error(`Prebuild gate validation failed: verify gate output at ${helperVerifiedIdx} does not precede build start at ${buildStartIdx}.`);
  }

  return {
    ok: true,
    prebuild_marker_index: prebuildStart,
    helper_verified_index: helperVerifiedIdx,
    build_start_index: buildStartIdx,
    ordering_verified: true
  };
};

const validateNegativeVerifyRefusal = res => {
  if (!res) {
    throw new Error('validateNegativeVerifyRefusal: command result object is required');
  }

  if (res.error) {
    throw new Error(`Negative verify failed due to process spawn error, NOT expected helper refusal: ${res.error.message || res.error}`);
  }

  if (res.signal) {
    throw new Error(`Negative verify failed due to process signal termination (${res.signal}), NOT expected helper refusal.`);
  }

  if (res.status === 0) {
    throw new Error('SECURITY GATE FAILURE: explicit verify exited 0 on unpatched copy! Refusal expected.');
  }

  if (res.status !== 1) {
    throw new Error(`Negative verify exited with unexpected exit code ${res.status} (expected 1 for helper refusal).`);
  }

  const stderr = res.stderr || '';
  if (!stderr.includes('ONCE UI PATCH REFUSED')) {
    throw new Error('Negative verify failed without expected "ONCE UI PATCH REFUSED" prefix in stderr.');
  }

  if (!stderr.includes('Accepted Once UI patch missing or modified')) {
    throw new Error('Negative verify failed without expected "Accepted Once UI patch missing or modified" reason in stderr.');
  }

  return {
    ok: true,
    exit_code: res.status,
    refusal_banner_found: true,
    refusal_reason_found: true,
    is_expected_refusal: true
  };
};

const applyScratchScripts = (pkgJsonPath, scriptsToAdd) => {
  const content = fs.readFileSync(pkgJsonPath, 'utf8');
  const pkg = JSON.parse(content);
  pkg.scripts = pkg.scripts || {};

  const auditLog = {
    added: {},
    identical_retained: {},
    conflicts: []
  };

  for (const [key, targetCmd] of Object.entries(scriptsToAdd)) {
    if (Object.prototype.hasOwnProperty.call(pkg.scripts, key)) {
      const existingCmd = pkg.scripts[key];
      if (existingCmd === targetCmd) {
        auditLog.identical_retained[key] = existingCmd;
      } else {
        auditLog.conflicts.push({
          script: key,
          existing: existingCmd,
          target: targetCmd
        });
      }
    } else {
      pkg.scripts[key] = targetCmd;
      auditLog.added[key] = targetCmd;
    }
  }

  if (auditLog.conflicts.length > 0) {
    const detail = auditLog.conflicts.map(c => `  - "${c.script}": existing "${c.existing}" != target "${c.target}"`).join('\n');
    throw new Error(`Conflict detected in package.json scripts at ${pkgJsonPath}:\n${detail}\nRefusing to overwrite silently.`);
  }

  if (Object.keys(auditLog.added).length > 0) {
    fs.writeFileSync(pkgJsonPath, JSON.stringify(pkg, null, 2) + '\n');
  }
  return auditLog;
};

const takeScopedCheckoutSnapshot = (repoRoot, expectedHashes) => {
  const canonicalPkgJsonPath = path.join(repoRoot, 'frontend/package.json');
  const canonicalPkgLockPath = path.join(repoRoot, 'frontend/package-lock.json');
  const nextConfigPath = path.join(repoRoot, 'frontend/next.config.ts');
  const tsconfigPath = path.join(repoRoot, 'frontend/tsconfig.json');
  const inputsDir = path.join(repoRoot, '.github/ws08-once-ui-install/inputs');

  const getFileInfo = (filePath, required = true) => {
    const exists = fs.existsSync(filePath);
    let hash = null;
    let readError = null;
    if (exists) {
      try {
        assertPhysicalFile(repoRoot, path.relative(repoRoot, filePath).split(path.sep).join('/'));
        hash = sha256(filePath);
      } catch (err) {
        readError = err.message;
      }
    }
    return {
      path: filePath,
      required,
      exists,
      sha256: hash,
      status: exists ? (readError ? `READ_ERROR: ${readError}` : 'PRESENT') : 'MISSING'
    };
  };

  const snapshot = {
    timestamp: new Date().toISOString(),
    protected_files: {
      canonical_package_json: getFileInfo(canonicalPkgJsonPath, true),
      canonical_package_lock: getFileInfo(canonicalPkgLockPath, true),
      next_config: getFileInfo(nextConfigPath, true),
      tsconfig: getFileInfo(tsconfigPath, true),
      input_helper: getFileInfo(path.join(inputsDir, 'once-ui-patch.cjs'), true),
      input_patch: getFileInfo(path.join(inputsDir, '@once-ui-system+core+2.0.0.patch'), true),
      input_identity: getFileInfo(path.join(inputsDir, 'once-ui-source-identity.json'), true),
      ...Object.fromEntries(Object.entries(CANONICAL_INPUTS).map(([key, { relativePath }]) =>
        [key, getFileInfo(path.join(repoRoot, 'frontend', relativePath), true)]))
    },
    prohibited_paths_presence: {
      canonical_node_modules_exists: fs.existsSync(path.join(repoRoot, 'frontend/node_modules'))
    }
  };

  return snapshot;
};

const verifyScopedCheckoutIntegrity = (initialSnapshot, finalSnapshot, expectedHashes) => {
  const integrityReport = {};
  const failures = [];

  // 1. Verify required protected files: must exist in initial AND final, and be byte-identical
  for (const key of REQUIRED_CHECKOUT_FILES) {
    const initEntry = initialSnapshot.protected_files[key];
    const finalEntry = finalSnapshot.protected_files[key];

    if (!initEntry) {
      failures.push(`Initial snapshot missing entry for ${key}`);
      integrityReport[`${key}_initial_recorded`] = false;
      continue;
    }
    if (!finalEntry) {
      failures.push(`Final snapshot missing entry for ${key}`);
      integrityReport[`${key}_final_recorded`] = false;
      continue;
    }

    const initExists = initEntry.exists === true && initEntry.sha256 !== null;
    const finalExists = finalEntry.exists === true && finalEntry.sha256 !== null;

    if (!initExists || !finalExists) {
      const msg = `Required file [${key}] is missing: initial exists=${initEntry.exists} (${initEntry.status}), final exists=${finalEntry.exists} (${finalEntry.status})`;
      failures.push(msg);
      integrityReport[`${key}_present`] = false;
      integrityReport[`${key}_unchanged`] = false;
      continue;
    }

    integrityReport[`${key}_present`] = true;

    // Check immutability between initial and final
    const unchanged = (initEntry.sha256 === finalEntry.sha256);
    integrityReport[`${key}_unchanged`] = unchanged;
    if (!unchanged) {
      failures.push(`Protected file [${key}] mutated during run: initial=${initEntry.sha256} vs final=${finalEntry.sha256}`);
    }
  }

  // 2. Baseline checks
  if (finalSnapshot.protected_files.canonical_package_json && finalSnapshot.protected_files.canonical_package_json.sha256 === expectedHashes.frontend_package_json_sha256) {
    integrityReport.canonical_pkg_json_matches_baseline = true;
  } else {
    integrityReport.canonical_pkg_json_matches_baseline = false;
    failures.push(`canonical_package_json mismatch: actual ${finalSnapshot.protected_files.canonical_package_json ? finalSnapshot.protected_files.canonical_package_json.sha256 : null} != expected ${expectedHashes.frontend_package_json_sha256}`);
  }

  if (finalSnapshot.protected_files.canonical_package_lock && finalSnapshot.protected_files.canonical_package_lock.sha256 === expectedHashes.frontend_package_lock_sha256) {
    integrityReport.canonical_pkg_lock_matches_baseline = true;
  } else {
    integrityReport.canonical_pkg_lock_matches_baseline = false;
    failures.push(`canonical_package_lock mismatch: actual ${finalSnapshot.protected_files.canonical_package_lock ? finalSnapshot.protected_files.canonical_package_lock.sha256 : null} != expected ${expectedHashes.frontend_package_lock_sha256}`);
  }

  if (finalSnapshot.protected_files.input_helper && finalSnapshot.protected_files.input_helper.sha256 === expectedHashes.helper_sha256) {
    integrityReport.input_helper_matches_baseline = true;
  } else {
    integrityReport.input_helper_matches_baseline = false;
    failures.push(`input_helper mismatch: actual ${finalSnapshot.protected_files.input_helper ? finalSnapshot.protected_files.input_helper.sha256 : null} != expected ${expectedHashes.helper_sha256}`);
  }

  if (finalSnapshot.protected_files.input_patch && finalSnapshot.protected_files.input_patch.sha256 === expectedHashes.approved_patch_sha256) {
    integrityReport.input_patch_matches_baseline = true;
  } else {
    integrityReport.input_patch_matches_baseline = false;
    failures.push(`input_patch mismatch: actual ${finalSnapshot.protected_files.input_patch ? finalSnapshot.protected_files.input_patch.sha256 : null} != expected ${expectedHashes.approved_patch_sha256}`);
  }

  if (finalSnapshot.protected_files.input_identity && finalSnapshot.protected_files.input_identity.sha256 === expectedHashes.identity_sha256) {
    integrityReport.input_identity_matches_baseline = true;
  } else {
    integrityReport.input_identity_matches_baseline = false;
    failures.push(`input_identity mismatch: actual ${finalSnapshot.protected_files.input_identity ? finalSnapshot.protected_files.input_identity.sha256 : null} != expected ${expectedHashes.identity_sha256}`);
  }

  for (const [key, { expectedKey }] of Object.entries(CANONICAL_INPUTS)) {
    for (const [stage, snapshot] of [['initial', initialSnapshot], ['final', finalSnapshot]]) {
      const matches = snapshot.protected_files[key]?.sha256 === expectedHashes[expectedKey];
      integrityReport[`${key}_${stage}_matches_baseline`] = matches;
      if (!matches) failures.push(`${key} ${stage} checksum mismatch`);
    }
  }

  // Physical input validation is recorded by each raw snapshot before comparison.
  for (const snapshot of [initialSnapshot, finalSnapshot]) {
    for (const [key, entry] of Object.entries(snapshot.protected_files)) {
      if (entry.status !== 'PRESENT') failures.push(`Invalid protected input [${key}]: ${entry.status}`);
    }
  }

  const nodeModulesNotCreated = !finalSnapshot.prohibited_paths_presence.canonical_node_modules_exists ||
    (finalSnapshot.prohibited_paths_presence.canonical_node_modules_exists === initialSnapshot.prohibited_paths_presence.canonical_node_modules_exists);
  integrityReport.canonical_node_modules_not_created = nodeModulesNotCreated;
  if (!nodeModulesNotCreated) {
    failures.push('canonical node_modules was created in checkout during runner execution!');
  }

  return {
    verified: failures.length === 0,
    report: integrityReport,
    failures,
    initial_snapshot_file: 'checkout-initial-snapshot.json',
    final_snapshot_file: 'checkout-final-snapshot.json'
  };
};

const runCmd = (cmd, args, cwd, logName, envOverrides = {}, evidenceDir = null, spawnFn = spawnSync) => {
  const startMs = Date.now();
  const startTime = new Date(startMs).toISOString();
  const env = { ...process.env, ...envOverrides };
  const isWin = process.platform === 'win32';
  const executable = (isWin && (cmd === 'npm' || cmd === 'npx')) ? `${cmd}.cmd` : cmd;
  const res = spawnFn(executable, args, { cwd, env, encoding: 'utf8', shell: false });
  const endMs = Date.now();
  const endTime = new Date(endMs).toISOString();
  const dur = ((endMs - startMs) / 1000).toFixed(2);

  const logContent = `=== COMMAND: ${cmd} ${args.join(' ')} ===\n` +
    `CWD: ${cwd}\n` +
    `START: ${startTime}\n` +
    `END: ${endTime}\n` +
    `DURATION: ${dur}s\n` +
    `EXIT CODE: ${res.status}\n` +
    `SIGNAL: ${res.signal || 'NONE'}\n` +
    `ERROR: ${res.error ? res.error.message : 'NONE'}\n` +
    `=== STDOUT ===\n${sanitize(res.stdout || '')}\n` +
    `=== STDERR ===\n${sanitize(res.stderr || '')}\n`;

  if (evidenceDir && logName) {
    fs.writeFileSync(path.join(evidenceDir, logName), logContent);
  }

  return {
    cmd,
    args,
    cwd,
    status: res.status,
    signal: res.signal || null,
    error: res.error || null,
    stdout: res.stdout || '',
    stderr: res.stderr || '',
    duration: dur,
    startTime,
    endTime,
    logContent
  };
};

const main = (options = {}) => {
  const startTime = Date.now();
  let currentStage = 'Initialization and Metadata Intake';

  console.log('=== WS-08 Once UI Ubuntu CI Install Lifecycle Runner (Remediated Snapshots & Early Provenance) ===');
  console.log('Platform:', os.platform(), os.release(), os.arch());
  console.log('Node:', process.version);

  const scriptDir = __dirname;
  const repoRoot = options.repoRoot || path.resolve(scriptDir, '../..');
  const runnerTempRaw = options.runnerTemp || process.env.RUNNER_TEMP || os.tmpdir();
  const runnerTemp = fs.realpathSync(runnerTempRaw);
  const spawnSyncFn = options.spawnSync || spawnSync;
  const exitFn = options.exitHandler || (code => process.exit(code));

  console.log('Repository root:', repoRoot);

  const inputsDir = options.inputsDir || path.join(repoRoot, '.github/ws08-once-ui-install/inputs');
  const evidenceDir = options.evidenceDir || path.join(repoRoot, '.github/ws08-once-ui-install/evidence');
  fs.mkdirSync(evidenceDir, { recursive: true });

  const inputHelper = path.join(inputsDir, 'once-ui-patch.cjs');
  const inputPatch = path.join(inputsDir, '@once-ui-system+core+2.0.0.patch');
  const inputIdentity = path.join(inputsDir, 'once-ui-source-identity.json');
  const canonicalPkgJson = path.join(repoRoot, 'frontend/package.json');
  const canonicalPkgLock = path.join(repoRoot, 'frontend/package-lock.json');

  const ciResults = {
    timestamp: new Date().toISOString(),
    overall_verdict: 'PENDING',
    scenarios: {}
  };

  const writeEarlySummary = (verdict, failedStage = null, errorMessage = null) => {
    try {
      ciResults.overall_verdict = verdict;
      if (failedStage) ciResults.failed_stage = failedStage;
      if (errorMessage) ciResults.error_message = errorMessage;
      ciResults.duration_seconds = ((Date.now() - startTime) / 1000).toFixed(2);
      fs.writeFileSync(path.join(evidenceDir, 'ci-summary.json'), JSON.stringify(ciResults, null, 2));
    } catch (e) {
      console.error('Warning: could not write ci-summary.json:', e.message);
    }
  };

  // Compute runner script SHA256 for provenance
  let runnerSha256 = null;
  try {
    runnerSha256 = sha256(__filename);
  } catch (_) {}

  const isCI = Boolean(process.env.CI);
  const githubContext = {
    is_ci: isCI,
    sha: process.env.GITHUB_SHA || (isCI ? 'unknown' : 'local-or-synthetic'),
    ref: process.env.GITHUB_REF || (isCI ? 'unknown' : 'local-or-synthetic'),
    event_name: process.env.GITHUB_EVENT_NAME || (isCI ? 'unknown' : 'local-or-synthetic'),
    workflow: process.env.GITHUB_WORKFLOW || (isCI ? 'unknown' : 'local-or-synthetic'),
    run_id: process.env.GITHUB_RUN_ID || (isCI ? 'unknown' : 'local-or-synthetic'),
    image_os: process.env.ImageOS || null,
    image_version: process.env.ImageVersion || null
  };

  const ownership = {
    task_id: 'WS-08',
    task_title: 'Ubuntu CI Install Lifecycle Verification (Remediated Snapshots & Early Provenance)',
    timestamp: new Date().toISOString(),
    runner_script_sha256: runnerSha256,
    runner_os: os.platform(),
    runner_release: os.release(),
    runner_arch: os.arch(),
    node_version: process.version,
    npm_version: null,
    github_context: githubContext,
    directories: {
      repo_root: repoRoot,
      inputs_dir: inputsDir,
      evidence_dir: evidenceDir,
      scratch_task_dir: null,
      normal: null,
      ignored_scripts: null
    },
    exact_inputs: null
  };
  fs.writeFileSync(path.join(evidenceDir, 'TASK_OWNERSHIP.json'), JSON.stringify(ownership, null, 2));

  // Write initial summary RUNNING
  writeEarlySummary('RUNNING');

  try {
    // 1. Initial Scoped Checkout Snapshot - Persisted Immediately
    currentStage = 'Intake Scoped Checkout Snapshot';
    console.log('\n--- Capturing & Persisting Initial Scoped Checkout Snapshot ---');
    const initialCheckoutSnapshot = takeScopedCheckoutSnapshot(repoRoot, EXPECTED);
    fs.writeFileSync(path.join(evidenceDir, 'checkout-initial-snapshot.json'), JSON.stringify(initialCheckoutSnapshot, null, 2));

    // Prohibited paths check at intake
    if (process.env.CI && initialCheckoutSnapshot.prohibited_paths_presence.canonical_node_modules_exists) {
      throw new Error('Intake integrity failure: frontend/node_modules exists on fresh CI checkout! Fresh checkout must not have node_modules.');
    }

    // Required files presence check at intake
    for (const reqKey of REQUIRED_CHECKOUT_FILES) {
      const fileEntry = initialCheckoutSnapshot.protected_files[reqKey];
      if (!fileEntry || !fileEntry.exists || fileEntry.sha256 === null) {
        throw new Error(`Intake integrity failure: required checkout file [${reqKey}] is missing at ${fileEntry ? fileEntry.path : 'unknown'}!`);
      }
    }

    // 2. Measure and Persist Actual Intake Input Hashes BEFORE Asserting
    currentStage = 'Exact Input Checksums Verification';
    console.log('\n--- Measuring & Persisting Input Hashes Against Expected Baselines ---');

    const measureInput = (filePath, expectedHash) => {
      const exists = fs.existsSync(filePath);
      let h = null;
      let err = null;
      if (exists) {
        try {
          h = sha256(filePath);
        } catch (e) {
          err = e.message;
        }
      }
      const matches = (h === expectedHash);
      return {
        path: filePath,
        exists,
        actual_sha256: h,
        expected_sha256: expectedHash,
        matches_baseline: matches,
        status: exists ? (matches ? 'MATCH' : 'MISMATCH') : 'MISSING',
        error: err
      };
    };

    const inputMeasurements = {
      timestamp: new Date().toISOString(),
      inputs: {
        helper: measureInput(inputHelper, EXPECTED.helper_sha256),
        patch: measureInput(inputPatch, EXPECTED.approved_patch_sha256),
        identity: measureInput(inputIdentity, EXPECTED.identity_sha256),
        canonicalPkgJson: measureInput(canonicalPkgJson, EXPECTED.frontend_package_json_sha256),
        canonicalPkgLock: measureInput(canonicalPkgLock, EXPECTED.frontend_package_lock_sha256),
        ...Object.fromEntries(Object.entries(CANONICAL_INPUTS).map(([key, { relativePath, expectedKey }]) =>
          [key, measureInput(path.join(repoRoot, 'frontend', relativePath), EXPECTED[expectedKey])]))
      }
    };
    fs.writeFileSync(path.join(evidenceDir, 'intake-input-hashes.json'), JSON.stringify(inputMeasurements, null, 2));

    ownership.exact_inputs = {
      helper: inputMeasurements.inputs.helper.actual_sha256,
      patch: inputMeasurements.inputs.patch.actual_sha256,
      identity: inputMeasurements.inputs.identity.actual_sha256,
      canonicalPkgJson: inputMeasurements.inputs.canonicalPkgJson.actual_sha256,
      canonicalPkgLock: inputMeasurements.inputs.canonicalPkgLock.actual_sha256,
      ...Object.fromEntries(Object.keys(CANONICAL_INPUTS).map(key => [key, inputMeasurements.inputs[key].actual_sha256]))
    };
    fs.writeFileSync(path.join(evidenceDir, 'TASK_OWNERSHIP.json'), JSON.stringify(ownership, null, 2));

    // Assert inputs match expected
    const hashMismatches = [];
    for (const [key, m] of Object.entries(inputMeasurements.inputs)) {
      if (!m.exists) {
        hashMismatches.push(`${key} is missing at ${m.path}`);
      } else if (!m.matches_baseline) {
        hashMismatches.push(`${key} checksum mismatch: actual ${m.actual_sha256} != expected ${m.expected_sha256}`);
      }
    }

    if (hashMismatches.length > 0) {
      throw new Error(`Input baseline verification FAILED:\n  ${hashMismatches.join('\n  ')}`);
    }
    verifyCanonicalInputs(path.join(repoRoot, 'frontend'), EXPECTED);
    console.log('All input checksums match exact expected baseline.');

    const identityData = JSON.parse(fs.readFileSync(inputIdentity, 'utf8'));

    // 3. Setup scratch directories in RUNNER_TEMP
    currentStage = 'Scratch Setup in RUNNER_TEMP';
    const taskDir = path.join(runnerTemp, `ws08-ubuntu-ci-install-${Date.now()}`);
    const normalDir = path.join(taskDir, 'normal');
    const ignoredDir = path.join(taskDir, 'ignored-scripts');

    fs.mkdirSync(normalDir, { recursive: true });
    fs.mkdirSync(ignoredDir, { recursive: true });

    // Update TASK_OWNERSHIP.json with actual scratch locations
    ownership.directories.scratch_task_dir = taskDir;
    ownership.directories.normal = normalDir;
    ownership.directories.ignored_scripts = ignoredDir;

    let npmVersion = 'unknown';
    try {
      const isWin = process.platform === 'win32';
      const npmExe = isWin ? 'npm.cmd' : 'npm';
      const npmCheck = spawnSyncFn(npmExe, ['--version'], { encoding: 'utf8', shell: false });
      npmVersion = (npmCheck.stdout || '').trim();
    } catch (_) {}
    ownership.npm_version = npmVersion;
    fs.writeFileSync(path.join(evidenceDir, 'TASK_OWNERSHIP.json'), JSON.stringify(ownership, null, 2));

    const copyFrontend = targetDir => {
      const srcDir = path.join(repoRoot, 'frontend');
      const walkAndCopy = (currentSrc, currentDst) => {
        fs.mkdirSync(currentDst, { recursive: true });
        for (const entry of fs.readdirSync(currentSrc, { withFileTypes: true })) {
          const name = entry.name;
          if (name === 'node_modules' || name === '.next' || name === '.git' || name === 'tsconfig.tsbuildinfo') continue;
          if (name.startsWith('.env')) continue;
          const srcPath = path.join(currentSrc, name);
          const dstPath = path.join(currentDst, name);
          if (entry.isDirectory()) {
            walkAndCopy(srcPath, dstPath);
          } else if (entry.isFile()) {
            fs.copyFileSync(srcPath, dstPath);
          }
        }
      };
      walkAndCopy(srcDir, targetDir);

      verifyCanonicalInputs(targetDir, EXPECTED);

      const pkgPath = path.join(targetDir, 'package.json');
      return applyScratchScripts(pkgPath, SCRIPTS_TO_ADD);
    };

    console.log('\n--- Preparing Scratch Copies with Script Conflict Detection ---');
    const normalScriptAudit = copyFrontend(normalDir);
    const ignoredScriptAudit = copyFrontend(ignoredDir);

    const manifestDelta = {
      original_scripts: JSON.parse(fs.readFileSync(canonicalPkgJson, 'utf8')).scripts,
      scratch_scripts_normal: JSON.parse(fs.readFileSync(path.join(normalDir, 'package.json'), 'utf8')).scripts,
      scratch_scripts_ignored: JSON.parse(fs.readFileSync(path.join(ignoredDir, 'package.json'), 'utf8')).scripts,
      target_scripts_to_add: SCRIPTS_TO_ADD,
      audit_normal: normalScriptAudit,
      audit_ignored: ignoredScriptAudit
    };
    fs.writeFileSync(path.join(evidenceDir, 'manifest-delta.json'), JSON.stringify(manifestDelta, null, 2));

    // Validate and incrementally record lockfile integrity immediately on scratch copies
    currentStage = 'Initial Scratch Lockfile Integrity Check';
    recordLockfileVerification(
      path.join(normalDir, 'package-lock.json'),
      EXPECTED.frontend_package_lock_sha256,
      'Normal Copy Initial Setup',
      evidenceDir
    );
    recordLockfileVerification(
      path.join(ignoredDir, 'package-lock.json'),
      EXPECTED.frontend_package_lock_sha256,
      'Ignored-scripts Copy Initial Setup',
      evidenceDir
    );
    console.log('Initial scratch copies lockfile integrity verified on both copies.');

    // ==========================================
    // SCENARIO A: NORMAL INSTALL
    // ==========================================
    currentStage = 'Scenario A: Normal Install (npm ci)';
    console.log('\n--- Scenario A: Normal Install (npm ci --include=dev --foreground-scripts) ---');
    const scAInstall = runCmd('npm', ['ci', '--include=dev', '--foreground-scripts'], normalDir, 'scenario-a-npm-ci.log', {}, evidenceDir, spawnSyncFn);
    console.log(`npm ci exit: ${scAInstall.status} in ${scAInstall.duration}s`);
    if (scAInstall.status !== 0) {
      throw new Error(`Scenario A npm ci failed with exit code ${scAInstall.status}`);
    }

    // Verify automatic root postinstall executed
    const hasPostinstall = scAInstall.stdout.includes('frontend@0.1.0 postinstall') &&
      scAInstall.stdout.includes('node scripts/once-ui-patch.cjs apply');
    const hasToolSuccess = scAInstall.stdout.includes('APPLIED AND VERIFIED: exact accepted Once UI patch');
    console.log('Root postinstall automatically executed:', hasPostinstall);
    console.log('Tool patch applied & verified:', hasToolSuccess);
    if (!hasPostinstall || !hasToolSuccess) {
      throw new Error('Scenario A failed: root postinstall did not execute automatically or tool did not report success.');
    }

    // Assert lockfile integrity after normal install
    currentStage = 'Scenario A: Lockfile Check Post-Install';
    recordLockfileVerification(
      path.join(normalDir, 'package-lock.json'),
      EXPECTED.frontend_package_lock_sha256,
      'Scenario A Post-Install',
      evidenceDir
    );

    // Measure 5 targets
    currentStage = 'Scenario A: Measure Targets Post-Install';
    const scAMeasured = measureTargets(normalDir, identityData.files);
    fs.writeFileSync(path.join(evidenceDir, 'scenario-a-measured-targets.json'), JSON.stringify(scAMeasured, null, 2));
    let scAAllFinal = true;
    for (const [relPath, m] of Object.entries(scAMeasured)) {
      console.log(`  ${relPath}: match final? ${m.matches_expected_after} (${m.actual_sha256})`);
      if (!m.matches_expected_after) scAAllFinal = false;
    }
    if (!scAAllFinal) {
      throw new Error('Scenario A failed: one or more targets do not match final accepted SHA256.');
    }

    // Explicit verify before build
    currentStage = 'Scenario A: Explicit Verify Before Build';
    console.log('\n--- Scenario A: Explicit Verify Before Build ---');
    const scAVerify = runCmd('node', ['scripts/once-ui-patch.cjs', 'verify'], normalDir, 'scenario-a-explicit-verify.log', {}, evidenceDir, spawnSyncFn);
    console.log(`Explicit verify exit: ${scAVerify.status}`);
    if (scAVerify.status !== 0) {
      throw new Error(`Scenario A explicit verify failed with exit code ${scAVerify.status}`);
    }

    // Typecheck
    currentStage = 'Scenario A: Typecheck (npm run typecheck)';
    console.log('\n--- Scenario A: Typecheck (npm run typecheck) ---');
    const scATypecheck = runCmd('npm', ['run', 'typecheck'], normalDir, 'scenario-a-typecheck.log', {}, evidenceDir, spawnSyncFn);
    console.log(`Typecheck exit: ${scATypecheck.status}`);
    if (scATypecheck.status !== 0) {
      throw new Error(`Scenario A typecheck failed with exit code ${scATypecheck.status}`);
    }

    // Build
    currentStage = 'Scenario A: Build (npm run build)';
    console.log('\n--- Scenario A: Build (npm run build) ---');
    const scABuild = runCmd('npm', ['run', 'build'], normalDir, 'scenario-a-build.log', {
      NEXT_PUBLIC_API_URL: 'http://localhost:8000/api',
      NEXT_TELEMETRY_DISABLED: '1'
    }, evidenceDir, spawnSyncFn);
    console.log(`Build exit: ${scABuild.status}`);
    if (scABuild.status !== 0) {
      throw new Error(`Scenario A build failed with exit code ${scABuild.status}`);
    }

    // Prebuild gate order verification
    currentStage = 'Scenario A: Prebuild Gate Order Verification';
    const prebuildCheck = validatePrebuildGate(scABuild.stdout);
    console.log('Prebuild gate executed before build verified:', prebuildCheck);

    // Assert lockfile integrity after build
    currentStage = 'Scenario A: Lockfile Check Post-Build';
    recordLockfileVerification(
      path.join(normalDir, 'package-lock.json'),
      EXPECTED.frontend_package_lock_sha256,
      'Scenario A Post-Build',
      evidenceDir
    );

    // Repeat apply & verify
    currentStage = 'Scenario A: Repeat Apply & Verify (No Drift)';
    console.log('\n--- Scenario A: Repeat Apply & Verify (No Drift) ---');
    const beforeRepeat = measureTargets(normalDir, identityData.files);
    fs.writeFileSync(path.join(evidenceDir, 'scenario-a-repeat-before-measured-targets.json'), JSON.stringify(beforeRepeat, null, 2));

    const scARepeatApply = runCmd('node', ['scripts/once-ui-patch.cjs', 'apply'], normalDir, 'scenario-a-repeat-apply.log', {}, evidenceDir, spawnSyncFn);
    const scARepeatVerify = runCmd('node', ['scripts/once-ui-patch.cjs', 'verify'], normalDir, 'scenario-a-repeat-verify.log', {}, evidenceDir, spawnSyncFn);

    const afterRepeat = measureTargets(normalDir, identityData.files);
    fs.writeFileSync(path.join(evidenceDir, 'scenario-a-repeat-after-measured-targets.json'), JSON.stringify(afterRepeat, null, 2));

    if (scARepeatApply.status !== 0 || scARepeatVerify.status !== 0) {
      throw new Error(`Scenario A repeat apply/verify failed: apply exit ${scARepeatApply.status}, verify exit ${scARepeatVerify.status}`);
    }

    validateTargetImmutability(beforeRepeat, afterRepeat, 'Scenario A repeat apply/verify');
    console.log('All 5 targets strictly identical after repeat apply/verify.');

    // Assert lockfile integrity after repeat
    currentStage = 'Scenario A: Lockfile Check Post-Repeat';
    recordLockfileVerification(
      path.join(normalDir, 'package-lock.json'),
      EXPECTED.frontend_package_lock_sha256,
      'Scenario A Post-Repeat',
      evidenceDir
    );

    ciResults.scenarios.normal = {
      status: 'PASS',
      install_exit: scAInstall.status,
      postinstall_automatic: hasPostinstall,
      targets_all_final: scAAllFinal,
      explicit_verify_exit: scAVerify.status,
      typecheck_exit: scATypecheck.status,
      build_exit: scABuild.status,
      prebuild_gate_verified: prebuildCheck,
      repeat_apply_exit: scARepeatApply.status,
      repeat_verify_exit: scARepeatVerify.status,
      repeat_identical: true,
      lockfile_intact: true
    };

    // ==========================================
    // SCENARIO B: IGNORED-SCRIPTS INSTALL & REFUSAL
    // ==========================================
    currentStage = 'Scenario B: Ignored-Scripts Install (npm ci --ignore-scripts)';
    console.log('\n--- Scenario B: Ignored-Scripts Install (npm ci --include=dev --ignore-scripts) ---');
    const scBInstall = runCmd('npm', ['ci', '--include=dev', '--ignore-scripts'], ignoredDir, 'scenario-b-npm-ci.log', {}, evidenceDir, spawnSyncFn);
    console.log(`npm ci --ignore-scripts exit: ${scBInstall.status} in ${scBInstall.duration}s`);
    if (scBInstall.status !== 0) {
      throw new Error(`Scenario B npm ci failed with exit code ${scBInstall.status}`);
    }

    // Assert lockfile integrity after ignored-scripts install
    currentStage = 'Scenario B: Lockfile Check Post-Install';
    recordLockfileVerification(
      path.join(ignoredDir, 'package-lock.json'),
      EXPECTED.frontend_package_lock_sha256,
      'Scenario B Post-Install',
      evidenceDir
    );

    // Measure actual original hashes BEFORE any apply or verify
    currentStage = 'Scenario B: Measure Originals';
    console.log('\n--- Scenario B: Measuring Actual Originals Before Verify/Apply ---');
    const scBPreApply = measureTargets(ignoredDir, identityData.files);
    fs.writeFileSync(path.join(evidenceDir, 'scenario-b-pre-apply-measured-targets.json'), JSON.stringify(scBPreApply, null, 2));
    let scBAllOriginal = true;
    for (const [relPath, m] of Object.entries(scBPreApply)) {
      console.log(`  ${relPath}: matches original? ${m.matches_expected_before} (${m.actual_sha256})`);
      if (!m.matches_expected_before) {
        scBAllOriginal = false;
        throw new Error(`Scenario B failed: file ${relPath} on unpatched install does not match original baseline SHA256 (actual: ${m.actual_sha256}, expected: ${identityData.files[relPath].before_sha256})`);
      }
    }

    // Explicit verify: MUST FAIL NONZERO (expected refusal gate)
    currentStage = 'Scenario B: Explicit Verify Refusal Gate';
    console.log('\n--- Scenario B: Explicit Verify on Unpatched Copy (Must Refuse) ---');
    const scBVerify = runCmd('node', ['scripts/once-ui-patch.cjs', 'verify'], ignoredDir, 'scenario-b-explicit-verify.log', {}, evidenceDir, spawnSyncFn);
    console.log(`Explicit verify exit code: ${scBVerify.status} (expected: 1)`);

    const refusalCheck = validateNegativeVerifyRefusal(scBVerify);
    console.log('Negative verify refusal gate successfully validated:', refusalCheck);

    // Re-measure targets after refusal (prove refusal did not mutate files)
    currentStage = 'Scenario B: Target Immutability After Refusal';
    const scBAfterRefusal = measureTargets(ignoredDir, identityData.files);
    fs.writeFileSync(path.join(evidenceDir, 'scenario-b-after-refusal-measured-targets.json'), JSON.stringify(scBAfterRefusal, null, 2));

    validateTargetImmutability(scBPreApply, scBAfterRefusal, 'Scenario B after negative refusal');
    console.log('Targets completely unchanged after refusal: verified.');

    // Assert lockfile integrity after refusal
    currentStage = 'Scenario B: Lockfile Check Post-Refusal';
    recordLockfileVerification(
      path.join(ignoredDir, 'package-lock.json'),
      EXPECTED.frontend_package_lock_sha256,
      'Scenario B Post-Refusal',
      evidenceDir
    );

    ciResults.scenarios.ignored_scripts = {
      status: 'PASS',
      install_exit: scBInstall.status,
      targets_all_original_before_apply: scBAllOriginal,
      expected_verify_refusal_exit: scBVerify.status,
      refusal_validation: refusalCheck,
      targets_unchanged_after_refusal: true,
      build_prevented: true,
      lockfile_intact: true
    };

    // ==========================================
    // SCENARIO C: EXPLICIT RECOVERY ON IGNORED-SCRIPTS
    // ==========================================
    currentStage = 'Scenario C: Explicit Recovery Apply';
    console.log('\n--- Scenario C: Explicit Recovery on Ignored-Scripts ---');

    // Run Apply to dedicated apply log
    const scCApply = runCmd('node', ['scripts/once-ui-patch.cjs', 'apply'], ignoredDir, 'scenario-c-recovery-apply.log', {}, evidenceDir, spawnSyncFn);
    console.log(`Recovery apply exit: ${scCApply.status}`);
    if (scCApply.status !== 0) {
      throw new Error(`Scenario C recovery apply failed with exit code ${scCApply.status}`);
    }

    // Assert lockfile after recovery apply
    currentStage = 'Scenario C: Lockfile Check Post-Recovery-Apply';
    recordLockfileVerification(
      path.join(ignoredDir, 'package-lock.json'),
      EXPECTED.frontend_package_lock_sha256,
      'Scenario C Post-Recovery-Apply',
      evidenceDir
    );

    // Run Verify to dedicated verify log
    currentStage = 'Scenario C: Explicit Recovery Verify';
    const scCVerify = runCmd('node', ['scripts/once-ui-patch.cjs', 'verify'], ignoredDir, 'scenario-c-recovery-verify.log', {}, evidenceDir, spawnSyncFn);
    console.log(`Recovery verify exit: ${scCVerify.status}`);
    if (scCVerify.status !== 0) {
      throw new Error(`Scenario C recovery verify failed with exit code ${scCVerify.status}`);
    }

    // Write consolidated non-overwritten recovery log
    const combinedRecoveryLog = `=== STAGE 1: RECOVERY APPLY ===\n` +
      scCApply.logContent +
      `\n=== STAGE 2: RECOVERY VERIFY ===\n` +
      scCVerify.logContent;
    fs.writeFileSync(path.join(evidenceDir, 'scenario-c-recovery.log'), combinedRecoveryLog);

    // Measure post-recovery targets
    currentStage = 'Scenario C: Measure Post-Recovery Targets';
    const scCPostRecovery = measureTargets(ignoredDir, identityData.files);
    fs.writeFileSync(path.join(evidenceDir, 'scenario-c-post-recovery-measured-targets.json'), JSON.stringify(scCPostRecovery, null, 2));
    let scCAllFinal = true;
    for (const [relPath, m] of Object.entries(scCPostRecovery)) {
      console.log(`  ${relPath}: match final after recovery? ${m.matches_expected_after} (${m.actual_sha256})`);
      if (!m.matches_expected_after) scCAllFinal = false;
    }
    if (!scCAllFinal) {
      throw new Error('Scenario C failed: targets do not match final accepted SHA256 after recovery.');
    }

    // Assert lockfile integrity after recovery verify
    currentStage = 'Scenario C: Lockfile Check Post-Recovery-Verify';
    recordLockfileVerification(
      path.join(ignoredDir, 'package-lock.json'),
      EXPECTED.frontend_package_lock_sha256,
      'Scenario C Post-Recovery-Verify',
      evidenceDir
    );

    ciResults.scenarios.recovery = {
      status: 'PASS',
      apply_exit: scCApply.status,
      verify_exit: scCVerify.status,
      targets_all_final: scCAllFinal,
      lockfile_unchanged: true,
      recovery_logs_separated: true
    };

    // ==========================================
    // CHECKOUT INTEGRITY CHECK
    // ==========================================
    currentStage = 'Scoped Checkout Integrity Verification';
    console.log('\n--- Capturing Raw Final Snapshot & Verifying Scoped Checkout Integrity ---');
    const finalCheckoutSnapshot = takeScopedCheckoutSnapshot(repoRoot, EXPECTED);
    fs.writeFileSync(path.join(evidenceDir, 'checkout-final-snapshot.json'), JSON.stringify(finalCheckoutSnapshot, null, 2));

    const integrityResult = verifyScopedCheckoutIntegrity(initialCheckoutSnapshot, finalCheckoutSnapshot, EXPECTED);
    fs.writeFileSync(path.join(evidenceDir, 'checkout-integrity.json'), JSON.stringify({
      timestamp: new Date().toISOString(),
      verified: integrityResult.verified,
      report: integrityResult.report,
      failures: integrityResult.failures,
      initial_snapshot_file: integrityResult.initial_snapshot_file,
      final_snapshot_file: integrityResult.final_snapshot_file
    }, null, 2));

    if (!integrityResult.verified) {
      throw new Error(`Scoped checkout integrity check FAILED:\n  ${integrityResult.failures.join('\n  ')}`);
    }
    console.log('Checkout integrity passed:', integrityResult.report);

    // Consolidate checksums.json
    currentStage = 'Consolidating Comprehensive Checksums';
    let lockStages = {};
    try {
      lockStages = JSON.parse(fs.readFileSync(path.join(evidenceDir, 'lockfile-verifications.json'), 'utf8'));
    } catch (_) {}

    const comprehensiveChecksums = {
      baseline_inputs: inputMeasurements.inputs,
      lockfile_verifications: lockStages,
      scratch_manifests: {
        normal_package_json: sha256(path.join(normalDir, 'package.json')),
        ignored_package_json: sha256(path.join(ignoredDir, 'package.json')),
        normal_package_lock: sha256(path.join(normalDir, 'package-lock.json')),
        ignored_package_lock: sha256(path.join(ignoredDir, 'package-lock.json'))
      },
      targets: {
        scenario_a_normal_final: scAMeasured,
        scenario_a_repeat_before: beforeRepeat,
        scenario_a_repeat_after: afterRepeat,
        scenario_b_pre_apply_original: scBPreApply,
        scenario_b_after_refusal: scBAfterRefusal,
        scenario_c_post_recovery_final: scCPostRecovery
      },
      scoped_checkout_evidence: {
        initial_snapshot: 'checkout-initial-snapshot.json',
        final_snapshot: 'checkout-final-snapshot.json',
        integrity_report: 'checkout-integrity.json'
      }
    };
    fs.writeFileSync(path.join(evidenceDir, 'checksums.json'), JSON.stringify(comprehensiveChecksums, null, 2));

    // Machine summary
    currentStage = 'Generating Machine Summary';
    const machineSummary = {
      platform: os.platform(),
      release: os.release(),
      arch: os.arch(),
      node_version: process.version,
      npm_version: npmVersion,
      total_memory_bytes: os.totalmem(),
      free_memory_bytes: os.freemem(),
      duration_seconds: ((Date.now() - startTime) / 1000).toFixed(2),
      github_context: githubContext
    };
    fs.writeFileSync(path.join(evidenceDir, 'machine-summary.json'), JSON.stringify(machineSummary, null, 2));

    // Final summary
    ciResults.overall_verdict = 'PASS';
    ciResults.duration_seconds = machineSummary.duration_seconds;
    ciResults.failed_stage = null;
    ciResults.error_message = null;
    fs.writeFileSync(path.join(evidenceDir, 'ci-summary.json'), JSON.stringify(ciResults, null, 2));

    console.log('\n========================================================');
    console.log('SUCCESS: All Ubuntu CI Install Lifecycle Scenarios PASSED.');
    console.log(`Artifacts generated in ${evidenceDir}`);
    console.log('========================================================\n');
  } catch (err) {
    console.error(`\nFATAL ERROR in runner at stage [${currentStage}]:`, err.message);
    writeEarlySummary('FAIL', currentStage, err.message);
    exitFn(1);
  }
};

if (require.main === module) {
  main();
} else {
  module.exports = {
    main,
    EXPECTED,
    SCRIPTS_TO_ADD,
    CANONICAL_INPUTS,
    verifyCanonicalInputs,
    REQUIRED_CHECKOUT_FILES,
    sha256,
    sanitize,
    measureTargets,
    validateTargetImmutability,
    validateLockfileIntegrity: recordLockfileVerification,
    recordLockfileVerification,
    validatePrebuildGate,
    validateNegativeVerifyRefusal,
    applyScratchScripts,
    takeScopedCheckoutSnapshot,
    verifyScopedCheckoutIntegrity,
    runCmd
  };
}
