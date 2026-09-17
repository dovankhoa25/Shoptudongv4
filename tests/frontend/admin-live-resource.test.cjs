const { test } = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const Module = require('node:module');
const ts = require('typescript');

function load(file, overrides) {
    const filename = path.resolve(__dirname, '../../resources/js/Realtime', file);
    const instance = new Module(filename, module);
    instance.filename = filename;
    instance.paths = Module._nodeModulePaths(path.dirname(filename));
    const original = instance.require.bind(instance);
    instance.require = name => overrides[name] ?? original(name);
    instance._compile(ts.transpileModule(fs.readFileSync(filename, 'utf8'), {
        compilerOptions: { module: ts.ModuleKind.CommonJS, target: ts.ScriptTarget.ES2022 },
    }).outputText, filename);
    return instance.exports;
}

// Run the actual hooks with deterministic effects and deferred HTTP. Socket callbacks
// are deliberately independent of HTTP completion, reproducing the reported outage.
function harness(t, options = {}) {
    const previous = { window: global.window, document: global.document };
    global.window = new EventTarget(); global.document = new EventTarget();
    document.visibilityState = 'visible';
    const slots = []; let cursor = 0, effects = [], dirty = true, mounted = true;
    const equal = (a, b) => a && b && a.length === b.length && a.every((x, i) => Object.is(x, b[i]));
    const react = {
        useState(initial) {
            const i = cursor++;
            if (!slots[i]) slots[i] = { value: typeof initial === 'function' ? initial() : initial };
            return [slots[i].value, next => {
                if (!mounted) return;
                const value = typeof next === 'function' ? next(slots[i].value) : next;
                if (!Object.is(value, slots[i].value)) { slots[i].value = value; dirty = true; }
            }];
        },
        useRef(value) { const i = cursor++; return (slots[i] ??= { current: value }); },
        useCallback(fn, deps) {
            const i = cursor++;
            if (!equal(slots[i]?.deps, deps)) slots[i] = { value: fn, deps };
            return slots[i].value;
        },
        useEffect(fn, deps) {
            const i = cursor++;
            if (!equal(slots[i]?.deps, deps)) effects.push(() => {
                slots[i]?.cleanup?.(); slots[i] = { deps, cleanup: fn() };
            });
        },
    };
    const h = {
        url: '/admin/nro-shop/orders?page=1', auth: { user: { id: 1 }, realtime_channel: 'credential-a' },
        calls: [], gets: [], channels: [], snapshots: { data: [{ id: 2 }], total: 1 }, ...options,
    };
    const http = {
        get(url, config) {
            h.calls.push(['get', url]);
            return new Promise((resolve, reject) => h.gets.push({ url, config, resolve: data => resolve({ data }), reject }));
        },
        async post(url) {
            h.calls.push(['post', url]);
            if (url === '/admin/live-views') {
                if (h.registrationError) throw h.registrationError;
                const id = String(h.calls.filter(c => c[1] === url).length);
                return { data: { id, channel: `Admin.View.${id}` } };
            }
            if (h.syncError) throw h.syncError;
            return { data: { revision: 1, data: h.snapshots } };
        },
        async patch(url) { h.calls.push(['patch', url]); },
        async delete(url) { h.calls.push(['delete', url]); },
    };
    const socket = {
        private(name) {
            if (h.socketError) throw h.socketError;
            const channel = { name, listen(event, fn) { this.receive = fn; }, on(event, fn) { this.subscribed = fn; },
                error(fn) { this.fail = fn; }, stopListening() {} };
            h.channels.push(channel); return channel;
        },
        leave() {}, connector: { pusher: { connection: { bind() {}, unbind() {} } } },
    };
    const dependencies = { react, '@inertiajs/react': { usePage: () => ({ props: { auth: h.auth } }) },
        axios: { default: http }, '@laravel/echo-react': { echo: () => socket }, './liveDelta': load('liveDelta.ts', {}) };
    const live = load('useLiveView.ts', dependencies);
    const { useLiveResource } = load('useLiveResource.ts', { ...dependencies, './useLiveView': live });
    h.render = () => {
        dirty = false; cursor = 0; effects = [];
        h.value = h.raw ? live.useLiveView(h.url, data => { h.data = data; }) : useLiveResource(h.url, 'Không tải được dữ liệu');
        effects.forEach(fn => fn()); return h.value;
    };
    h.flush = async () => { for (let i = 0; i < 30; i++) { await Promise.resolve(); if (dirty) h.render(); } };
    h.hide = () => { document.visibilityState = 'hidden'; document.dispatchEvent(new Event('visibilitychange')); };
    h.show = () => { document.visibilityState = 'visible'; document.dispatchEvent(new Event('visibilitychange')); };
    h.count = (method, suffix) => h.calls.filter(c => c[0] === method && c[1].endsWith(suffix)).length;
    h.unmount = () => { if (!mounted) return; mounted = false; slots.forEach(slot => slot?.cleanup?.()); };
    t.after(() => { h.unmount(); global.window = previous.window; global.document = previous.document; });
    h.render(); return h;
}
const forbidden = { response: { status: 403 } };

test('ordinary GET renders rows even when live registration returns 403 HTML', async t => {
    const h = harness(t, { registrationError: forbidden });
    assert.equal(h.count('post', '/live-views'), 0);
    h.gets[0].resolve({ data: [{ id: 176 }], total: 176 }); await h.flush();
    assert.equal(h.value.data.data[0].id, 176);
    assert.equal(h.value.error, null); assert.match(h.value.warning, /gián đoạn/);
    for (let i = 0; i < 10; i++) { h.hide(); h.show(); window.dispatchEvent(new Event('online')); }
    await h.flush(); assert.equal(h.count('post', '/live-views'), 1);
    h.value.reload(); h.gets[1].resolve({ data: [{ id: 177 }] }); await h.flush();
    assert.equal(h.value.data.data[0].id, 177);
});

