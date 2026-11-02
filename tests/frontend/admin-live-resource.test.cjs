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
        calls: [], gets: [], syncs: [], channels: [], snapshots: { data: [{ id: 2 }], total: 1 }, ...options,
    };
    const connectionListeners = new Map();
    h.connection = { state: 'connected', bind: (event, fn) => connectionListeners.set(event, fn),
        unbind: (event, fn) => { if (connectionListeners.get(event) === fn) connectionListeners.delete(event); } };
    h.connect = state => {
        h.connection.state = state;
        if (state !== 'connected') h.channels.forEach(channel => { channel.subscription.subscribed = false; });
        connectionListeners.get('state_change')?.({ current: state });
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
            if (h.deferSync) return new Promise((resolve, reject) => h.syncs.push({ url, resolve: data => resolve({ data }), reject }));
            return { data: { revision: h.snapshotRevision ?? 1, data: h.snapshots } };
        },
        async patch(url) { h.calls.push(['patch', url]); if (h.renewError) throw h.renewError; },
        async delete(url) { h.calls.push(['delete', url]); },
    };
    const socket = {
        private(name) {
            if (h.socketError) throw h.socketError;
            const channel = { name, subscription: { subscribed: false }, listen(event, fn) { this.receive = fn; },
                on(event, fn) { this.subscribed = () => { this.subscription.subscribed = true; fn(); }; },
                error(fn) { this.fail = fn; }, stopListening() {} };
            h.channels.push(channel); return channel;
        },
        leave() {}, connector: { pusher: { connection: h.connection } },
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

test('transient subscription failure retries within a bounded recovery incident, never during healthy idle', async t => {
    t.mock.timers.enable({ apis: ['setTimeout', 'Date'], now: 100000 });
    const h = harness(t, { raw: true }); await h.flush();
    h.channels[0].fail({ status: 503 }); await h.flush();
    assert.equal(h.value.status, 'offline'); assert.equal(h.count('delete', '/1'), 1);
    t.mock.timers.tick(29999); await h.flush(); assert.equal(h.channels.length, 1);
    t.mock.timers.tick(1); await h.flush(); assert.equal(h.channels.length, 2);
    h.channels[1].subscribed(); await h.flush(); assert.equal(h.value.status, 'live');
    t.mock.timers.tick(60000); await h.flush();
    assert.equal(h.channels.length, 2); assert.equal(h.count('post', '/2/sync'), 1);
});

test('expired lease re-registers after a 404 sync without reloading page data', async t => {
    t.mock.timers.enable({ apis: ['setTimeout', 'Date'], now: 100000 });
    const h = harness(t, { raw: true, syncError: { response: { status: 404 } } }); await h.flush();
    h.channels[0].subscribed(); await h.flush(); assert.equal(h.value.status, 'offline');
    h.syncError = null; t.mock.timers.tick(1000); await h.flush();
    assert.equal(h.channels.length, 2);
    h.channels[1].subscribed(); await h.flush(); assert.equal(h.value.status, 'live');
    assert.equal(h.gets.length, 0); assert.equal(h.count('post', '/2/sync'), 1);
});

test('reconnect during cooldown recovers once after the deadline without bypassing Retry-After', async t => {
    t.mock.timers.enable({ apis: ['setTimeout', 'Date'], now: 100000 });
    const h = harness(t, { raw: true }); await h.flush(); h.channels[0].subscribed(); await h.flush();
    h.syncError = { response: { status: 429, headers: { 'retry-after': '42' } } };
    h.value.sync(); await h.flush(); assert.equal(h.value.status, 'offline');
    h.syncError = null; h.connect('unavailable'); h.connect('connected'); h.channels[0].subscribed(); await h.flush();
    assert.equal(h.count('post', '/1/sync'), 2);
    t.mock.timers.tick(41999); await h.flush(); assert.equal(h.count('post', '/1/sync'), 2);
    t.mock.timers.tick(1); await h.flush();
    assert.equal(h.value.status, 'live'); assert.equal(h.count('post', '/1/sync'), 3);
});

test('recovery attempts stop after three failures and security denials never retry automatically', async t => {
    t.mock.timers.enable({ apis: ['setTimeout', 'Date'], now: 100000 });
    const h = harness(t, { raw: true, registrationError: { response: { status: 503 } } }); await h.flush();
    for (let i = 0; i < 5; i++) { t.mock.timers.tick(30000); await h.flush(); }
    assert.equal(h.count('post', '/live-views'), 3);
    h.registrationError = forbidden; h.value.sync(); await h.flush();
    assert.equal(h.value.status, 'denied'); const deniedCalls = h.calls.length;
    for (let i = 0; i < 5; i++) {
        t.mock.timers.tick(60000); h.connect('unavailable'); h.connect('connected');
        h.hide(); h.show(); window.dispatchEvent(new Event('online')); await h.flush();
    }
    assert.equal(h.calls.length, deniedCalls);
});

test('hidden or unmounted views do not issue scheduled recovery requests', async t => {
    t.mock.timers.enable({ apis: ['setTimeout', 'Date'], now: 100000 });
    const h = harness(t, { raw: true, registrationError: { response: { status: 503 } } }); await h.flush();
    h.hide(); t.mock.timers.tick(30000); await h.flush();
    assert.equal(h.count('post', '/live-views'), 1);
    h.registrationError = null; h.show(); await h.flush(); assert.equal(h.channels.length, 1);
    h.channels[0].fail({ status: 503 }); await h.flush(); h.unmount();
    const requests = h.calls.length; t.mock.timers.tick(120000); await h.flush(); assert.equal(h.calls.length, requests);
});

test('socket reconnect reconciles an existing lease without waiting for another page or focus event', async t => {
    const h = harness(t, { raw: true }); await h.flush(); h.channels[0].subscribed(); await h.flush();
    h.connect('unavailable'); await h.flush(); assert.equal(h.value.status, 'offline');
    h.connect('connected'); await h.flush();
    assert.equal(h.value.status, 'live'); assert.equal(h.count('post', '/1/sync'), 2);
    assert.equal(h.count('post', '/live-views'), 1);
});

test('message deltas stay live; a revision gap followed by temporary sync503 recovers once', async t => {
    t.mock.timers.enable({ apis: ['setTimeout', 'Date'], now: 100000 });
    const h = harness(t, { raw: true }); await h.flush(); h.channels[0].subscribed(); await h.flush();
    const message = (base, revision, body) => h.channels[0].receive({ viewId: '1', batch: `message-${revision}`, base, revision,
        part: 0, total: 1, chunk: Buffer.from(JSON.stringify([{ op: 'set', path: ['data', 0, 'body'], value: body }])).toString('base64') });
    message(1, 2, 'Tin mới từ khách 😃'); await h.flush();
    message(1, 2, 'Replay cũ'); await h.flush();
    assert.equal(h.value.status, 'live'); assert.equal(h.data.data[0].body, 'Tin mới từ khách 😃');
    assert.equal(h.count('post', '/1/sync'), 1);
    h.syncError = { response: { status: 503 } };
    message(4, 5, 'Missed revisions'); await h.flush(); assert.equal(h.value.status, 'offline');
    h.syncError = null; h.snapshotRevision = 5; h.snapshots = { data: [{ id: 2, body: 'Đã đồng bộ bù' }] };
    t.mock.timers.tick(30000); await h.flush();
    assert.equal(h.value.status, 'live'); assert.equal(h.data.data[0].body, 'Đã đồng bộ bù');
    assert.equal(h.count('post', '/1/sync'), 3);
    message(5, 6, 'Tin kế tiếp'); await h.flush();
    assert.equal(h.data.data[0].body, 'Tin kế tiếp'); assert.equal(h.value.status, 'live');
    t.mock.timers.tick(60000); await h.flush(); assert.equal(h.count('post', '/1/sync'), 3);
});

test('expired renewal replaces its lease and revoked frames stop all automatic recovery', async t => {
    t.mock.timers.enable({ apis: ['setTimeout', 'Date'], now: 100000 });
    const h = harness(t, { raw: true }); await h.flush(); h.channels[0].subscribed(); await h.flush();
    h.renewError = { response: { status: 404 } };
    t.mock.timers.tick(15 * 60000); await h.flush(); assert.equal(h.value.status, 'offline');
    t.mock.timers.tick(1000); await h.flush(); assert.equal(h.channels.length, 2);
    h.channels[1].subscribed(); await h.flush(); assert.equal(h.value.status, 'live');
    h.channels[1].receive({ viewId: '2', revoked: true }); await h.flush(); assert.equal(h.value.status, 'denied');
    const requests = h.calls.length;
    t.mock.timers.tick(120000); h.connect('unavailable'); h.connect('connected'); await h.flush();
    assert.equal(h.calls.length, requests);
});

test('successful recovery sync re-arms lease renewal after a failed PATCH', async t => {
    t.mock.timers.enable({ apis: ['setTimeout', 'Date'], now: 100000 });
    const h = harness(t, { raw: true }); await h.flush(); h.channels[0].subscribed(); await h.flush();
    h.renewError = { response: { status: 503 } };
    t.mock.timers.tick(15 * 60000); await h.flush(); assert.equal(h.value.status, 'offline');
    assert.equal(h.count('patch', '/1'), 1);
    h.renewError = null; t.mock.timers.tick(30000); await h.flush(); assert.equal(h.value.status, 'live');
    assert.equal(h.count('post', '/1/sync'), 2);
    t.mock.timers.tick(15 * 60000); await h.flush(); assert.equal(h.count('patch', '/1'), 2);
    assert.equal(h.count('post', '/live-views'), 1);
});

test('subscription success during reconnect snapshot queues one catch-up after authorization', async t => {
    const h = harness(t, { raw: true }); await h.flush(); h.channels[0].subscribed(); await h.flush();
    h.connect('unavailable'); await h.flush(); h.deferSync = true; h.connect('connected'); await h.flush();
    assert.equal(h.syncs.length, 1);
    // The first HTTP snapshot may precede authorization: changes in this gap need another read.
    h.channels[0].subscribed(); await h.flush(); assert.equal(h.syncs.length, 1);
    h.syncs[0].resolve({ revision: 2, data: { data: [{ id: 2, body: 'Before private subscription' }] } }); await h.flush();
    assert.equal(h.syncs.length, 2);
    h.syncs[1].resolve({ revision: 3, data: { data: [{ id: 2, body: 'Message sent in authorization gap' }] } }); await h.flush();
    assert.equal(h.value.status, 'live'); assert.equal(h.data.data[0].body, 'Message sent in authorization gap');
    assert.equal(h.count('post', '/live-views'), 1); assert.equal(h.count('post', '/1/sync'), 3);
});

test('a genuine network recovery starts a new bounded incident after exhausted retries', async t => {
    t.mock.timers.enable({ apis: ['setTimeout', 'Date'], now: 100000 });
    const h = harness(t, { raw: true, registrationError: { response: { status: 503 } } }); await h.flush();
    for (let i = 0; i < 5; i++) { t.mock.timers.tick(30000); await h.flush(); }
    assert.equal(h.count('post', '/live-views'), 3);
    h.registrationError = null; h.connect('unavailable'); h.connect('connected'); await h.flush();
    assert.equal(h.channels.length, 1);
    h.channels[0].subscribed(); await h.flush(); assert.equal(h.value.status, 'live');
    t.mock.timers.tick(60000); await h.flush(); assert.equal(h.count('post', '/live-views'), 4);
});

test('projection retry controls recover the last missed message after five seconds and coalesce duplicates', async t => {
    t.mock.timers.enable({ apis: ['setTimeout', 'Date'], now: 100000 });
    const h = harness(t, { raw: true }); await h.flush(); h.channels[0].subscribed(); await h.flush();
    for (let i = 0; i < 20; i++) h.channels[0].receive({ viewId: '1', retry: true });
    await h.flush(); assert.equal(h.value.status, 'offline'); assert.equal(h.count('post', '/1/sync'), 1);
    h.snapshotRevision = 2; h.snapshots = { data: [{ id: 2, body: 'Last customer message after storage recovery' }] };
    t.mock.timers.tick(4999); await h.flush(); assert.equal(h.count('post', '/1/sync'), 1);
    t.mock.timers.tick(1); await h.flush();
    assert.equal(h.value.status, 'live'); assert.equal(h.count('post', '/1/sync'), 2);
    assert.equal(h.data.data[0].body, 'Last customer message after storage recovery');
    t.mock.timers.tick(60000); await h.flush(); assert.equal(h.count('post', '/1/sync'), 2);
    h.channels[0].receive({ viewId: '1', revoked: true }); await h.flush();
    const requests = h.calls.length; h.channels[0].receive({ viewId: '1', retry: true });
    t.mock.timers.tick(60000); await h.flush(); assert.equal(h.value.status, 'denied'); assert.equal(h.calls.length, requests);
});

test('retry control during an in-flight snapshot queues the final missed-message reconciliation', async t => {
    const h = harness(t, { raw: true, deferSync: true }); await h.flush(); h.channels[0].subscribed(); await h.flush();
    assert.equal(h.syncs.length, 1);
    for (let i = 0; i < 20; i++) h.channels[0].receive({ viewId: '1', retry: true });
    await h.flush(); assert.equal(h.syncs.length, 1);
    h.syncs[0].resolve({ revision: 1, data: { data: [{ id: 2, body: 'Snapshot before message' }] } }); await h.flush();
    assert.equal(h.syncs.length, 2);
    h.syncs[1].resolve({ revision: 2, data: { data: [{ id: 2, body: 'Last customer message' }] } }); await h.flush();
    assert.equal(h.data.data[0].body, 'Last customer message'); assert.equal(h.value.status, 'live');
    assert.equal(h.count('post', '/1/sync'), 2);
});

test('hidden duplicate retry controls do not exhaust recovery without making any requests', async t => {
    t.mock.timers.enable({ apis: ['setTimeout', 'Date'], now: 100000 });
    const h = harness(t, { raw: true }); await h.flush(); h.channels[0].subscribed(); await h.flush(); h.hide();
    for (let i = 0; i < 10; i++) h.channels[0].receive({ viewId: '1', retry: true });
    t.mock.timers.tick(6000); await h.flush();
    for (let i = 0; i < 10; i++) h.channels[0].receive({ viewId: '1', retry: true });
    assert.equal(h.count('post', '/1/sync'), 1);
    h.snapshots = { data: [{ id: 2, body: 'Hidden update recovered' }] }; h.show(); await h.flush();
    assert.equal(h.value.status, 'live'); assert.equal(h.count('post', '/1/sync'), 2);
    assert.equal(h.data.data[0].body, 'Hidden update recovered');
});

test('a fresh protected revision gap restarts capped recovery once but replayed frames do not', async t => {
    t.mock.timers.enable({ apis: ['setTimeout', 'Date'], now: 100000 });
    const h = harness(t, { raw: true }); await h.flush(); h.channels[0].subscribed(); await h.flush();
    h.syncError = { response: { status: 503 } }; h.value.sync(); await h.flush();
    for (let i = 0; i < 2; i++) { t.mock.timers.tick(30000); await h.flush(); }
    const frame = (base, revision) => ({ viewId: '1', batch: `revision-${revision}`, base, revision, part: 0, total: 1,
        chunk: Buffer.from(JSON.stringify([{ op: 'set', path: ['data', 0, 'body'], value: 'New server state' }])).toString('base64') });
    assert.equal(h.count('post', '/1/sync'), 4); assert.equal(h.value.status, 'offline');
    t.mock.timers.tick(30000); h.channels[0].receive(frame(4, 5)); await h.flush();
    assert.equal(h.count('post', '/1/sync'), 5);
    for (let i = 0; i < 2; i++) { t.mock.timers.tick(30000); await h.flush(); }
    assert.equal(h.count('post', '/1/sync'), 7);
    t.mock.timers.tick(30000);
    for (let i = 0; i < 20; i++) h.channels[0].receive(frame(4, 5));
    h.channels[0].receive({ ...frame(5, 6), base: 6 });
    h.channels[0].receive({ ...frame(5, 6), chunk: Buffer.from('{}').toString('base64') });
    await h.flush(); assert.equal(h.count('post', '/1/sync'), 7);
    h.syncError = null; h.snapshotRevision = 6; h.snapshots = { data: [{ id: 2, body: 'Gap recovered' }] };
    h.channels[0].receive(frame(5, 6)); await h.flush();
    assert.equal(h.value.status, 'live'); assert.equal(h.count('post', '/1/sync'), 8);
    assert.equal(h.data.data[0].body, 'Gap recovered');
});

test('an applied fresh frame restores capped status and promptly repairs a lost renewal timer', async t => {
    t.mock.timers.enable({ apis: ['setTimeout', 'Date'], now: 100000 });
    const h = harness(t, { raw: true }); await h.flush(); h.channels[0].subscribed(); await h.flush();
    h.renewError = { response: { status: 503 } }; h.syncError = { response: { status: 503 } };
    t.mock.timers.tick(15 * 60000); await h.flush();
    for (let i = 0; i < 2; i++) { t.mock.timers.tick(30000); await h.flush(); }
    assert.equal(h.value.status, 'offline'); assert.equal(h.count('patch', '/1'), 1);
    t.mock.timers.tick(30000); h.renewError = null; h.syncError = null;
    h.snapshotRevision = 2; h.snapshots = { data: [{ id: 2, body: 'Protected frame recovered' }] };
    const frame = revision => ({ viewId: '1', batch: `matching-${revision}`, base: revision - 1, revision, part: 0, total: 1,
        chunk: Buffer.from(JSON.stringify([{ op: 'set', path: ['data', 0, 'body'], value: 'Protected frame recovered' }])).toString('base64') });
    h.channels[0].receive(frame(2)); await h.flush();
    assert.equal(h.value.status, 'live'); assert.equal(h.data.data[0].body, 'Protected frame recovered');
    assert.equal(h.count('post', '/1/sync'), 4);
    h.channels[0].receive(frame(3)); await h.flush(); assert.equal(h.count('post', '/1/sync'), 4);
    t.mock.timers.tick(15 * 60000); await h.flush(); assert.equal(h.count('patch', '/1'), 2);
});

test('fresh recovery evidence preserves the remaining Retry-After deadline', async t => {
    t.mock.timers.enable({ apis: ['setTimeout', 'Date'], now: 100000 });
    const h = harness(t, { raw: true }); await h.flush(); h.channels[0].subscribed(); await h.flush();
    h.syncError = { response: { status: 503, headers: { 'retry-after': '42' } } }; h.value.sync(); await h.flush();
    for (let i = 0; i < 2; i++) { t.mock.timers.tick(42000); await h.flush(); }
    assert.equal(h.count('post', '/1/sync'), 4);
    h.syncError = null; h.snapshotRevision = 5; h.snapshots = { data: [{ id: 2, body: 'After throttle deadline' }] };
    const chunk = Buffer.from(JSON.stringify([{ op: 'set', path: ['data', 0, 'body'], value: 'Fresh gap' }])).toString('base64');
    for (let i = 0; i < 20; i++) h.channels[0].receive({ viewId: '1', batch: 'throttled-gap', base: 4, revision: 5, part: 0, total: 1, chunk });
    await h.flush(); assert.equal(h.count('post', '/1/sync'), 4);
    t.mock.timers.tick(41999); await h.flush(); assert.equal(h.count('post', '/1/sync'), 4);
    t.mock.timers.tick(1); await h.flush();
    assert.equal(h.count('post', '/1/sync'), 5); assert.equal(h.value.status, 'live');
});

test('fresh private frames recover a hidden view once it becomes visible without hidden HTTP', async t => {
    t.mock.timers.enable({ apis: ['setTimeout', 'Date'], now: 100000 });
    const h = harness(t, { raw: true }); await h.flush(); h.channels[0].subscribed(); await h.flush();
    h.renewError = { response: { status: 503 } }; h.syncError = { response: { status: 503 } };
    t.mock.timers.tick(15 * 60000); await h.flush();
    for (let i = 0; i < 2; i++) { t.mock.timers.tick(30000); await h.flush(); }
    t.mock.timers.tick(30000); h.syncError = null; h.renewError = null; h.hide();
    h.snapshotRevision = 2; h.snapshots = { data: [{ id: 2, body: 'Hidden fresh update' }] };
    const chunk = Buffer.from(JSON.stringify([{ op: 'set', path: ['data', 0, 'body'], value: 'Hidden fresh update' }])).toString('base64');
    h.channels[0].receive({ viewId: '1', batch: 'hidden-fresh', base: 1, revision: 2, part: 0, total: 1, chunk });
    await h.flush(); assert.equal(h.count('post', '/1/sync'), 3);
    h.show(); await h.flush(); assert.equal(h.value.status, 'live'); assert.equal(h.count('post', '/1/sync'), 4);
});

test('an applied protected frame cannot bypass a 429 HTTP cooldown', async t => {
    t.mock.timers.enable({ apis: ['setTimeout', 'Date'], now: 100000 });
    const h = harness(t, { raw: true }); await h.flush(); h.channels[0].subscribed(); await h.flush();
    h.syncError = { response: { status: 429, headers: { 'retry-after': '42' } } }; h.value.sync(); await h.flush();
    h.syncError = null; h.snapshotRevision = 2; h.snapshots = { data: [{ id: 2, body: 'Fresh direct update' }] };
    const chunk = Buffer.from(JSON.stringify([{ op: 'set', path: ['data', 0, 'body'], value: 'Fresh direct update' }])).toString('base64');
    h.channels[0].receive({ viewId: '1', batch: '429-fresh', base: 1, revision: 2, part: 0, total: 1, chunk });
    await h.flush(); assert.equal(h.value.status, 'live'); assert.equal(h.count('post', '/1/sync'), 2);
    h.value.sync(); await h.flush(); assert.equal(h.count('post', '/1/sync'), 2);
    t.mock.timers.tick(41999); await h.flush(); assert.equal(h.count('post', '/1/sync'), 2);
    t.mock.timers.tick(1); await h.flush(); assert.equal(h.count('post', '/1/sync'), 3);
});
