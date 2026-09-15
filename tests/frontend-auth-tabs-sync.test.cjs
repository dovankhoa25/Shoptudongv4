const { test } = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const vm = require('node:vm');
const { createRequire } = require('node:module');
const ts = require('typescript');

for (const root of ['123nick.com v4', 'shophhp.net v4', 'vanghhp.vn v4']) {
    test(root + ': simultaneous tab token changes settle without rebroadcast loops', () => {
        const base = path.resolve(__dirname, '../../', root);
        const dependency = createRequire(path.join(base, 'package.json'));
        const { configureStore } = dependency('@reduxjs/toolkit');
        const ports = [];
        const messages = [];
        let sent = 0;
        class BroadcastChannel {
            constructor() { ports.push(this); }
            postMessage(data) {
                sent++;
                for (const target of ports) if (target !== this) messages.push({ target, data });
            }
        }
        function createTab(browser = true) {
            const cleanups = [];
            const modules = new Map();
            let store;
            const load = relative => {
                if (modules.has(relative)) return modules.get(relative);
                const compiled = ts.transpileModule(fs.readFileSync(path.join(base, relative), 'utf8'), {
                    compilerOptions: { module: ts.ModuleKind.CommonJS, target: ts.ScriptTarget.ES2022, esModuleInterop: true },
                });
                const exports = {};
                modules.set(relative, exports);
                vm.runInNewContext(compiled.outputText, { exports, module: { exports }, console, BroadcastChannel,
                    ...(browser ? { window: { BroadcastChannel } } : {}),
                    require(name) {
                        if (name === '@/store') return { store };
                        if (name === '@/store/slices/authSlice') return load('store/slices/authSlice.ts');
                        if (name === './tabs-sync') return load('services/tabs-sync.ts');
                        if (name === '@/lib/balanceRealtime') return { mergeBalanceProfile: (old, next) => ({ ...old, ...next }) };
                        // Run the actual effect setup/cleanup with Redux and an asynchronous channel queue.
                        if (name === 'react') return { useRef: current => ({ current }), useEffect: setup => cleanups.push(setup()) };
                        return dependency(name);
                    },
                }, { filename: path.join(base, relative) });
                return exports;
            };
            const auth = load('store/slices/authSlice.ts');
            store = configureStore({ reducer: { auth: auth.default } });
            const sync = load('services/tabs-sync.ts');
            if (browser) {
                sync.useAuthTabsSync();
                load('services/token-watcher.tsx').default();
                store.dispatch({ type: 'boot-complete' });
            }
            return { store, auth, cleanup: () => cleanups.forEach(fn => fn?.()) };
        }
        const first = createTab();
        const second = createTab();
        const drain = () => {
            for (let i = 0; i < 30 && messages.length; i++) {
                const { target, data } = messages.shift();
                target.onmessage?.({ data });
            }
            assert.equal(messages.length, 0, 'remote token changes must not echo indefinitely');
        };
        try {
            first.store.dispatch(first.auth.setAccessToken({ accessToken: 'A' }));
            second.store.dispatch(second.auth.setAccessToken({ accessToken: 'B' }));
            drain();
            assert.equal(sent, 2, 'only the two local changes should be broadcast');
            first.store.dispatch(first.auth.setAccessToken({ accessToken: 'C' }));
            drain();
            assert.equal(second.store.getState().auth.accessToken, 'C');
            first.store.dispatch(first.auth.logout());
            drain();
            assert.equal(second.store.getState().auth.accessToken, null);
            assert.equal(sent, 4);
            const before = ports.length;
            createTab(false);
            assert.equal(ports.length, before, 'SSR must not open a browser channel');
        } finally {
            first.cleanup();
            second.cleanup();
        }
        assert.ok(ports.every(port => port.onmessage === null), 'unmount must detach channel listeners');
    });
}
