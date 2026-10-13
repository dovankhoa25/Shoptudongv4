const test = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const Module = require('node:module');
const ts = require('typescript');

const filename = path.resolve(__dirname, '../../resources/js/Components/tradingBotActivityState.ts');
const loaded = new Module(filename, module);
loaded._compile(ts.transpileModule(fs.readFileSync(filename, 'utf8'), {
    compilerOptions: { module: ts.ModuleKind.CommonJS, target: ts.ScriptTarget.ES2022 },
}).outputText, filename);
const { nextTradingBotActivity, tradingBotActivityView } = loaded.exports;
const observed = Date.parse('2026-10-14T12:00:00Z');
const event = {
    bot_id: 7, bot_type: 'gem_bot', code: 'ready', text: 'Sẵn sàng',
    observed_at: new Date(observed).toISOString(), expires_at: new Date(observed + 35000).toISOString(),
    online_seconds: 60, map_id: 84, zone: 41, x: 652, y: 336,
};

test('Only the selected bot receives ordered, valid activity observations', () => {
    assert.equal(nextTradingBotActivity(null, event, 7, 'gem_bot'), event);
    assert.equal(nextTradingBotActivity(null, event, 8, 'gem_bot'), null);
    assert.equal(nextTradingBotActivity(null, event, 7, 'bot'), null);
    const newer = { ...event, observed_at: new Date(observed + 5000).toISOString() };
    assert.equal(nextTradingBotActivity(newer, event, 7, 'gem_bot'), newer);
    assert.equal(nextTradingBotActivity(event, { ...event, observed_at: 'invalid' }, 7, 'gem_bot'), event);
    assert.equal(nextTradingBotActivity(event, { ...event, expires_at: event.observed_at }, 7, 'gem_bot'), event);
});

test('Activity expires at 35 seconds without changing the web command or retaining an online clock', () => {
    const before = JSON.stringify(event);
    assert.deepEqual(tradingBotActivityView(event, observed + 34000), {
        fresh: true, onlineSeconds: 94, waitSeconds: 0, location: 'Map 84 · Khu 41 · 652-336',
    });
    assert.deepEqual(tradingBotActivityView(event, observed + 35000), {
        fresh: false, onlineSeconds: 0, waitSeconds: 0, location: '',
    });
    assert.equal(JSON.stringify(event), before);
    assert.equal(tradingBotActivityView({ ...event, expires_at: 'invalid' }, observed).fresh, false);
});

test('A zero or absent online observation never invents time online', () => {
    for (const online_seconds of [0, undefined, null, -1, Infinity]) {
        assert.equal(tradingBotActivityView({ ...event, online_seconds }, observed + 10000).onlineSeconds, 0);
    }
    assert.equal(tradingBotActivityView(event, observed - 5000).onlineSeconds, 60);
    assert.equal(tradingBotActivityView({ ...event, wait_seconds: 8 }, observed + 10000).waitSeconds, 0);
});

test('Unknown locations are omitted individually instead of displaying null, undefined or sentinel map -1', () => {
    assert.equal(tradingBotActivityView({ ...event, map_id: null, zone: undefined, x: -1 }, observed).location, '');
    assert.equal(tradingBotActivityView({ ...event, zone: -1, x: undefined, y: undefined }, observed).location, 'Map 84');
    assert.equal(tradingBotActivityView({ ...event, map_id: 0, zone: 0, x: 0, y: 0 }, observed).location, 'Map 0 · Khu 0 · 0-0');
});
