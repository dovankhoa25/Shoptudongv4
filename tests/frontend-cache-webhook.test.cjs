const { test } = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const vm = require('node:vm');
const crypto = require('node:crypto');
const ts = require('typescript');

for (const root of ['123nick.com v4', 'shophhp.net v4', 'vanghhp.vn v4']) {
    test(root + ': signed invalidation, rejected inputs and bounded payload', async () => {
        const source = fs.readFileSync(path.resolve(__dirname, '../../', root, 'app/api/webhooks/invalidate/route.ts'), 'utf8');
        const compiled = ts.transpileModule(source, { compilerOptions: { module: ts.ModuleKind.CommonJS, target: ts.ScriptTarget.ES2022, esModuleInterop: true }, reportDiagnostics: true });
        assert.equal(compiled.diagnostics.length, 0);
        const secret = 'test-secret-with-at-least-32-characters';
        const tags = [];
        const exports = {};
        const sandbox = { exports, module: { exports }, Buffer, Response, Date, Object,
            process: { env: { WEBHOOK_SECRET: secret } },
            require(name) {
                if (name === 'next/cache') return { revalidateTag: (tag, options) => tags.push([tag, options.expire]) };
                if (name === 'crypto') return crypto;
                throw new Error('Unexpected dependency ' + name);
            }
        };
        vm.runInNewContext(compiled.outputText, sandbox);
        const send = async (payload, signature) => {
            const body = typeof payload === 'string' ? payload : JSON.stringify(payload);
            return exports.POST(new Request('https://example.test/api/webhooks/invalidate', { method: 'POST', body, headers: {
                'x-webhook-signature': signature ?? 'sha256=' + crypto.createHmac('sha256', secret).update(body).digest('hex'),
            }}));
        };
        const timestamp = Math.floor(Date.now() / 1000);
        assert.equal((await send({ type: 'groups', groups: ['nick', 'prices'], timestamp })).status, 200);
        assert.ok(tags.some(([tag, expire]) => tag === 'nick-detail' && expire === 0));
        assert.ok(tags.some(([tag]) => tag === 'categories'));
        assert.ok(tags.some(([tag]) => tag === 'server-prices'));
        tags.length = 0;
        assert.equal((await send({ type: 'global', timestamp }, 'short')).status, 401);
        assert.equal((await send({ type: 'global' })).status, 401);
        assert.equal((await send({ type: 'global', timestamp: timestamp - 301 })).status, 401);
        assert.equal((await send({ type: 'groups', groups: ['__proto__'], timestamp })).status, 400);
        assert.equal((await send('{')).status, 400);
        assert.equal((await send('x'.repeat(9000))).status, 413);
        assert.deepEqual(tags, []);
        assert.equal((await send({ type: 'category', slug: 'nick-nro', timestamp })).status, 200);
        assert.ok(tags.some(([tag]) => tag === 'game-types'));
        assert.ok(tags.some(([tag]) => tag === 'services'));
        assert.ok(tags.some(([tag]) => tag === 'sitemap'));
    });
}
