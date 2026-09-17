/* eslint-disable @typescript-eslint/no-require-imports -- Node's dependency-free CommonJS test runner. */
const assert = require('node:assert/strict');
const { test } = require('node:test');
const fs = require('node:fs');
const path = require('node:path');
const vm = require('node:vm');
const ts = require('typescript');

const root = path.resolve(__dirname, '..');

// Transpile the real production modules with installed TypeScript, without a test dependency.
function harness({ failLogout = false, failPassword = false, role = 'customer', verified = true, states = {} } = {}) {
  const events = [];
  const local = new Map();
  const session = new Map();
  const storage = (data) => ({
    getItem: (key) => data.get(key) ?? null,
    setItem: (key, value) => data.set(key, value),
    removeItem: (key) => data.delete(key),
    clear: () => data.clear(),
  });
  const user = { id: 1, name: 'Test', email: 'test@example.com', role, telegram_chat_id: null, email_verified: verified };
  let state = { user, isLoading: false, setUser: (value) => { state.user = value; },
    clearUser: () => { events.push('clear-user'); state.user = null; }, fetchUser: async () => {} };
  const store = () => state;
  store.getState = () => state;
  store.persist = { clearStorage: () => local.delete('auth_user_storage') };
  let cookie = 'auth_token=test-token';
  const document = { getElementById: () => ({ focus: () => events.push('focus-password') }), get cookie() { return cookie; }, set cookie(value) { cookie = value; events.push('clear-cookie'); } };
  const window = { location: { href: '' } };
  let hook = 0;
  const react = {
    useState: (initial) => { const index = hook++; return [Object.hasOwn(states, index) ? states[index] : initial, () => {}]; },
    useEffect: () => {},
  };
  const jsx = (type, props) => ({ type, props });
  const cache = new Map();
  function load(filename) {
    if (cache.has(filename)) return cache.get(filename).exports;
    const loadedModule = { exports: {} };
    cache.set(filename, loadedModule);
    const source = ts.transpileModule(fs.readFileSync(filename, 'utf8'), {
      fileName: filename,
      compilerOptions: { module: ts.ModuleKind.CommonJS, jsx: ts.JsxEmit.ReactJSX, target: ts.ScriptTarget.ES2020, esModuleInterop: true },
    }).outputText;
    function requireModule(name) {
      if (name === '@/src/stores/useUserStore') return { useUserStore: store };
      if (name === 'react') return react;
      if (name === 'react/jsx-runtime') return { jsx, jsxs: jsx };
      if (name === 'next/navigation') return { useRouter: () => ({ push: (url) => events.push(url) }), usePathname: () => '/' };
      if (name === 'sonner') return { toast: { success: () => {}, error: () => {}, warning: () => {} } };
      if (name.startsWith('@/src/lib/')) return load(path.join(root, `${name.slice(2)}.ts`));
      return new Proxy({}, { get: (_, key) => key === '__esModule' ? true : String(key) });
    }
    const context = { exports: loadedModule.exports, module: loadedModule, require: requireModule, console, process,
      document, window, localStorage: storage(local), sessionStorage: storage(session),
      fetch: async (url, options) => {
        events.push({ url, options });
        if (url.endsWith('/logout') && failLogout) throw new Error('offline');
        if (url.endsWith('/user/password') && failPassword) return new Response(JSON.stringify({ message: 'Invalid password' }), { status: 422, headers: { 'content-type': 'application/json' } });
        return new Response(JSON.stringify({ message: 'OK', user }), { headers: { 'content-type': 'application/json' } });
      },
    };
    vm.runInNewContext(source, context, { filename });
    return loadedModule.exports;
  }
  local.set('auth_user_storage', 'cached-user');
  local.set('tdr_cart', 'keep-cart');
  return { load: (file) => load(path.join(root, file)), events, local, session, state, window, document };
}

function nodes(tree, predicate) {
  if (!tree || typeof tree !== 'object') return [];
  if (Array.isArray(tree)) return tree.flatMap((child) => nodes(child, predicate));
  return [...(predicate(tree) ? [tree] : []), ...nodes(tree.props?.children, predicate)];
}

for (const failLogout of [false, true]) {
  test(`logout attempts backend before cleanup, backend failure=${failLogout}`, async () => {
    const h = harness({ failLogout });
    await h.load('src/lib/auth.ts').logout();
    assert.equal(h.events[0]?.url, 'http://localhost:8000/api/logout');
    assert.equal(h.events[0]?.options.method, 'POST');
    assert.equal(h.events[0]?.options.headers.Authorization, 'Bearer test-token');
    assert.equal(h.state.user, null);
    assert.match(h.document.cookie, /Max-Age=0/);
    assert.equal(h.local.has('auth_user_storage'), false);
    assert.equal(h.local.get('tdr_cart'), 'keep-cart');
    assert.equal(h.session.get('tdr_is_logging_out'), 'true');
  });
}

