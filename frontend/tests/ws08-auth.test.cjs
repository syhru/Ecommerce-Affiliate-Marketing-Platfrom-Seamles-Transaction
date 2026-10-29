/* eslint-disable @typescript-eslint/no-require-imports -- Node's CommonJS test harness. */
const assert = require('node:assert/strict');
const { test } = require('node:test');
const fs = require('node:fs');
const path = require('node:path');
const vm = require('node:vm');
const ts = require('typescript');

const root = path.resolve(__dirname, '..');
const canonicalUser = (fields = {}) => ({
  id: 1, name: 'Canonical User', email: 'canonical@example.test', role: 'customer',
  email_verified: true, is_active: true, telegram_chat_id: null, created_at: null,
  affiliate_profile: null, ...fields,
});

function harness() {
  let cookie = 'auth_token=original';
  const local = new Map();
  const session = new Map();
  const storage = (map) => ({ getItem: (key) => map.get(key) ?? null,
    setItem: (key, value) => map.set(key, value), removeItem: (key) => map.delete(key) });
  const document = { get cookie() { return cookie; }, set cookie(value) {
    cookie = value.includes('Max-Age=0') ? '' : value;
  } };
  const requests = [];
  const cache = new Map();
  function load(relative) {
    if (cache.has(relative)) return cache.get(relative).exports;
    const loadedModule = { exports: {} };
    cache.set(relative, loadedModule);
    const source = ts.transpileModule(fs.readFileSync(path.join(root, relative), 'utf8'), {
      fileName: relative, compilerOptions: { module: ts.ModuleKind.CommonJS,
        target: ts.ScriptTarget.ES2020, jsx: ts.JsxEmit.ReactJSX, esModuleInterop: true },
    }).outputText;
    vm.runInNewContext(source, { module: loadedModule, exports: loadedModule.exports,
      require: (name) => name.startsWith('@/') ? load(`${name.slice(2)}.ts`) : require(name),
      console, process: { env: {} }, document, window: { location: { protocol: 'http:' } },
      localStorage: storage(local), sessionStorage: storage(session),
      fetch: (url, options) => new Promise((resolve, reject) => {
        requests.push({ url, options, reject, resolve: (body = {}, status = 200) => resolve(
          new Response(JSON.stringify(body), { status, headers: { 'content-type': 'application/json' } })) });
      }),
    });
    return loadedModule.exports;
  }
  const store = load('src/stores/useUserStore.ts').useUserStore;
  const auth = load('src/lib/auth.ts');
  return { store, auth, requests, document, local, session,
    async authenticate(user = canonicalUser()) {
      const pending = store.getState().fetchUser();
      requests.at(-1).resolve({ data: user });
      await pending;
      assert.equal(store.getState().authStatus, 'authenticated');
    },
  };
}

test('WS08 concurrent logout shares one request, clears only after success, and permits retry', async () => {
  const h = harness(); await h.authenticate();
  const first = h.auth.logout();
  const second = h.auth.logout();
  assert.equal(first, second, 'all callers observe the same completion');
  assert.equal(h.requests.length, 2, 'one user request plus one logout');
  assert.equal(h.store.getState().authStatus, 'authenticated');
  assert.equal(h.document.cookie, 'auth_token=original');
  h.requests.at(-1).resolve({ message: 'Logged out' });
  await Promise.all([first, second]);
  assert.equal(h.store.getState().authStatus, 'guest');
  assert.equal(h.store.getState().user, null);
  assert.equal(h.document.cookie, '');
  assert.equal(h.local.has('auth_user_storage'), false);
  assert.equal(h.session.get('tdr_is_logging_out'), 'true');
  h.document.cookie = 'auth_token=replacement';
  const retry = h.auth.logout();
  assert.equal(h.requests.length, 3);
  h.requests.at(-1).resolve(); await retry;
});

