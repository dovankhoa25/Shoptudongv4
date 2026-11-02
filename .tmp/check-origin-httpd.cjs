const fs = require('node:fs');
const path = require('node:path');
const { spawn } = require('node:child_process');

async function main() {
  const serverRoot = 'D:/laragon/bin/apache/httpd-2.4.66-260223-Win64-VS18';
  const root = path.join(__dirname, 'origin-httpd-test').replaceAll('\\', '/');
  fs.mkdirSync(root + '/nested', { recursive: true });
  fs.copyFileSync(path.join(__dirname, '../public/.htaccess'), root + '/.htaccess');
  for (const name of ['build.zip', 'nested/backup.ZIP', 'database.sql', 'server.log', 'image.png']) {
    fs.writeFileSync(root + '/' + name, 'test fixture');
  }
  const port = 19863;
  fs.writeFileSync(root + '/httpd.conf', `ServerRoot "${serverRoot}"
Listen 127.0.0.1:${port}
ServerName 127.0.0.1
LoadModule authz_core_module modules/mod_authz_core.so
LoadModule authz_host_module modules/mod_authz_host.so
LoadModule rewrite_module modules/mod_rewrite.so
PidFile "${root}/httpd.pid"
ErrorLog "${root}/error.log"
LogLevel error
DocumentRoot "${root}"
<Directory "${root}">
    AllowOverride All
    Require all granted
</Directory>
`);
  const child = spawn(serverRoot + '/bin/httpd.exe', ['-f', root + '/httpd.conf', '-DFOREGROUND'], {
    windowsHide: true, stdio: ['ignore', 'ignore', 'pipe'],
  });
  let errors = '';
  child.stderr.on('data', value => errors += value);
  try {
    let started = false;
    for (let attempt = 0; attempt < 30; attempt++) {
      if (child.exitCode !== null) throw new Error(errors || 'Apache exited early');
      try {
        const response = await fetch(`http://127.0.0.1:${port}/image.png`, { signal: AbortSignal.timeout(500) });
        if (response.status === 200) { started = true; break; }
      } catch {}
      await new Promise(resolve => setTimeout(resolve, 200));
    }
    if (!started) throw new Error('Apache did not start: ' + errors);
    const checks = [];
    for (const [name, expected] of [['build.zip', 403], ['nested/backup.ZIP', 403], ['database.sql', 403], ['server.log', 403], ['image.png', 200]]) {
      const response = await fetch(`http://127.0.0.1:${port}/${name}`);
      checks.push({ name, expected, actual: response.status });
      if (response.status !== expected) throw new Error(JSON.stringify(checks));
    }
    fs.writeFileSync(path.join(__dirname, 'origin-httpd-test-results.json'), JSON.stringify(checks, null, 2));
    console.log(JSON.stringify(checks));
  } finally {
    // Apache's parent gracefully stops its Windows worker as well.
    const stop = spawn(serverRoot + '/bin/httpd.exe', ['-f', root + '/httpd.conf', '-k', 'shutdown'], { windowsHide: true, stdio: 'ignore' });
    await new Promise(resolve => stop.once('exit', resolve));
    if (child.exitCode === null) child.kill();
  }
}

main().catch(error => { console.error(error.message); process.exitCode = 1; });
