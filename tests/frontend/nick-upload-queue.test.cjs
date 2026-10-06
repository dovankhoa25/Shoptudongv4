const assert = require('node:assert/strict');
const { test } = require('node:test');
const fs = require('node:fs');
const path = require('node:path');
const vm = require('node:vm');
const ts = require('typescript');
const source = fs.readFileSync(path.join(__dirname, '../../resources/js/Utils/NickUploadQueue.ts'), 'utf8');
const output = ts.transpileModule(source, { compilerOptions: { module: ts.ModuleKind.CommonJS, target: ts.ScriptTarget.ES2022 } }).outputText;
const context = { exports: {}, AbortController, Error };
vm.runInNewContext(output, context);
const { NickUploadQueue } = context.exports;
const tick = () => new Promise(resolve => setImmediate(resolve));
const file = name => ({ name });

test('uploads at most three files and retains selected order after out-of-order completions', async () => {
    const requests = new Map();
    let active = 0, peak = 0, view = [];
    const queue = new NickUploadQueue((file, signal, progress) => {
        active++; peak = Math.max(peak, active);
        return new Promise(resolve => requests.set(file, () => { active--; progress(100); resolve(file.name); }));
    }, () => {}, entries => view = entries);
    const files = ['a', 'b', 'c', 'd', 'e'].map(file);
    queue.setFiles(files);
    assert.equal(requests.size, 3);
    requests.get(files[2])(); await tick();
    assert.ok(requests.has(files[3]));
    requests.get(files[1])(); await tick();
    requests.get(files[4])(); requests.get(files[3])(); requests.get(files[0])(); await tick();
    assert.equal(peak, 3);
    assert.equal(active, 0);
    assert.equal(JSON.stringify(view.map(entry => entry.id)), JSON.stringify(['a', 'b', 'c', 'd', 'e']));
    assert.ok(view.every(entry => entry.status === 'ready'));
});

test('retry uploads only the failed file and re-rendering does not resend ready files', async () => {
    const counts = new Map(); let view = [];
    const files = ['ok', 'retry'].map(file);
    const queue = new NickUploadQueue(async file => {
        counts.set(file, (counts.get(file) || 0) + 1);
        if (file.name === 'retry' && counts.get(file) === 1) throw new Error('network');
        return file.name;
    }, () => {}, entries => view = entries);
    queue.setFiles(files); await tick();
    assert.equal(view[1].status, 'failed');
    queue.setFiles([...files]); await tick();
    assert.equal(counts.get(files[1]), 1);
    queue.retry(); await tick();
    assert.equal(counts.get(files[0]), 1);
    assert.equal(counts.get(files[1]), 2);
    assert.ok(view.every(entry => entry.status === 'ready'));
});

test('removed or abandoned uploads cannot reappear after a late server response', async () => {
    const removed = []; let resolve, view = [];
    const queue = new NickUploadQueue(() => new Promise(done => resolve = done), id => removed.push(id), entries => view = entries);
    queue.setFiles([file('removed')]);
    queue.setFiles([]);
    resolve('late-upload'); await tick();
    assert.equal(view.length, 0);
    assert.deepEqual(removed, ['late-upload']);
    queue.setFiles([file('abandoned')]);
    queue.dispose();
    resolve('abandoned-upload'); await tick();
    assert.deepEqual(removed, ['late-upload', 'abandoned-upload']);
});

test('switching to the old form releases unused uploaded tokens without changing the original files', async () => {
    const removed = []; const files = [file('one'), file('two')];
    const queue = new NickUploadQueue(async file => file.name, id => removed.push(id), () => {});
    queue.setFiles(files); await tick();
    queue.setFiles([]); await tick();
    assert.deepEqual(removed, ['one', 'two']);
    assert.equal(files.length, 2);
});
