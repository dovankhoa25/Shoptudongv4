<?php
// Test-only web switch for the currently running isolated localhost fixture.
$path=realpath($argv[1]??'');$temp=realpath(sys_get_temp_dir());
if(!$path||!$temp||!str_starts_with(strtolower($path),strtolower($temp.DIRECTORY_SEPARATOR))
    ||!str_starts_with(basename(dirname($path)),'trading-v3-gold-db-')||basename($path)!=='test.sqlite')throw new RuntimeException('Isolated gold fixture path required.');
$db=new PDO('sqlite:'.$path);$db->setAttribute(PDO::ATTR_ERRMODE,PDO::ERRMODE_EXCEPTION);$db->exec('PRAGMA busy_timeout=5000');
if((int)$db->query('SELECT COUNT(*) FROM bots WHERE id IN (1,2,3,4)')->fetchColumn()!==4
    ||(int)$db->query("SELECT COUNT(*) FROM servers WHERE name='SV11 gold test'")->fetchColumn()!==1
    ||(int)$db->query("SELECT COUNT(*) FROM users WHERE email='gold-v3-live@example.invalid'")->fetchColumn()!==1
    ||$db->query('SELECT ip FROM server_game_login WHERE id=11')->fetchColumn()!=='dragon11.teamobi.com')throw new RuntimeException('Unexpected fixture; do not mutate.');
$db->exec("UPDATE bots SET status=0 WHERE id=1 AND type='selling_main'");
echo "Isolated web switch: selling main OFF; stock and orders unchanged.\n";
