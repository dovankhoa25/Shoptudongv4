<?php
$root=realpath($argv[1]??'');$base=realpath('D:/code/App dev/NRO_NATIVE_SLEEP_247/qltk/NroTradingV3/gold-live-results');
if(!$root||!$base||!str_starts_with(strtolower($root),strtolower($base.DIRECTORY_SEPARATOR)))throw new RuntimeException('Isolated gold archive required.');
$path=$root.'/test-database-no-credentials.sqlite';if(!is_file($path))throw new RuntimeException('Missing isolated database.');
$db=new PDO('sqlite:'.$path);$db->setAttribute(PDO::ATTR_ERRMODE,PDO::ERRMODE_EXCEPTION);
if((int)$db->query("SELECT COUNT(*) FROM users WHERE email='gold-v3-live@example.invalid'")->fetchColumn()!==1||$db->query('SELECT ip FROM server_game_login WHERE id=11')->fetchColumn()!=='dragon11.teamobi.com')throw new RuntimeException('Unexpected database.');
$db->exec('PRAGMA secure_delete=ON');$db->exec("UPDATE bots SET account_name='gold-test-redacted',account_password=''");$db->exec('VACUUM');
echo "Gold archive sanitized and compacted; financial rows retained.\n";