test('socket authorization failure preserves the successful ordinary read', async t => {
    const h = harness(t); h.gets[0].resolve({ data: [{ id: 5 }] }); await h.flush();
    h.channels[0].fail({ status: 403 }); await h.flush();
    assert.equal(h.value.data.data[0].id, 5); assert.match(h.value.warning, /gián đoạn/);
    assert.equal(h.count('delete', '/1'), 1);
});

test('unauthorized ordinary read reports an error without starting realtime', async t => {
    const h = harness(t); h.gets[0].reject(forbidden); await h.flush();
    assert.equal(h.value.data, null); assert.match(h.value.error, /403/);
    assert.equal(h.count('post', '/live-views'), 0); assert.equal(h.value.loading, false);
});

test('HTTP 200 containing HTML is not displayed as an empty successful table', async t => {
    const h = harness(t); h.gets[0].resolve('<html>Blocked</html>'); await h.flush();
    assert.equal(h.value.data, null); assert.match(h.value.error, /Không tải/);
    assert.equal(h.count('post', '/live-views'), 0);
});

test('rapid filter changes abort old reads and ignore out-of-order responses', async t => {
    const h = harness(t); const old = h.gets[0];
    h.url = '/admin/nro-shop/orders?page=2'; h.render(); await h.flush();
    assert.equal(old.config.signal.aborted, true);
    h.gets[1].resolve({ data: [{ id: 20 }] }); old.resolve({ data: [{ id: 10 }] }); await h.flush();
    assert.equal(h.value.data.data[0].id, 20); assert.equal(h.gets.length, 2);
});

test('credential changes clear old account data before starting the next read', async t => {
    const h = harness(t); h.gets[0].resolve({ data: [{ id: 10 }] }); await h.flush();
    h.auth = { user: { id: 2 }, realtime_channel: 'credential-b' }; h.render();
    assert.equal(h.value.data, null); await h.flush();
    assert.equal(h.gets.length, 2); assert.equal(h.count('delete', '/1'), 1);
    h.channels[0].subscribed(); await h.flush(); assert.equal(h.count('post', '/1/sync'), 0);
});

test('a live snapshot wins over a slower manual read and later refresh uses sync', async t => {
    const h = harness(t); h.gets[0].resolve({ data: [{ id: 1 }] }); await h.flush();
    h.value.reload(); const manual = h.gets[1];
    h.channels[0].subscribed(); await h.flush(); manual.resolve({ data: [{ id: 0 }] }); await h.flush();
    assert.equal(manual.config.signal.aborted, true); assert.equal(h.value.data.data[0].id, 2);
    h.value.reload(); await h.flush(); assert.equal(h.gets.length, 2); assert.equal(h.count('post', '/1/sync'), 2);
});

test('a forbidden manual read discards previously visible data', async t => {
    const h = harness(t, { registrationError: forbidden });
    h.gets[0].resolve({ data: [{ id: 1 }] }); await h.flush();
    h.value.reload(); h.gets[1].reject(forbidden); await h.flush();
    assert.equal(h.value.data, null); assert.match(h.value.error, /403/);
});

test('closing a modal aborts its read and a delayed response cannot restart it', async t => {
    const h = harness(t); const read = h.gets[0]; h.url = null; h.render(); await h.flush();
    assert.equal(read.config.signal.aborted, true); read.resolve({ data: [{ id: 1 }] }); await h.flush();
    assert.equal(h.value.data, null); assert.equal(h.value.loading, false); assert.equal(h.count('post', '/live-views'), 0);
});

test('brief tab switches keep a live subscription; long hidden tabs release it', async t => {
    t.mock.timers.enable({ apis: ['setTimeout', 'Date'], now: 100000 });
    const h = harness(t, { raw: true }); await h.flush(); h.channels[0].subscribed(); await h.flush();
    h.hide(); t.mock.timers.tick(10000); h.show(); await h.flush();
    assert.equal(h.count('delete', '/1'), 0); assert.equal(h.count('post', '/live-views'), 1);
    assert.equal(h.count('post', '/1/sync'), 1);
    h.hide(); t.mock.timers.tick(30000); await h.flush();
    assert.equal(h.count('delete', '/1'), 1); h.show(); await h.flush();
    assert.equal(h.count('post', '/live-views'), 2);
});

test('429 obeys Retry-After even when visibility, network and manual retry fire', async t => {
    t.mock.timers.enable({ apis: ['setTimeout', 'Date'], now: 100000 });
    const h = harness(t, { raw: true, registrationError: { response: { status: 429, headers: { 'retry-after': '120' } } } });
    await h.flush(); h.value.sync(); h.hide(); h.show(); window.dispatchEvent(new Event('online')); await h.flush();
    assert.equal(h.count('post', '/live-views'), 1);
    t.mock.timers.tick(119000); h.value.sync(); await h.flush(); assert.equal(h.count('post', '/live-views'), 1);
    t.mock.timers.tick(1000); h.registrationError = null; h.value.sync(); await h.flush();
    assert.equal(h.count('post', '/live-views'), 2);
});

test('socket setup failure releases its lease instead of leaking registered views', async t => {
    const h = harness(t, { raw: true, socketError: new Error('missing configuration') }); await h.flush();
    assert.equal(h.value.status, 'offline'); assert.equal(h.count('delete', '/1'), 1);
});
