const assert = require('node:assert/strict');
const { test } = require('node:test');
const fs = require('node:fs');
const path = require('node:path');
const vm = require('node:vm');
const ts = require('typescript');

const root = path.resolve(__dirname, '..');
function serverUser(affiliate = 'active') {
  return { id: 1, name: 'Synthetic User', email: 'synthetic@example.test',
    role: 'affiliate', telegram_chat_id: null, email_verified: true,
    is_active: true, created_at: '2026-01-01T00:00:00.000000Z',
    affiliate_profile: { id: 1, user_id: 1, referral_code: 'SYNTHETIC',
      commission_rate: 5, balance: 0, total_earned: 0, status: affiliate,
      approved_at: null, bank_name: null, bank_account_number: null,
      bank_account_holder: null, created_at: '2026-01-01T00:00:00.000000Z' } };
}

function harness({ token = 'credential', status = 200, affiliate = 'active',
  body = { data: serverUser(affiliate) } } = {}) {
  const oldUser = { ...serverUser(affiliate), email_verified: false };
  const local = new Map([['auth_user_storage', JSON.stringify({ state: {
    user: oldUser, authStatus: 'authenticated', isLoading: false }, version: 0 })]]);
  const storage = { getItem: (k) => local.get(k) ?? null,
    setItem: (k, v) => local.set(k, v), removeItem: (k) => local.delete(k) };
  let cookie = token ? `auth_token=${token}` : '';
  const responders = [];
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
      if (name === '@/components/AuthRetry') return load('components/AuthRetry.tsx');
      if (name.startsWith('@/src/lib/')) return load(`${name.slice(2)}.ts`);
      if (name === 'zustand' || name === 'zustand/middleware') return require(name);
      if (name === 'react') return {
        Suspense: 'Suspense',
        useCallback: (fn) => fn,
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
        useParams: () => ({ orderNumber: 'SYNTHETIC-ORDER' }),
        usePathname: () => '/orders/SYNTHETIC-ORDER',
        useSearchParams: () => ({ get: () => null, toString: () => '' }) };
      if (name === 'sonner') return { toast: { error() {}, info() {}, warning() {} } };
      return new Proxy({}, { get: (_, key) => key === '__esModule' ? true : String(key) });
    }
    vm.runInNewContext(source, { module, exports: module.exports, require: importModule,
      console, process: { env: {} }, document, window, localStorage: storage,
      sessionStorage: { getItem: () => null }, fetch: (url) => {
        requests.push(url);
        return new Promise((resolve, reject) => {
          responders.push({ resolve: (payload = body, code = status) => resolve(new Response(
            JSON.stringify(code === 401 ? { message: 'Unauthenticated' } : payload),
            { status: code, headers: { 'content-type': 'application/json' } })), reject });
        });
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
    if (tree?.type === 'Suspense') tree = tree.props.children.type();
    if (tree?.type?.name === 'AuthRetry') tree = tree.type(tree.props);
    rendering = false;
    return tree;
  }
  function runEffects() { for (const fn of pending.splice(0)) fn(); }
  return { realStore, local, document, requests, redirects, render, runEffects,
    resolve: async (payload = body, code = status, index = responders.length - 1) => {
      responders[index].resolve(payload, code); await new Promise(setImmediate);
    },
    reject: async (index = responders.length - 1) => {
      responders[index].reject(new Error('offline')); await new Promise(setImmediate);
    } };
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

test('canonical /user rejects malformed identities without asserting guest or deleting credentials', async () => {
  const invalid = [null, {}, serverUser(), { data: null }, { data: {} },
    ...[{ id: 0 }, { name: '' }, { email: null }, { role: 'admin' },
      { email_verified: 'true' }, { is_active: 1 }, { telegram_chat_id: 123 },
      { created_at: false }, { affiliate_profile: {} },
      { affiliate_profile: { ...serverUser().affiliate_profile, status: 'unexpected' } }]
      .map((fields) => ({ data: { ...serverUser(), ...fields } }))];
  for (const body of invalid) {
    const h = harness({ body });
    const validation = h.realStore.getState().fetchUser();
    await h.resolve(); await validation;
    const state = h.realStore.getState();
    assert.equal(state.authStatus, 'unknown', JSON.stringify(body));
    assert.equal(state.user, null);
    assert.equal(state.isLoading, false);
    assert.ok(state.authError, 'retryable validation error is exposed');
    assert.equal(h.document.cookie, 'auth_token=credential');
  }
});

test('omitted affiliate relation stays unresolved with an explicit canonical retry', async () => {
  const partial = serverUser(); delete partial.affiliate_profile;
  const h = harness({ body: { data: partial } });
  const first = h.realStore.getState().fetchUser(); await h.resolve(); await first;
  assert.equal(h.realStore.getState().authStatus, 'authenticated');
  assert.equal(Object.hasOwn(h.realStore.getState().user, 'affiliate_profile'), false);
  assert.ok(h.realStore.getState().authError);
  const retry = h.realStore.getState().fetchUser();
  await h.resolve({ data: { ...serverUser(), role: 'customer', affiliate_profile: null } }); await retry;
  assert.equal(h.realStore.getState().user.affiliate_profile, null);
  assert.equal(h.realStore.getState().authError, null);
});

test('setUser treats login/profile values as refresh hints, never authentication or missing-relation truth', async () => {
  const h = harness();
  const initial = serverUser(); delete initial.affiliate_profile;
  h.realStore.getState().setUser(initial);
  assert.equal(h.requests.length, 1);
  assert.equal(h.realStore.getState().authStatus, 'validating');
  assert.equal(h.realStore.getState().user, null);
  await h.resolve({ data: serverUser() });
  assert.equal(h.realStore.getState().authStatus, 'authenticated');
  h.realStore.getState().setUser({ ...initial, name: 'Unconfirmed change' });
  assert.equal(h.requests.length, 2);
  assert.notEqual(h.realStore.getState().user.name, 'Unconfirmed change');
  assert.equal(h.realStore.getState().user.affiliate_profile.status, 'active');
  await h.resolve({ data: { ...serverUser(), name: 'Confirmed change' } });
  assert.equal(h.realStore.getState().user.name, 'Confirmed change');
  h.realStore.getState().setUser(null);
  assert.equal(h.realStore.getState().authStatus, 'guest');
  assert.equal(h.realStore.getState().user, null);
});

test('W8AR F01 profile keeps a server-confirmed session after transient revalidation failure', async () => {
  const h = harness();
  const initial = h.realStore.getState().fetchUser(); await h.resolve(); await initial;
  h.render('app/profile/page.tsx'); h.runEffects();
  const prior = h.realStore.getState().user;
  const refresh = h.realStore.getState().fetchUser();
  await h.reject(); await refresh;
  h.render('app/profile/page.tsx'); h.runEffects();
  assert.deepEqual(h.redirects, [], 'transient failure must not send a valid session to login');
  assert.equal(h.realStore.getState().user, prior);
  assert.equal(h.realStore.getState().authStatus, 'authenticated');
  assert.ok(h.realStore.getState().authError);
});

test('W8AR F01 profile initial offline uncertainty neither redirects nor trusts cached user', async () => {
  const h = harness();
  const initial = h.realStore.getState().fetchUser(); await h.reject(); await initial;
  h.render('app/profile/page.tsx'); h.runEffects();
  assert.deepEqual(h.redirects, [], 'unknown is not confirmed guest');
  assert.equal(h.realStore.getState().user, null);
  assert.equal(h.realStore.getState().authStatus, 'unknown');
  assert.equal(h.realStore.getState().isLoading, false);
});

const protectedPages = [
  ['profile', '/login?redirect=/profile'],
  ['orders', '/login?redirect=/orders'],
  ['orders/[orderNumber]', '/login?redirect=/orders/SYNTHETIC-ORDER'],
  ['checkout', '/login?redirect=/checkout'],
  ['telegram/setup', '/login?redirect=/telegram/setup'],
  ...['dashboard', 'register', 'clicks', 'commissions', 'withdraw', 'pending', 'inactive']
    .map((page) => [`affiliate/${page}`, `/login?redirect=/affiliate/${page}`]),
  ['affiliate/rejected', '/login'],
];

function nodes(tree) {
  if (!tree || typeof tree !== 'object') return [];
  return [tree, ...[tree.props?.children].flat(Infinity).flatMap(nodes)];
}
function retryButton(tree) {
  return nodes(tree).find((node) => node.type === 'button' &&
    node.props.children === 'Coba lagi');
}

for (const [route, login] of protectedPages) {
  const page = `app/${route}/page.tsx`;
  test(`W8AR F01 ${route}: initial offline shows reachable canonical retry, not guest or cached identity`, async () => {
    const h = harness();
    const first = h.realStore.getState().fetchUser(); await h.reject(); await first;
    const tree = h.render(page); h.runEffects();
    assert.deepEqual(h.redirects, [], 'unknown must not redirect');
    assert.equal(h.requests.length, 1, 'unknown must not load protected data');
    assert.equal(h.realStore.getState().user, null, 'cached-only identity stays discarded');
    const button = retryButton(tree);
    assert.ok(button, 'settled uncertainty exposes a retry rather than endless loading');
    const errorText = h.realStore.getState().authError;
    assert.ok(nodes(tree).some((node) => node.props?.children === errorText), 'canonical error is visible');
    button.props.onClick();
    assert.equal(h.requests.length, 2, 'retry calls canonical fetchUser');
    assert.ok(h.requests[1].endsWith('/user'));
    assert.equal(h.realStore.getState().isLoading, true);
    assert.equal(retryButton(h.render(page)), undefined, 'no error/retry UI while validating');
    h.runEffects(); assert.deepEqual(h.redirects, []);
    await h.resolve(undefined, 401);
    h.render(page); h.runEffects();
    assert.deepEqual(h.redirects, [login], 'current 401 still redirects to login');
  });
  test(`W8AR F01 ${route}: initial loading does not flash auth error`, () => {
    const h = harness();
    assert.equal(h.realStore.getState().isLoading, true);
    const tree = h.render(page); h.runEffects();
    assert.equal(retryButton(tree), undefined);
    assert.deepEqual(h.redirects, []);
    assert.deepEqual(h.requests, []);
  });
  test(`W8AR F01 ${route}: confirmed guest redirects without protected requests`, async () => {
    const h = harness({ token: null });
    await h.realStore.getState().fetchUser();
    h.render(page); h.runEffects();
    assert.deepEqual(h.redirects, [login]);
    assert.deepEqual(h.requests, []);
  });
  test(`W8AR F01 ${route}: confirmed session current 401 still redirects`, async () => {
    const h = harness();
    const first = h.realStore.getState().fetchUser(); await h.resolve(); await first;
    const refresh = h.realStore.getState().fetchUser(); await h.resolve(undefined, 401); await refresh;
    h.render(page); h.runEffects();
    assert.equal(h.realStore.getState().user, null);
    assert.equal(h.realStore.getState().authStatus, 'guest');
    assert.deepEqual(h.redirects, [login]);
    assert.equal(h.requests.length, 2);
  });
}

test('logout while /user is in flight cannot restore authenticated user', async () => {
  const h = harness(); const validation = h.realStore.getState().fetchUser();
  h.realStore.getState().clearUser(); await h.resolve(); await validation;
  assert.equal(h.realStore.getState().authStatus, 'guest');
  assert.equal(h.realStore.getState().user, null);
});
