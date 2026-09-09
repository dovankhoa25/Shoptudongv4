const { test } = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const Module = require('node:module');
const ts = require('typescript');
const React = require('react');
const { renderToStaticMarkup } = require('react-dom/server');

function load(file, overrides = {}) {
    const filename = path.resolve(__dirname, '../../resources/js/Features/Chat', file);
    const instance = new Module(filename, module);
    instance.filename = filename;
    instance.paths = Module._nodeModulePaths(path.dirname(filename));
    const originalRequire = instance.require.bind(instance);
    instance.require = name => overrides[name] ?? originalRequire(name);
    instance._compile(ts.transpileModule(fs.readFileSync(filename, 'utf8'), {
        compilerOptions: { module: ts.ModuleKind.CommonJS, target: ts.ScriptTarget.ES2022, jsx: ts.JsxEmit.ReactJSX },
    }).outputText, filename);
    return instance.exports;
}

const { clipboardImages, continuesMessageGroup, latestReadCustomerMessageId } = load('chatUi.ts');
const { ChatAvatar, ReadReceipt, MessageActions } = load('MessageTools.tsx');
const item = file => ({ kind: 'file', type: file.type, getAsFile: () => file });

test('plain text and URL paste are left to the browser', () => {
    assert.deepEqual(clipboardImages({ items: [{ kind: 'string', type: 'text/plain' }], files: [] }), []);
});
test('image paste handles screenshot plus text without duplicating fallback files', () => {
    const file = new File(['png'], 'screenshot.png', { type: 'image/png' });
    assert.deepEqual(clipboardImages({ items: [item(file), { kind: 'string', type: 'text/plain' }], files: [file] }), [file]);
});
test('clipboard falls back to files and ignores non-image files', () => {
    const image = new File(['jpeg'], 'photo.jpg', { type: 'image/jpeg' });
    const document = new File(['text'], 'note.txt', { type: 'text/plain' });
    assert.deepEqual(clipboardImages({ items: [], files: [image, document] }), [image]);
});
test('null clipboard item does not create an attachment', () => {
    assert.deepEqual(clipboardImages({ items: [{ kind: 'file', type: 'image/png', getAsFile: () => null }], files: [] }), []);
});

const message = (id, patch = {}) => ({ id, conversation_id: 10, sender_kind: 'agent', sender: { id: 5 }, type: 'text', is_internal: false, created_at: '2026-09-10T10:00:00Z', seen_by: [], ...patch });
test('only adjacent messages from the same author and conversation group within five minutes', () => {
    assert.equal(continuesMessageGroup(message(1), message(2, { created_at: '2026-09-10T10:04:59Z' })), true);
    for (const patch of [{ sender: { id: 6 } }, { sender: null }, { sender_kind: 'customer' }, { conversation_id: 11 }, { type: 'tip' }, { type: 'system' }, { is_internal: true }, { created_at: '2026-09-10T10:06:00Z' }, { created_at: 'bad-date' }]) {
        assert.equal(continuesMessageGroup(message(1), message(2, patch)), false);
    }
    assert.equal(continuesMessageGroup(undefined, message(1)), false);
});
test('one receipt belongs to the highest confirmed, seen customer message only', () => {
    const seen = { sender_kind: 'customer', seen_by: [{ id: 5 }] };
    assert.equal(latestReadCustomerMessageId([
        message(2, seen), message(7, seen), message(8), message(9, { ...seen, type: 'tip' }),
        message(10, { ...seen, is_internal: true }), message(-1, seen), message(11, { sender_kind: 'customer' }),
    ]), 7);
    assert.equal(latestReadCustomerMessageId([message(2)]), null);
});
test('receipt is compact, deduplicates viewers and does not expose names until expanded', () => {
    const html = renderToStaticMarkup(React.createElement(ReadReceipt, { readers: [{ id: 1, display_name: 'Private name' }, { id: 1 }, { id: 2 }] }));
    assert.match(html, /Đã xem · 2 người/);
    assert.doesNotMatch(html, /Private name/);
    assert.match(html, /aria-expanded="false"/);
});
test('missing avatar renders a blue initial from the public display name', () => {
    const html = renderToStaticMarkup(React.createElement(ChatAvatar, { user: { id: 1, display_name: 'Hỗ trợ', username: 'internal-account', avatar: null } }));
    assert.match(html, /bg-blue-600/);
    assert.match(html, />H<\/span>/);
    assert.doesNotMatch(html, /internal-account/);
});
test('disabled reactions keep content but render no reaction control', () => {
    const html = renderToStaticMarkup(React.createElement(MessageActions, { enabled: false, open: false, alignRight: true, onOpenChange() {}, onReact() {}, emojis: ['👍'] }, 'hello'));
    assert.match(html, /hello/);
    assert.doesNotMatch(html, /Thả cảm xúc/);
});

function gestureHarness() {
    const cleanups = [];
    const opens = [];
    const mockReact = { ...React, useRef: initial => ({ current: initial }), useEffect: effect => { const cleanup = effect(); if (cleanup) cleanups.push(cleanup); } };
    const tools = load('MessageTools.tsx', { react: mockReact });
    const tree = tools.MessageActions({ children: 'hello', enabled: true, alignRight: false, open: false, onOpenChange: value => opens.push(value), onReact() {}, emojis: ['👍'] });
    return { handlers: tree.props, opens, cleanup: () => cleanups.forEach(fn => fn()) };
}
const pointer = (type = 'touch') => ({ pointerType: type, isPrimary: true, clientX: 0, clientY: 0, target: { closest: () => null } });
test('touch long press opens actions without submitting anything', t => {
    t.mock.timers.enable({ apis: ['setTimeout'] });
    const h = gestureHarness(); h.handlers.onPointerDown(pointer());
    t.mock.timers.tick(499); assert.deepEqual(h.opens, []);
    t.mock.timers.tick(1); assert.deepEqual(h.opens, [true]); h.cleanup();
});
test('scrolling, cancellation and unmount cancel long press', t => {
    t.mock.timers.enable({ apis: ['setTimeout'] });
    for (const cancel of [h => h.handlers.onPointerMove({ clientX: 20, clientY: 0 }), h => h.handlers.onPointerCancel(), h => h.cleanup()]) {
        const h = gestureHarness(); h.handlers.onPointerDown(pointer()); cancel(h);
        t.mock.timers.tick(600); assert.deepEqual(h.opens, []); h.cleanup();
    }
});
test('a mouse press is not interpreted as mobile long press', t => {
    t.mock.timers.enable({ apis: ['setTimeout'] });
    const h = gestureHarness(); h.handlers.onPointerDown(pointer('mouse'));
    t.mock.timers.tick(600); assert.deepEqual(h.opens, []); h.cleanup();
});
