const { test } = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const vm = require('node:vm');
const ts = require('typescript');

// Exercise the real list callback/effect without mounting the unrelated editor,
// attachment and payment UI in this large component.
const filename = path.resolve(__dirname, '../../resources/js/Features/Chat/ChatWorkspace.tsx');
const source = ts.createSourceFile(filename, fs.readFileSync(filename, 'utf8'), ts.ScriptTarget.Latest, true, ts.ScriptKind.TSX);
let read, fallback;
function visit(node) {
    if (ts.isVariableDeclaration(node) && node.name.getText(source) === 'fetchConversations') read = node.initializer.arguments[0];
    if (ts.isCallExpression(node) && node.expression.getText(source) === 'useEffect'
        && node.arguments[0]?.getText(source).includes('chatFallbackSignatureRef.current')) fallback = node.arguments[0];
    ts.forEachChild(node, visit);
}
visit(source);
function callback(node, context) {
    assert.ok(node, 'list callback still exists');
    return vm.runInNewContext(ts.transpileModule(`(${node.getText(source)})`, {
        compilerOptions: { target: ts.ScriptTarget.ES2022 },
    }).outputText, context);
}
function setup() {
    const state = { requests: [], timers: new Map(), syncs: 0 };
    const context = {
        AbortController, mode: 'agent', baseUrl: '/admin/chat', quiet: false,
        assignment: 'mine', inboxView: 'completed', completedPeriod: 'week', search: 'support', status: '',
        conversationPerPage: 20, conversationFilterSignature: 'completed:week:mine:support',
        currentUserId: 7, props: { auth: { realtime_channel: 'lease-a' } }, initialConversationId: null,
        chatLive: { status: 'denied' }, chatLiveSyncRef: { current: () => state.syncs++ },
        chatFallbackSignatureRef: { current: null }, conversationFilterSignatureRef: { current: 'completed:week:mine:support' },
        conversationListGenerationRef: { current: 0 }, conversationListAbortRef: { current: null },
        conversationMoreGenerationRef: { current: 0 }, conversationMoreAbortRef: { current: null },
        conversationsRef: { current: [] }, unreadTotalRef: { current: 0 }, selectedIdRef: { current: null },
        statusBelongsToView: status => status === 'resolved', sortConversations: rows => rows,
        recordConversationListSnapshot() {}, isCanceledRequest: () => false, errorMessage: e => e.message,
        window: {
            setTimeout(fn) { const id = Symbol(); state.timers.set(id, fn); return id; },
            clearTimeout(id) { state.timers.delete(id); },
            axios: { get(url, config) { return new Promise(resolve => state.requests.push({ url, config, resolve })); } },
        },
    };
    for (const key of ['LoadingMoreConversations', 'LoadingList', 'Conversations', 'UnreadTotal', 'ConversationCounts', 'ConversationTotal', 'ConversationPage', 'ConversationLastPage', 'Error', 'SelectedId']) {
        context[`set${key}`] = value => { state[key] = value; };
    }
    context.fetchConversations = callback(read, context);
    const runFallback = callback(fallback, context);
    return { state, context, runFallback, tick() { const pending = [...state.timers.values()]; state.timers.clear(); pending.forEach(fn => fn()); } };
}

test('agent event refresh stays on realtime; explicit fallback preserves all list filters', async () => {
    const { state, context } = setup();
    await context.fetchConversations(true); await context.fetchConversations();
    assert.equal(state.requests.length, 0); assert.equal(state.syncs, 1);
    const pending = context.fetchConversations(false, true);
    assert.equal(state.requests[0].url, '/admin/chat/conversations');
    assert.equal(state.requests[0].config.params.assignment, 'mine');
    assert.equal(state.requests[0].config.params.view, 'completed');
    assert.equal(state.requests[0].config.params.period, 'week');
    state.requests[0].resolve({ data: { data: [{ id: 1, status: 'resolved' }, { id: 2, status: 'waiting_agent' }], unread_total: 3, meta: { total: 1 } } });
    await pending;
    assert.equal(state.Conversations.length, 1); assert.equal(state.Conversations[0].id, 1);
    assert.equal(state.LoadingList, false);
});

test('failed live re-registration while loading more does not reset chat to page one repeatedly', () => {
    const h = setup(); h.runFallback(); h.tick();
    assert.equal(h.state.requests.length, 1);
    h.context.chatLive.status = 'connecting'; h.runFallback();
    h.context.chatLive.status = 'denied'; h.runFallback(); h.tick();
    assert.equal(h.state.requests.length, 1);
    h.context.conversationFilterSignature = 'new-filter'; h.context.conversationFilterSignatureRef.current = 'new-filter';
    h.runFallback(); h.tick(); assert.equal(h.state.requests.length, 2);
});

test('cancelled search timers do not consume the fallback attempt; healthy reconnect permits a later fallback', () => {
    const h = setup(); const cleanup = h.runFallback(); cleanup(); h.tick();
    assert.equal(h.state.requests.length, 0);
    h.runFallback(); h.tick(); assert.equal(h.state.requests.length, 1);
    h.context.chatLive.status = 'live'; h.runFallback();
    h.context.chatLive.status = 'offline'; h.runFallback(); h.tick();
    assert.equal(h.state.requests.length, 2);
});

test('late HTTP response is ignored after the active chat filter changes', async () => {
    const { state, context } = setup();
    const pending = context.fetchConversations(false, true);
    context.conversationFilterSignatureRef.current = 'new-filter';
    state.requests[0].resolve({ data: { data: [{ id: 1, status: 'resolved' }], unread_total: 0 } });
    await pending; assert.equal(state.Conversations, undefined);
});
