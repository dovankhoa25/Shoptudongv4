const { test } = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const Module = require('node:module');
const ts = require('typescript');
const React = require('react');

function harness(t) {
    const previous = global.window;
    global.window = new EventTarget();
    const h = {
        page: { url: '/admin/gem-orders?status=pending', props: { auth: { user: { id: 7 } }, orders: ['initial'] } },
        live: { status: 'live', sync: () => { h.syncs++; } },
        syncs: 0, reloads: 0, replacements: [], calls: [], child: { marker: 'page-content' },
    };
    const slots = []; let cursor = 0, effects = [], dirty = false;
    const equal = (a, b) => a && b && a.length === b.length && a.every((value, i) => Object.is(value, b[i]));
    const react = {
        ...React,
        useState(initial) {
            const i = cursor++;
            slots[i] ??= { value: typeof initial === 'function' ? initial() : initial };
            return [slots[i].value, value => { slots[i].value = typeof value === 'function' ? value(slots[i].value) : value; dirty = true; }];
        },
        useEffect(fn, deps) {
            const i = cursor++;
            if (!equal(slots[i]?.deps, deps)) effects.push(() => {
                slots[i]?.cleanup?.(); slots[i] = { deps, cleanup: fn() };
            });
        },
    };
    const router = {
        replace(options) { h.replacements.push(options); h.page.props = options.props(h.page.props); },
        reload() { h.reloads++; },
    };
    const filename = path.resolve(__dirname, '../../resources/js/Layouts/Admin/AdminRealtimeProvider.tsx');
    const instance = new Module(filename, module);
    instance.filename = filename; instance.paths = Module._nodeModulePaths(path.dirname(filename));
    const dependencies = {
        react,
        '@inertiajs/react': { router, usePage: () => h.page },
        '@/Realtime/useLiveView': { useLiveView(url, receive, mode) {
            h.calls.push({ url, receive, mode }); h.receive = receive; return h.live;
        } },
    };
    instance.require = name => {
        assert.ok(name in dependencies, `Unexpected provider dependency: ${name}`);
        return dependencies[name];
    };
    instance._compile(ts.transpileModule(fs.readFileSync(filename, 'utf8'), {
        compilerOptions: { module: ts.ModuleKind.CommonJS, target: ts.ScriptTarget.ES2022, jsx: ts.JsxEmit.React, esModuleInterop: true },
    }).outputText, filename);
    const Provider = instance.exports.default;
    h.render = () => {
        for (let pass = 0; pass < 3; pass++) {
            cursor = 0; effects = []; dirty = false;
            h.output = Provider({ children: h.child });
            if (dirty) continue;
            effects.forEach(fn => fn()); break;
        }
        return h.output;
    };
    h.dispatch = (name, detail) => {
        const event = new Event(name); Object.defineProperty(event, 'detail', { value: detail });
        window.dispatchEvent(event);
    };
    h.unmount = () => slots.forEach(slot => slot?.cleanup?.());
    t.after(() => { h.unmount(); global.window = previous; });
    h.render(); return h;
}

test('admin pages keep their content without a global realtime warning in every connection state', t => {
    const h = harness(t);
    for (h.live.status of ['connecting', 'live', 'offline', 'denied']) {
        const output = h.render();
        assert.equal(output.type, React.Fragment);
        assert.equal(output.props.children, h.child);
    }
});

test('removing the banner retains filtered subscriptions and state-preserving page patches', t => {
    const h = harness(t);
    assert.equal(h.calls.at(-1).url, '/admin/gem-orders?status=pending');
    assert.equal(h.calls.at(-1).mode, 'page');
    const auth = h.page.props.auth;
    h.receive({ orders: ['updated'] });
    assert.deepEqual(h.page.props.orders, ['updated']);
    assert.equal(h.page.props.auth, auth);
    assert.equal(h.replacements[0].preserveState, true);
    assert.equal(h.replacements[0].preserveScroll, true);
    assert.equal(h.reloads, 0);
});

test('manual sync and authorized HTTP recovery still work without duplicate event handlers', t => {
    const h = harness(t);
    h.dispatch('admin:live-sync'); assert.equal(h.syncs, 1);
    h.dispatch('admin:refresh-if-offline'); assert.equal(h.reloads, 0);
    h.live.status = 'offline'; h.render(); h.render();
    h.dispatch('admin:live-sync'); assert.equal(h.reloads, 1);
    h.dispatch('admin:refresh-if-offline'); assert.equal(h.reloads, 2);
    h.live.status = 'live'; h.render();
    h.dispatch('admin:live-sync'); assert.equal(h.syncs, 2);
    h.unmount(); h.dispatch('admin:live-sync');
    assert.equal(h.syncs, 2); assert.equal(h.reloads, 2);
});

test('NRO tab modes and page eligibility remain scoped to the current admin page', t => {
    const h = harness(t);
    h.page.url = '/admin/nro-shop'; h.page.props.capabilities = { accounts: false }; h.render();
    assert.equal(h.calls.at(-1).mode, 'summary');
    h.dispatch('admin:nro-tab', 'accounts'); h.render();
    assert.equal(h.calls.at(-1).mode, 'page');
    h.dispatch('admin:nro-tab', 'orders'); h.render();
    assert.equal(h.calls.at(-1).mode, 'summary');
    h.page.url = '/admin/gem-orders/23'; h.render();
    assert.equal(h.calls.at(-1).url, '/admin/gem-orders/23');
    assert.equal(h.calls.at(-1).mode, 'page');
    h.page.url = '/admin/chat'; h.render();
    assert.equal(h.calls.at(-1).url, null);
    h.page.url = '/admin/gem-orders'; h.page.props.auth.user = null; h.render();
    assert.equal(h.calls.at(-1).url, null);
});
