const assert = require('node:assert/strict');
const { test } = require('node:test');
const fs = require('node:fs');
const path = require('node:path');
const vm = require('node:vm');
const ts = require('typescript');

const root = path.resolve(__dirname, '..');
function harness({ token = 'credential', status = 200, affiliate = 'active' } = {}) {
  const oldUser = { id: 1, name: 'Old', role: 'affiliate', email_verified: false,
    affiliate_profile: { status: affiliate } };
  const local = new Map([['auth_user_storage', JSON.stringify({ state: {
    user: oldUser, authStatus: 'authenticated', isLoading: false }, version: 0 })]]);
  const storage = { getItem: (k) => local.get(k) ?? null,
    setItem: (k, v) => local.set(k, v), removeItem: (k) => local.delete(k) };
  let cookie = token ? `auth_token=${token}` : '';
  let resolveRequest;
  const requests = [];
  const redirects = [];
  const effects = new Map();
  let effectIndex = 0;
  let stateIndex = 0;
  let rendering = false;
  const cache = new Map();
  const document = { get cookie() { return cookie; }, set cookie(v) {
    cookie = v.includes('Max-Age=0') ? '' : v;
  } };
  const window = { location: { protocol: 'http:', replace: (url) => {
    assert.equal(rendering, false); redirects.push(url);
  } } };
  const jsx = (type, props) => ({ type, props });
  let store;
  function load(relative) {
    if (cache.has(relative)) return cache.get(relative).exports;
    const module = { exports: {} }; cache.set(relative, module);
    const source = ts.transpileModule(fs.readFileSync(path.join(root, relative), 'utf8'), {
      fileName: relative, compilerOptions: { module: ts.ModuleKind.CommonJS,
        jsx: ts.JsxEmit.ReactJSX, target: ts.ScriptTarget.ES2020, esModuleInterop: true },
    }).outputText;
    function importModule(name) {
      if (name === '@/src/stores/useUserStore') return { useUserStore: store };
      if (name.startsWith('@/src/lib/')) return load(`${name.slice(2)}.ts`);
      if (name === 'zustand' || name === 'zustand/middleware') return require(name);
      if (name === 'react') return {
        Suspense: 'Suspense',
        useState: (initial) => { stateIndex++; return [initial, () => {
          assert.equal(rendering, false, 'state update during render');
        }]; },
        useEffect: (fn, deps) => {
          const index = effectIndex++;
          const previous = effects.get(index);
          if (!previous || deps.some((dep, i) => dep !== previous.deps[i])) {
            effects.set(index, { deps, fn }); pending.push(fn);
          }
        },
      };
      if (name === 'react/jsx-runtime') return { jsx, jsxs: jsx };
      if (name === 'next/navigation') return { useRouter: () => ({
        replace: (url) => redirects.push(url), push: (url) => redirects.push(url) }),
        useSearchParams: () => ({ get: () => null }) };
      if (name === 'sonner') return { toast: { error() {}, info() {}, warning() {} } };
      return new Proxy({}, { get: (_, key) => key === '__esModule' ? true : String(key) });
    }
    vm.runInNewContext(source, { module, exports: module.exports, require: importModule,
      console, process, document, window, localStorage: storage,
      sessionStorage: { getItem: () => null }, fetch: (url) => {
        requests.push(url);
        return new Promise((resolve) => { resolveRequest = () => resolve(new Response(
          JSON.stringify(status === 401 ? { message: 'Unauthenticated' } : { ...oldUser, email_verified: true }),
          { status, headers: { 'content-type': 'application/json' } })); });
      } });
    return module.exports;
  }
  const realStore = load('src/stores/useUserStore.ts').useUserStore;
  store = (selector) => selector ? selector(realStore.getState()) : realStore.getState();
  store.getState = realStore.getState;
  const pending = [];
  function render(file) {
    effectIndex = 0; stateIndex = 0; rendering = true;
    let tree = load(file).default();
    // /login wraps its inner component in Suspense.
    if (tree.type === 'Suspense') tree = tree.props.children.type();
    rendering = false;
    return tree;
  }
  function runEffects() { for (const fn of pending.splice(0)) fn(); }
  return { realStore, local, document, requests, redirects, render, runEffects,
    resolve: async () => { resolveRequest(); await new Promise(setImmediate); } };
}

