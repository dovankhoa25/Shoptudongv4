const { test } = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const Module = require('node:module');
const ts = require('typescript');

const filename = path.resolve(__dirname, '../../resources/js/Realtime/echoConfig.ts');
const instance = new Module(filename, module);
instance.filename = filename;
instance.paths = Module._nodeModulePaths(path.dirname(filename));
instance._compile(ts.transpileModule(fs.readFileSync(filename, 'utf8'), {
    compilerOptions: { module: ts.ModuleKind.CommonJS, target: ts.ScriptTarget.ES2022 },
}).outputText, filename);
const { buildEchoConfig } = instance.exports;

function applicationSetup(dependencies) {
    const appPath = path.resolve(__dirname, '../../resources/js/app.tsx');
    const source = ts.createSourceFile(appPath, fs.readFileSync(appPath, 'utf8'), ts.ScriptTarget.Latest, true, ts.ScriptKind.TSX);
    let setup;
    function find(node) {
        if (ts.isMethodDeclaration(node) && node.name.getText(source) === 'setup') setup = node;
        ts.forEachChild(node, find);
    }
    find(source); assert.ok(setup);
    const compiled = ts.transpileModule(`exports.setup = ({ ${setup.getText(source)} }).setup;`, {
        compilerOptions: { module: ts.ModuleKind.CommonJS, target: ts.ScriptTarget.ES2022, jsx: ts.JsxEmit.ReactJSX },
    }).outputText;
    const exports = {};
    new Function('exports', 'require', ...Object.keys(dependencies), compiled)(exports, require, ...Object.values(dependencies));
    return exports.setup;
}

test('runtime Inertia configuration works when production HTML has no meta tags', () => {
    const config = buildEchoConfig({ public_key: 'production.public', csrf_token: 'session-csrf' }, () => null);
    assert.equal(config.key, 'production.public');
    assert.equal(config.csrfToken, 'session-csrf');
    assert.equal(config.authEndpoint, '/broadcasting/auth');
    assert.deepEqual(config.enabledTransports, ['ws', 'wss']);
});

test('runtime values take precedence over stale HTML while legacy meta bootstrap remains compatible', () => {
    const meta = name => ({ 'ably-public-key': 'legacy.public', 'csrf-token': 'legacy-csrf' })[name];
    const current = buildEchoConfig({ public_key: 'current.public', csrf_token: 'current-csrf' }, meta);
    assert.equal(current.key, 'current.public');
    assert.equal(current.csrfToken, 'current-csrf');
    const legacy = buildEchoConfig(undefined, meta);
    assert.equal(legacy.key, 'legacy.public');
    assert.equal(legacy.csrfToken, 'legacy-csrf');
});

test('private-key fields are ignored and an accidental private suffix is never passed to Echo', () => {
    const config = buildEchoConfig({ public_key: 'safe.public:private-suffix', key: 'another:private-key' }, () => null);
    assert.equal(config.key, 'safe.public');
    assert.equal(config.csrfToken, null);
    assert.equal(JSON.stringify(config).includes('private'), false);
    assert.equal(buildEchoConfig({ key: 'another:private-key' }, () => null).key, '');
});

test('the actual Echo connector derives the web authorization CSRF header without HTML metas', async () => {
    const { default: Echo } = await import('laravel-echo');
    const client = { connection: { socket_id: 'test-socket' }, disconnect() {} };
    const echo = new Echo({ ...buildEchoConfig({ public_key: 'safe.public', csrf_token: 'runtime-csrf' }, () => null), client });
    assert.equal(echo.connector.options.auth.headers['X-CSRF-TOKEN'], 'runtime-csrf');
    assert.equal(echo.connector.options.authEndpoint, '/broadcasting/auth');
    echo.disconnect();
});

test('the real application setup configures Echo from initial props before rendering React', () => {
    const order = []; let configured;
    const setup = applicationSetup({
        buildEchoConfig,
        configureEcho: config => { configured = config; order.push('configure'); },
        createRoot: () => ({ render() { order.push('render'); } }),
        document: { querySelector: () => null }, ToastProvider: () => null,
        router: { on() {} },
    });
    setup({ el: {}, App: () => null, props: { initialPage: { props: {
        realtime: { public_key: 'setup.public', csrf_token: 'setup-csrf' },
    } } } });
    assert.deepEqual(order, ['configure', 'render']);
    assert.equal(configured.key, 'setup.public');
    assert.equal(configured.csrfToken, 'setup-csrf');
});

test('the actual SDK uses rotated CSRF after the real SPA navigation hook without recreating Echo', async () => {
    const { default: Echo } = await import('laravel-echo');
    const Pusher = require('pusher-js');
    const runtime = Pusher.Runtime;
    const previous = { createXHR: runtime.createXHR, getDefaultStrategy: runtime.getDefaultStrategy };
    const requests = []; let configurations = 0, echo; const navigation = {};
    // Exercise the real authorizer/header code, with no real websocket or HTTP.
    runtime.getDefaultStrategy = () => ({ isSupported: () => false });
    runtime.createXHR = () => ({
        headers: {},
        open(method, url) { this.method = method; this.url = url; },
        setRequestHeader(name, value) { this.headers[name] = value; },
        send(body) {
            requests.push({ url: this.url, headers: this.headers, body });
            this.status = 200; this.readyState = 4; this.responseText = '{"auth":"test-signature"}';
            this.onreadystatechange();
        },
    });
    try {
        const setup = applicationSetup({
            buildEchoConfig,
            configureEcho(config) { configurations++; echo = new Echo({ ...config, Pusher }); },
            createRoot: () => ({ render() {} }), document: { querySelector: () => null }, ToastProvider: () => null,
            router: { on(name, callback) { navigation[name] = callback; } },
        });
        setup({ el: {}, App: () => null, props: { initialPage: { props: {
            realtime: { public_key: 'setup.public', csrf_token: 'guest-csrf' },
        } } } });
        const authorize = () => echo.connector.pusher.config.channelAuthorizer(
            { socketId: 'test-socket', channelName: 'private-Chat.User.7.web-test' },
            error => assert.equal(error, null),
        );
        authorize();
        navigation.navigate({ detail: { page: { props: {
            realtime: { public_key: 'setup.public', csrf_token: 'after-login-csrf' },
        } } } });
        authorize();
        navigation.navigate({ detail: { page: { props: { ordinary_partial_prop: true } } } });
        authorize();
        assert.equal(configurations, 1);
        assert.equal(requests.length, 3);
        assert.equal(requests[0].headers['X-CSRF-TOKEN'], 'guest-csrf');
        assert.equal(requests[1].headers['X-CSRF-TOKEN'], 'after-login-csrf');
        assert.equal(requests[2].headers['X-CSRF-TOKEN'], 'after-login-csrf');
        assert.ok(requests.every(request => request.url === '/broadcasting/auth'));
        assert.ok(requests.every(request => !('X-XSRF-TOKEN' in request.headers)));
    } finally {
        echo?.disconnect();
        runtime.createXHR = previous.createXHR;
        runtime.getDefaultStrategy = previous.getDefaultStrategy;
    }
});
