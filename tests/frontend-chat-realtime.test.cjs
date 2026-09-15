const { test } = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const vm = require('node:vm');
const { createRequire } = require('node:module');
const ts = require('typescript');

function harness(root, handler) {
    const base = path.resolve(__dirname, '../../', root);
    const dependency = createRequire(path.join(base, 'package.json'));
    const { createApi } = dependency('@reduxjs/toolkit/query');
    let clock = Date.now();
    let calls = 0;
    class Clock extends Date { static now() { return clock; } }
    const api = createApi({ reducerPath: 'api', tagTypes: ['Chat'], endpoints: () => ({}),
        baseQuery: async (args, context) => { calls++; return handler(args, context); } });
    const modules = new Map();
    const stubReducer = { __esModule: true, default: (state = {}) => state };
    function load(relative) {
        if (modules.has(relative)) return modules.get(relative);
        const compiled = ts.transpileModule(fs.readFileSync(path.join(base, relative), 'utf8'), {
            compilerOptions: { module: ts.ModuleKind.CommonJS, target: ts.ScriptTarget.ES2022, esModuleInterop: true },
            reportDiagnostics: true,
        });
        assert.equal(compiled.diagnostics.length, 0);
        const exports = {};
        modules.set(relative, exports);
        vm.runInNewContext(compiled.outputText, { exports, module: { exports }, Date: Clock,
            setTimeout, clearTimeout, setInterval, clearInterval, console, process,
            require(name) {
                if (name === '@/lib') return { api };
                if (name === '@/lib/chatRealtimeQuery') return load('lib/chatRealtimeQuery.ts');
                if (name === './chatCredential') return load('store/chatCredential.ts');
                if (name === './slices/authSlice') return load('store/slices/authSlice.ts');
                if (name === './slices/uiSlice' || name === './slices/cartSlice') return stubReducer;
                if (name === '@/lib/balanceRealtime') return { mergeBalanceProfile: (old, next) => ({ ...old, ...next }) };
                return dependency(name);
            },
        }, { filename: path.join(base, relative) });
        return exports;
    }
    const { store, persistor } = load('store/index.ts');
    load('services/chatService.ts');
    const auth = load('store/slices/authSlice.ts');
    const query = (forceRefetch = false) => store.dispatch(api.endpoints.getChatRealtimeChannel.initiate(
        store.getState().chatCredentialVersion, { forceRefetch }));
    return { store, api, auth, query, calls: () => calls, now: () => clock, advance: ms => { clock += ms; },
        login: token => store.dispatch(auth.setAccessToken({ accessToken: token })),
        cleanup: () => { persistor.pause(); store.dispatch(api.util.resetApiState()); },
    };
}

for (const root of ['123nick.com v4', 'shophhp.net v4', 'vanghhp.vn v4']) {
    test(root + ': concurrent consumers and remount reuse a credential-scoped channel', async t => {
        let resolve;
        const h = harness(root, () => new Promise(done => { resolve = done; }));
        t.after(h.cleanup);
        h.login('token-A');
        const first = h.query();
        const concurrent = Array.from({ length: 20 }, () => h.query());
        assert.equal(h.calls(), 1);
        resolve({ data: { data: { channel: 'channel-A' } } });
        await Promise.all([first, ...concurrent]);
        for (const request of [first, ...concurrent]) request.unsubscribe();
        // With keepUnusedDataFor=0 the old implementation loses its cache here.
        await new Promise(done => setTimeout(done, 10));
        const remount = h.query();
        assert.equal((await remount).data.data.channel, 'channel-A');
        assert.equal(h.calls(), 1);
        remount.unsubscribe();
        assert.ok(Object.values(h.store.getState().api.queries).every(query => typeof query.originalArgs === 'number'));
        assert.ok(!Object.keys(h.store.getState().api.queries).some(key => key.includes('token-A')));
    });

    test(root + ': hydration, token rotation and logout cannot reuse an old credential key', async t => {
        const h = harness(root, async (_args, context) => ({
            data: { data: { channel: 'channel-' + context.getState().auth.accessToken } },
        }));
        t.after(h.cleanup);
        h.store.dispatch({ type: 'persist/REHYDRATE', key: 'root',
            payload: { auth: { accessToken: 'A', isAuthenticated: true, user: null } } });
        const a = h.store.getState().chatCredentialVersion;
        assert.equal((await h.query()).data.data.channel, 'channel-A');
        h.login('A');
        assert.equal(h.store.getState().chatCredentialVersion, a);
        h.login('B');
        const b = h.store.getState().chatCredentialVersion;
        assert.ok(b > a);
        assert.equal((await h.query()).data.data.channel, 'channel-B');
        h.store.dispatch(h.auth.logout());
        await h.query();
        assert.equal(h.calls(), 2, 'logged-out attempts must not call the API');
        h.login('A');
        assert.ok(h.store.getState().chatCredentialVersion > b);
        assert.equal((await h.query()).data.data.channel, 'channel-A');
        assert.equal(h.calls(), 3, 'a new login must authenticate again even with the same token value');
    });

    test(root + ': 429 cooldown survives repeated requests and automatic retries stop after three failures', async t => {
        let status = 429;
        const h = harness(root, async () => status === 200 ? { data: { data: { channel: 'recovered' } } } : ({
            error: { status, data: { message: 'Wait' } }, meta: { response: new Response('', { headers: { 'Retry-After': '42' } }) },
        }));
        t.after(h.cleanup);
        h.login('A');
        const first = await h.query();
        assert.equal(first.error.retryAt, h.now() + 42_000);
        assert.equal(first.error.retryAutomatically, true);
        for (let i = 0; i < 50; i++) await h.query(true);
        assert.equal(h.calls(), 1);
        h.advance(42_000);
        assert.equal((await h.query(true)).error.retryAutomatically, true);
        h.advance(42_000);
        assert.equal((await h.query(true)).error.retryAutomatically, false);
        assert.equal(h.calls(), 3);
        status = 200;
        assert.ok((await h.query(true)).error, 'manual retry also respects the remaining wait');
        h.advance(42_000);
        assert.equal((await h.query(true)).data.data.channel, 'recovered');
        assert.equal(h.calls(), 4);
    });

    test(root + ': late responses never populate the next login and auth errors do not auto-retry', async t => {
        const pending = [];
        const h = harness(root, () => new Promise(resolve => pending.push(resolve)));
        t.after(h.cleanup);
        h.login('A');
        const old = h.query();
        h.store.dispatch(h.auth.logout());
        h.login('B');
        const current = h.query();
        pending[0]({ data: { data: { channel: 'old-private-channel' } } });
        assert.equal((await old).error.status, 'CUSTOM_ERROR');
        pending[1]({ error: { status: 403, data: {} } });
        const denied = await current;
        assert.equal(denied.error.retryAutomatically, false);
        for (let i = 0; i < 10; i++) await h.query(true);
        assert.equal(h.calls(), 2);
    });

    test(root + ': Retry-After dates and missing headers have a bounded client retry cadence', async t => {
        let header;
        const h = harness(root, async () => ({ error: { status: 429, data: {} },
            meta: { response: new Response('', { headers: header ? { 'Retry-After': header } : {} }) } }));
        t.after(h.cleanup);
        h.login('A');
        const retryDate = new Date(h.now() + 90_000);
        header = retryDate.toUTCString();
        assert.equal((await h.query()).error.retryAt, Date.parse(header));
        h.advance(91_000);
        header = undefined;
        assert.equal((await h.query(true)).error.retryAt, h.now() + 60_000);
    });
}