test('WS08 stale successful logout rejects instead of clearing a replacement session', async () => {
  const h = harness(); await h.authenticate();
  const pending = h.auth.logout();
  const logoutRequest = h.requests.at(-1);
  const rejected = assert.rejects(pending, /session changed/i);
  h.document.cookie = 'auth_token=replacement';
  await h.authenticate(canonicalUser({ id: 2, name: 'Replacement User' }));
  logoutRequest.resolve({ message: 'Logged out' });
  await rejected;
  assert.equal(h.document.cookie, 'auth_token=replacement');
  assert.equal(h.store.getState().user.id, 2);
  assert.equal(h.store.getState().authStatus, 'authenticated');
  assert.equal(h.session.has('tdr_is_logging_out'), false);
  assert.ok(h.local.has('auth_user_storage'));
});

test('WS08 logout rejects offline failure and preserves validated session for retry', async () => {
  const h = harness(); await h.authenticate();
  const before = h.store.getState().user;
  const failure = assert.rejects(h.auth.logout(), /offline/);
  assert.equal(h.requests.at(-1).options.method, 'POST');
  assert.ok(h.requests.at(-1).url.endsWith('/logout'));
  h.requests.at(-1).reject(new Error('offline'));
  await failure;
  assert.equal(h.document.cookie, 'auth_token=original');
  assert.equal(h.store.getState().user, before);
  assert.equal(h.store.getState().authStatus, 'authenticated');
  assert.equal(h.session.has('tdr_is_logging_out'), false);
  const retry = h.auth.logout();
  h.requests.at(-1).resolve(); await retry;
  assert.equal(h.store.getState().authStatus, 'guest');
});

for (const status of [401, 403, 500]) {
  test(`WS08 logout HTTP ${status} rejects all callers without clearing local auth`, async () => {
    const h = harness(); await h.authenticate();
    const first = h.auth.logout();
    const second = h.auth.logout();
    assert.equal(first, second);
    const rejected = Promise.all([first, second].map((pending) => assert.rejects(pending,
      (error) => error.status === status)));
    h.requests.at(-1).resolve({ message: 'Logout failed' }, status);
    await rejected;
    assert.equal(h.requests.length, 2);
    assert.equal(h.document.cookie, 'auth_token=original');
    assert.equal(h.store.getState().authStatus, 'authenticated');
    assert.equal(h.session.has('tdr_is_logging_out'), false);
  });
}

test('WS08 offline canonical revalidation preserves confirmed identity and exposes retry error', async () => {
  const h = harness(); await h.authenticate();
  const pending = h.store.getState().fetchUser();
  h.requests.at(-1).reject(new Error('offline')); await pending;
  assert.equal(h.store.getState().authStatus, 'authenticated');
  assert.equal(h.store.getState().user.id, 1);
  assert.equal(h.store.getState().isLoading, false);
  assert.ok(h.store.getState().authError);
  assert.equal(h.document.cookie, 'auth_token=original');
  await h.authenticate();
  assert.equal(h.store.getState().authError, null);
});

test('W8AR F02 fetch401 before logout200 cannot turn genuine success into failure', async () => {
  const h = harness(); await h.authenticate();
  h.local.set('tdr_cart', '[{"quantity":3}]');
  const refresh = h.store.getState().fetchUser();
  const userRequest = h.requests.at(-1);
  const logout = h.auth.logout();
  const logoutRequest = h.requests.at(-1);
  const outcome = logout.then(() => 'success', error => error.message);
  userRequest.resolve({ message: 'Unauthenticated' }, 401); await refresh;
  logoutRequest.resolve({ message: 'Logged out' });
  assert.equal(await outcome, 'success');
  assert.equal(h.store.getState().authStatus, 'guest');
  assert.equal(h.document.cookie, '');
  assert.equal(h.local.get('tdr_cart'), '[{"quantity":3}]');
});

