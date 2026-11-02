const fs = require('node:fs');
const path = require('node:path');
const net = require('node:net');

// Read-only public HTTP/DNS audit. Retain summaries, never response bodies/cookies.
const result = { checkedAt: new Date().toISOString(), dns: [], http: [], bundles: [] };
const pages = new Map();
const publicHeader = /^(?:server|location|via|x-powered-by|x-served-by|x-backend.*|x-server.*|x-origin.*|cf-ray|cf-cache-status|content-type|content-security-policy|link|refresh)$/i;
const infra = /(?:^|\.)(?:123nick\.com|shophhp\.(?:net|vn)|vanghhp\.vn)$/i;

async function pool(items, fn, concurrency = 3) {
  let index = 0;
  await Promise.all(Array.from({ length: Math.min(concurrency, items.length) }, async () => {
    while (index < items.length) await fn(items[index++]);
  }));
}

function inspect(body) {
  const hosts = new Set();
  const ipUrls = new Set();
  for (const match of body.matchAll(/(?:https?|wss?):(?:\/\/|\\\/\\\/)([^\s/\\'"`<>{}$),;]+)/g)) {
    const hostname = match[1].split('@').pop().replace(/:\d+$/, '').replace(/^\[|\]$/g, '');
    if (infra.test(hostname)) hosts.add(hostname);
    if (net.isIP(hostname)) ipUrls.add(hostname);
  }
  const indicators = [];
  for (const [label, expression] of [
    ['stack_trace', /"(?:exception|trace)"\s*:|Stack trace:|Whoops!|Ignition|SQLSTATE\[/i],
    ['environment_file', /^(?:APP_KEY|DB_PASSWORD|APP_ENV)=/m],
    ['git_head', /^ref: refs\/heads\//m],
    ['phpinfo', /<title>phpinfo\(\)<\/title>|PHP Variables|Server API<\/td>/i],
    ['directory_listing', /<title>Index of|<h1>Index of/i],
  ]) if (expression.test(body)) indicators.push(label);
  return { hosts: [...hosts], ipUrls: [...ipUrls], indicators };
}

async function get(url, { method = 'GET', limit = 2_000_000, accept = '*/*', range = false } = {}) {
  try {
    const response = await fetch(url, {
      method, redirect: 'manual', signal: AbortSignal.timeout(15000),
      headers: { Accept: accept, 'User-Agent': 'Owner-Origin-Exposure-Audit/1.0', ...(range ? { Range: 'bytes=0-4095' } : {}) },
    });
    let body = '', bytes = 0;
    if (response.body && method !== 'HEAD') {
      const reader = response.body.getReader();
      const decoder = new TextDecoder();
      while (bytes < limit) {
        const { done, value } = await reader.read();
        if (done) break;
        const remaining = limit - bytes;
        body += decoder.decode(value.subarray(0, remaining), { stream: true });
        bytes += value.length;
      }
      await reader.cancel();
    }
    return { url, method, status: response.status, headers: Object.fromEntries([...response.headers].filter(([key]) => publicHeader.test(key))), sampledBytes: Math.min(bytes, limit), truncated: bytes >= limit, ...inspect(body), body };
  } catch (error) {
    return { url, method, error: error.message, code: error.cause?.code };
  }
}

function retain(collection, response) {
  const { body, ...summary } = response;
  collection.push(summary);
  console.log(JSON.stringify(summary));
}

async function dnsLookup(name, type) {
  for (const base of ['https://cloudflare-dns.com/dns-query', 'https://dns.google/resolve']) {
    try {
      const response = await fetch(`${base}?name=${encodeURIComponent(name)}&type=${type}`, { headers: { Accept: 'application/dns-json' }, signal: AbortSignal.timeout(9000) });
      if (!response.ok) continue;
      const json = await response.json();
      return { name, type, status: json.Status, answers: (json.Answer || []).map(({ name, type, data }) => ({ name, type, data })), resolver: new URL(base).hostname };
    } catch {}
  }
  return { name, type, error: 'DNS-over-HTTPS unavailable' };
}

async function main() {
  const sites = ['123nick.com', 'shophhp.net', 'vanghhp.vn'];
  await pool(sites, async name => {
    const response = await get(`https://${name}/`);
    if (response.body) pages.set(name, response.body);
    retain(result.http, response);
  });
  const hostnames = new Set([...sites, 'www.123nick.com', 'www.shophhp.net', 'www.vanghhp.vn', 'api.shophhp.net', 'api.shophhp.vn', 'admin.shophhp.net', 'admin.shophhp.vn', 'backup.shophhp.net', 'shophhp.vn']);
  for (const body of pages.values()) for (const hostname of inspect(body).hosts) hostnames.add(hostname);
  await pool([...hostnames].flatMap(name => ['A', 'AAAA', 'CNAME', 'MX', 'NS'].map(type => [name, type])), async ([name, type]) => {
    const summary = await dnsLookup(name, type);
    result.dns.push(summary);
    console.log(JSON.stringify({ dns: summary }));
  }, 4);
  const mxHosts = [...new Set(result.dns.flatMap(row => row.type === 'MX' ? row.answers.filter(a => a.type === 15).map(a => a.data.split(/\s+/).pop().replace(/\.$/, '')) : []))];
  await pool(mxHosts, async name => {
    const summary = await dnsLookup(name, 'A'); result.dns.push(summary); console.log(JSON.stringify({ dns: summary }));
  });
  const bundleUrls = new Set();
  for (const [name, body] of pages) {
    for (const match of body.matchAll(/<script[^>]+src="([^"]+)"/g)) {
      const url = new URL(match[1], `https://${name}`);
      if (url.hostname === name && url.pathname.startsWith('/_next/static/')) bundleUrls.add(url.href);
    }
  }
  await pool([...bundleUrls], async url => retain(result.bundles, await get(url)));
  const apiPaths = ['/api/card-types', '/api/game-types-with-categories', '/api/servers', '/api/gem/servers', '/api/bots/overview', '/api/bots?server_id=1&type=selling_main', '/api/gem/bots?server_id=1', '/api/server-prices', '/api/nro-shop/catalog', '/api/bots', '/api/nick/0', '/api/not-found-origin-audit', '/api/me', '/app/v2/servers/login', '/app/v3/runtime/config'];
  const targets = sites.flatMap(name => [`http://${name}/`, `https://${name}/robots.txt`, `https://${name}/sitemap.xml`]);
  for (const name of ['api.shophhp.net', 'api.shophhp.vn', 'backup.shophhp.net', 'admin.shophhp.vn', 'admin.shophhp.net']) {
    targets.push(`https://${name}/`, `http://${name}/`, `https://${name}/login`);
  }
  for (const name of ['api.shophhp.net', 'backup.shophhp.net']) {
    for (const apiPath of apiPaths) targets.push(`https://${name}${apiPath}`);
  }
  await pool(targets, async url => retain(result.http, await get(url, { method: url.startsWith('http:') ? 'HEAD' : 'GET', accept: url.includes('/api/') || url.includes('/app/') ? 'application/json' : '*/*' })));
  const sensitivePaths = ['/.env', '/.env.production', '/.git/HEAD', '/phpinfo.php', '/info.php', '/storage/logs/laravel.log', '/build.zip', '/build/manifest.json', '/hot'];
  await pool([...sites, 'api.shophhp.net', 'backup.shophhp.net'].flatMap(name => sensitivePaths.map(p => `https://${name}${p}`)), async url => retain(result.http, await get(url, { limit: 4096, range: true })));
  fs.writeFileSync(path.join(__dirname, 'origin-ip-audit-results.json'), JSON.stringify(result, null, 2));
  console.log(JSON.stringify({ finished: true, http: result.http.length, bundles: result.bundles.length, dns: result.dns.length, ips: [...new Set([...result.http, ...result.bundles].flatMap(x => x.ipUrls || []))], indicators: [...result.http, ...result.bundles].filter(x => x.indicators?.length) }));
}

main().catch(error => { console.error(error.message); process.exitCode = 1; });