test('Navbar logout uses backend revocation before redirecting', async () => {
  const h = harness({ states: { 3: true, 4: true } });
  const tree = h.load('components/Navbar.tsx').Navbar();
  const buttons = nodes(tree, (node) => node.props?.onClick?.name === 'handleLogout');
  assert.ok(buttons.length);
  await buttons[0].props.onClick();
  assert.equal(h.events[0]?.url, 'http://localhost:8000/api/logout');
  assert.equal(h.window.location.href, '/login');
});

test('login helper uses the live login endpoint', async () => {
  const h = harness();
  await h.load('src/lib/auth.ts').login({ email: 'test@example.com', password: 'password' });
  assert.equal(h.events[0]?.url, 'http://localhost:8000/api/login');
});

test('resend helper uses the verification endpoint', async () => {
  const h = harness();
  await h.load('src/lib/auth.ts').resendEmailVerification();
  assert.equal(h.events[0]?.url, 'http://localhost:8000/api/email/verification-notification');
  assert.equal(h.events[0]?.options.method, 'POST');
});

test('Navbar shows verification action and hides business navigation for unverified users', () => {
  const h = harness({ verified: false, states: { 3: true, 4: true } });
  const tree = h.load('components/Navbar.tsx').Navbar();
  assert.equal(nodes(tree, (node) => node.props?.children === 'Kirim ulang verifikasi').length, 1);
  assert.equal(nodes(tree, (node) => node.props?.children === 'Katalog Produk').length, 0);
  assert.equal(nodes(tree, (node) => node.props?.children === 'Histori').length, 0);
});

test('Navbar omits verification action for verified users', () => {
  const h = harness({ verified: true, states: { 3: true, 4: true } });
  const tree = h.load('components/Navbar.tsx').Navbar();
  assert.equal(nodes(tree, (node) => node.props?.children === 'Kirim ulang verifikasi').length, 0);
});

for (const role of ['superadmin', 'admin', 'customer']) {
  test(`Navbar privileged navigation recognizes only superadmin: ${role}`, () => {
    const h = harness({ role, states: { 3: true, 4: true } });
    const tree = h.load('components/Navbar.tsx').Navbar();
    const panels = nodes(tree, (node) => node.props?.children === 'Admin Panel');
    assert.equal(panels.length > 0, role === 'superadmin');
  });
}

for (const changedEmail of [false, true]) {
  test(`profile submits current_password only for email change: ${changedEmail}`, async () => {
    const h = harness({ states: { 0: false, 1: 'Test', 2: changedEmail ? 'new@example.com' : 'test@example.com', 4: true, 8: 'old-password' } });
    const tree = h.load('app/profile/page.tsx').default();
    const [button] = nodes(tree, (node) => node.props?.children === 'Simpan Profil');
    button.props.onClick({ preventDefault() {} });
    await new Promise(setImmediate);
    const request = h.events.find((event) => event.url?.endsWith('/user/profile'));
    assert.ok(request);
    assert.equal(JSON.parse(request.options.body).current_password, changedEmail ? 'old-password' : undefined);
  });
}

test('email change without current password is blocked before request', async () => {
  const h = harness({ states: { 0: false, 2: 'new@example.com', 4: true } });
  const tree = h.load('app/profile/page.tsx').default();
  const [button] = nodes(tree, (node) => node.props?.children === 'Simpan Profil');
  button.props.onClick({ preventDefault() {} });
  await new Promise(setImmediate);
  assert.equal(h.events.some((event) => event.url?.endsWith('/user/profile')), false);
});

for (const failPassword of [false, true]) {
  test(`password update clears authentication only on success: failure=${failPassword}`, async () => {
    const h = harness({ failPassword, states: { 0: false, 8: 'old-password', 9: 'new-password', 10: 'new-password' } });
    const tree = h.load('app/profile/page.tsx').default();
    const [form] = nodes(tree, (node) => node.props?.onSubmit?.name === 'handleUpdatePassword');
    assert.ok(form);
    await form.props.onSubmit({ preventDefault() {} });
    assert.equal(h.events[0]?.url, 'http://localhost:8000/api/user/password');
    assert.equal(h.state.user === null, !failPassword);
    assert.equal(h.window.location.href, failPassword ? '' : '/login');
  });
}
