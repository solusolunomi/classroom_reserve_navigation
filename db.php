<?php
// db.php (PostgreSQL / Supabase)
//
// 推奨：環境変数 DATABASE_URL に Supabase の接続URLを設定して使う
// 例）postgresql://USER:PASSWORD@HOST:6543/postgres?sslmode=require
//
// 互換：DATABASE_URL が無い場合は個別の環境変数でもOK
//   PGHOST, PGPORT, PGDATABASE, PGUSER, PGPASSWORD, PGSSLMODE

if (!function_exists('getenv')) {
  exit('DB接続エラー: getenv が利用できません。');
}

$databaseUrl = getenv('DATABASE_URL');

$host = '';
$port = 5432;
$dbname = 'postgres';
$user = '';
$pass = '';
$sslmode = 'require';

if ($databaseUrl) {
  $parts = parse_url($databaseUrl);
  if ($parts === false) {
    exit("DB接続エラー: DATABASE_URL の形式が不正です。");
  }

  $host = $parts['host'] ?? '';
  $port = (int)($parts['port'] ?? 5432);
  $user = rawurldecode($parts['user'] ?? '');
  $pass = rawurldecode($parts['pass'] ?? '');
  $dbname = isset($parts['path']) ? ltrim($parts['path'], '/') : 'postgres';

  parse_str($parts['query'] ?? '', $query);
  if (!empty($query['sslmode'])) {
    $sslmode = $query['sslmode'];
  }
} else {
  // Individual env vars (useful for some hosts / local dev)
  $host = getenv('PGHOST') ?: '';
  $port = (int)(getenv('PGPORT') ?: 5432);
  $dbname = getenv('PGDATABASE') ?: 'postgres';
  $user = getenv('PGUSER') ?: '';
  $pass = getenv('PGPASSWORD') ?: '';
  $sslmode = getenv('PGSSLMODE') ?: 'require';
}

if ($host === '' || $user === '' || $pass === '') {
  exit("DB接続エラー: 接続情報が不足しています（DATABASE_URL か PG* 環境変数を設定してください）。");
}

$dsn = "pgsql:host={$host};port={$port};dbname={$dbname};sslmode={$sslmode}";

try {
  $pdo = new PDO($dsn, $user, $pass, [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    PDO::ATTR_EMULATE_PREPARES => false,
  ]);

  // 日本の運用に合わせる（任意）
  $pdo->exec("SET TIME ZONE 'Asia/Tokyo'");
} catch (PDOException $e) {
  exit("DB接続エラー: " . $e->getMessage());
}