for (const status of [0, 403, 500]) {
  test(`W8AR F01 initial failure ${status} is unknown but revalidation keeps only confirmed identity`, async () => {
    const h = harness();
    const initial = h.store.getState().fetchUser();
    if (status) h.requests.at(-1).resolve({ message: 'Unavailable' }, status);
    else h.requests.at(-1).reject(new Error('offline'));
    await initial;
    assert.equal(h.store.getState().user, null);
    assert.equal(h.store.getState().authStatus, 'unknown');
    assert.equal(h.store.getState().isLoading, false);
    await h.authenticate();
    const prior = h.store.getState().user;
    const refresh = h.store.getState().fetchUser();
    if (status) h.requests.at(-1).resolve({ message: 'Unavailable' }, status);
    else h.requests.at(-1).reject(new Error('offline'));
    await refresh;
    assert.equal(h.store.getState().user, prior);
    assert.equal(h.store.getState().authStatus, 'authenticated');
    assert.ok(h.store.getState().authError);
    assert.equal(h.document.cookie, 'auth_token=original');
  });
}

for (const replacement of ['', 'auth_token=replacement']) {
  for (const succeeds of [false, true]) {
    test(`W8AR F01 changed credential ${replacement || 'removed'} invalidates prior identity on old success=${succeeds}`, async () => {
      const h = harness(); await h.authenticate();
      const refresh = h.store.getState().fetchUser();
      h.document.cookie = replacement;
      if (succeeds) h.requests.at(-1).resolve({ data: canonicalUser() });
      else h.requests.at(-1).reject(new Error('offline'));
      await refresh;
      assert.equal(h.store.getState().user, null);
      assert.equal(h.store.getState().authStatus, replacement ? 'unknown' : 'guest');
      assert.equal(h.store.getState().isLoading, false);
      assert.equal(h.document.cookie, replacement);
    });
  }
}

test('W8AR F01 replacement refresh cannot retain prior identity on failure and current401 still clears auth', async () => {
  const h = harness(); await h.authenticate();
  h.document.cookie = 'auth_token=replacement';
  const refresh = h.store.getState().fetchUser();
  assert.equal(h.store.getState().user, null);
  h.requests.at(-1).reject(new Error('offline')); await refresh;
  assert.equal(h.store.getState().user, null);
  assert.equal(h.store.getState().authStatus, 'unknown');
  await h.authenticate(canonicalUser({ id: 2 }));
  const expired = h.store.getState().fetchUser();
  h.requests.at(-1).resolve({ message: 'Unauthenticated' }, 401); await expired;
  assert.equal(h.store.getState().authStatus, 'guest');
  assert.equal(h.store.getState().user, null);
  assert.equal(h.document.cookie, '');
});

test('W8AR F02 logout suspends same-session refresh until failure then permits retry', async () => {
  const h = harness(); await h.authenticate();
  const prior = h.store.getState().user;
  const old = h.store.getState().fetchUser();
  const oldRequest = h.requests.at(-1);
  const failed = assert.rejects(h.auth.logout(), /Failed/);
  const logoutRequest = h.requests.at(-1);
  await h.store.getState().fetchUser();
  assert.equal(h.requests.length, 3, 'no new user request while logout is pending');
  oldRequest.resolve({ message: 'Unauthenticated' }, 401); await old;
  logoutRequest.resolve({ message: 'Failed' }, 500); await failed;
  assert.equal(h.store.getState().user, prior);
  assert.equal(h.store.getState().authStatus, 'authenticated');
  assert.equal(h.store.getState().isLoading, false);
  assert.equal(h.document.cookie, 'auth_token=original');
  await h.authenticate();
});

test('W8AR F02 absent or replacement cookie is not treated as successful logout', async () => {
  for (const cookie of ['', 'auth_token=replacement']) {
    const h = harness(); await h.authenticate();
    const pending = h.auth.logout();
    const outcome = assert.rejects(pending, /session changed/i);
    h.document.cookie = cookie;
    h.requests.at(-1).resolve({ message: 'Logged out' }); await outcome;
    assert.equal(h.document.cookie, cookie);
    assert.equal(h.store.getState().user, null, 'old identity must not be attributed to replacement');
    assert.equal(h.store.getState().authStatus, cookie ? 'unknown' : 'guest');
    assert.equal(h.session.has('tdr_is_logging_out'), false);
  }
});