for (const page of ['app/login/page.tsx', 'app/register/page.tsx']) {
  test(`A1 ${page}: persisted user + stale credential cannot redirect, 401 clears state`, async () => {
    const h = harness({ status: 401 });
    assert.equal(h.realStore.getState().authStatus, 'unknown');
    assert.equal(h.realStore.getState().user, null, 'historical hydration is discarded');
    h.render(page); h.runEffects();
    const validation = h.realStore.getState().fetchUser();
    h.render(page); h.runEffects();
    assert.deepEqual(h.redirects, []);
    await h.resolve(); await validation;
    h.render(page); h.runEffects();
    assert.equal(h.realStore.getState().authStatus, 'guest');
    assert.equal(h.realStore.getState().user, null);
    assert.equal(h.document.cookie, '');
    assert.equal(JSON.parse(h.local.get('auth_user_storage')).state.user, null);
    assert.deepEqual(h.redirects, []);
  });
  test(`A2 ${page}: valid credential permits redirect only after /user succeeds`, async () => {
    const h = harness(); h.render(page); h.runEffects();
    const validation = h.realStore.getState().fetchUser();
    h.render(page); h.runEffects();
    assert.equal(h.realStore.getState().authStatus, 'validating');
    assert.deepEqual(h.redirects, []);
    await h.resolve(); await validation;
    h.render(page); h.runEffects();
    assert.equal(h.realStore.getState().authStatus, 'authenticated');
    assert.deepEqual(h.redirects, ['/']);
    assert.ok(h.requests[0].endsWith('/user'));
  });
  test(`A3 ${page}: no credential keeps guest route open`, async () => {
    const h = harness({ token: null });
    await h.realStore.getState().fetchUser(); h.render(page); h.runEffects();
    assert.equal(h.realStore.getState().authStatus, 'guest');
    assert.deepEqual(h.requests, []); assert.deepEqual(h.redirects, []);
  });
}

test('B1 email-verified: mount refreshes canonical /user once, updates email_verified without render loop', async () => {
  const h = harness(); h.render('app/email-verified/page.tsx');
  assert.equal(h.requests.length, 0, 'no side effect during render');
  h.runEffects(); assert.equal(h.requests.length, 1);
  await h.resolve();
  assert.equal(h.realStore.getState().user.email_verified, true);
  h.render('app/email-verified/page.tsx'); h.runEffects();
  assert.equal(h.requests.length, 1, 'stable effect does not repeat on state update');
});

test('B1 email-verified: refresh 401 converges to guest', async () => {
  const h = harness({ status: 401 }); h.render('app/email-verified/page.tsx'); h.runEffects();
  await h.resolve(); assert.equal(h.realStore.getState().authStatus, 'guest');
  assert.equal(h.document.cookie, ''); assert.equal(h.realStore.getState().user, null);
});

for (const status of ['inactive', 'rejected', 'pending']) {
  test(`C1 dashboard ${status}: resolves to /affiliate/${status}, never /`, async () => {
    const h = harness({ affiliate: status }); const validation = h.realStore.getState().fetchUser();
    await h.resolve(); await validation;
    h.render('app/affiliate/dashboard/page.tsx'); h.runEffects();
    assert.deepEqual(h.redirects, [`/affiliate/${status}`]);
  });
}

test('inactive affiliate direct page stays explanatory without reactivation or redirect', async () => {
  const h = harness({ affiliate: 'inactive' });
  const validation = h.realStore.getState().fetchUser();
  await h.resolve(); await validation;
  h.render('app/affiliate/inactive/page.tsx'); h.runEffects();
  assert.deepEqual(h.redirects, []);
  const source = fs.readFileSync(path.join(root, 'app/affiliate/inactive/page.tsx'), 'utf8');
  assert.match(source, /Akun Affiliate Nonaktif/);
  assert.doesNotMatch(source, /apiPost|apiPut|apiPatch/);
});

test('logout while /user is in flight cannot restore authenticated user', async () => {
  const h = harness(); const validation = h.realStore.getState().fetchUser();
  h.realStore.getState().clearUser(); await h.resolve(); await validation;
  assert.equal(h.realStore.getState().authStatus, 'guest');
  assert.equal(h.realStore.getState().user, null);
});