test('W8AR F02 replacement validation pending at logout completion remains authoritative', async () => {
  const h = harness(); await h.authenticate();
  const logout = h.auth.logout();
  const logoutRequest = h.requests.at(-1);
  const rejected = assert.rejects(logout, /session changed/i);
  h.document.cookie = 'auth_token=replacement';
  const validation = h.store.getState().fetchUser();
  const userRequest = h.requests.at(-1);
  logoutRequest.resolve(); await rejected;
  assert.equal(h.store.getState().authStatus, 'validating');
  userRequest.resolve({ data: canonicalUser({ id: 2 }) }); await validation;
  assert.equal(h.store.getState().user.id, 2);
  assert.equal(h.document.cookie, 'auth_token=replacement');
});

test('W8AR F01 malformed revalidation never accepts supplied identity or privileges', async () => {
  const h = harness(); await h.authenticate();
  const prior = h.store.getState().user;
  const pending = h.store.getState().fetchUser();
  h.requests.at(-1).resolve({ data: { role: 'superadmin', id: 9 } }); await pending;
  assert.equal(h.store.getState().user, prior);
  assert.equal(h.store.getState().user.role, 'customer');
  assert.ok(h.store.getState().authError);
});

test('WS08 latest canonical refresh wins when responses complete out of order', async () => {
  const h = harness();
  const old = h.store.getState().fetchUser();
  const oldRequest = h.requests.at(-1);
  const current = h.store.getState().fetchUser();
  h.requests.at(-1).resolve({ data: canonicalUser({ name: 'Current' }) }); await current;
  oldRequest.resolve({ data: canonicalUser({ name: 'Stale' }) }); await old;
  assert.equal(h.store.getState().user.name, 'Current');
  assert.equal(h.store.getState().authStatus, 'authenticated');
});

for (const status of [200, 401]) {
  test(`WS08 old credential /user ${status} cannot replace or clear a newly validated session`, async () => {
    const h = harness();
    const old = h.store.getState().fetchUser();
    const oldRequest = h.requests.at(-1);
    h.document.cookie = 'auth_token=replacement';
    await h.authenticate(canonicalUser({ id: 2 }));
    oldRequest.resolve({ data: canonicalUser() }, status); await old;
    assert.equal(h.document.cookie, 'auth_token=replacement');
    assert.equal(h.store.getState().user.id, 2);
    assert.equal(h.store.getState().authStatus, 'authenticated');
  });
}

test('WS08 setUser(null) invalidates in-flight refresh without accepting a later response', async () => {
  const h = harness();
  const pending = h.store.getState().fetchUser();
  h.store.getState().setUser(null);
  h.requests.at(-1).resolve({ data: canonicalUser() }); await pending;
  assert.equal(h.store.getState().user, null);
  assert.equal(h.store.getState().authStatus, 'guest');
  assert.equal(h.store.getState().isLoading, false);
});

test('WS08 successful logout invalidates an outstanding canonical refresh', async () => {
  const h = harness(); await h.authenticate();
  const refresh = h.store.getState().fetchUser();
  const refreshRequest = h.requests.at(-1);
  const logout = h.auth.logout();
  h.requests.at(-1).resolve(); await logout;
  refreshRequest.resolve({ data: canonicalUser() }); await refresh;
  assert.equal(h.store.getState().authStatus, 'guest');
  assert.equal(h.store.getState().user, null);
  assert.equal(h.document.cookie, '');
});

for (const status of [200, 401]) {
  test(`WS08 token replacement alone makes an old /user ${status} response inert`, async () => {
    const h = harness();
    const pending = h.store.getState().fetchUser();
    h.document.cookie = 'auth_token=replacement';
    h.requests.at(-1).resolve({ data: canonicalUser() }, status); await pending;
    assert.equal(h.store.getState().user, null);
    assert.notEqual(h.store.getState().authStatus, 'authenticated');
    assert.equal(h.document.cookie, 'auth_token=replacement');
    await h.authenticate(canonicalUser({ id: 2 }));
    assert.equal(h.store.getState().user.id, 2);
  });
}
