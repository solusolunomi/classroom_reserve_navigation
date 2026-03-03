<?php
require __DIR__ . "/db.php";
require __DIR__ . "/auth.php";
require_admin();

// 最新200件をエクスポート（管理画面の一覧と揃える）
$rows = $pdo->query("
  SELECT
    r.reservation_date,
    c.classroom_name,
    r.start_time,
    r.end_time,
    r.user_name,
    r.title,
    u.username
  FROM reservation r
  LEFT JOIN classroom c ON c.classroom_id = r.classroom_id
  LEFT JOIN users u ON u.user_id = r.user_id
  ORDER BY r.reservation_date DESC, r.start_time DESC
  LIMIT 200
")->fetchAll(PDO::FETCH_ASSOC);

header('Content-Type: text/csv; charset=UTF-8');
header('Content-Disposition: attachment; filename="reservations_export.csv"');

$out = fopen('php://output', 'w');
// Excel向けBOM（不要なら消してOK）
fwrite($out, "\xEF\xBB\xBF");

fputcsv($out, [
  'reservation_date',
  'classroom_name',
  'start_time',
  'end_time',
  'user_name',
  'title',
  'username'
]);

foreach ($rows as $r) {
  $start = (string)($r['start_time'] ?? '');
  $end   = (string)($r['end_time'] ?? '');
  // HH:MM:SS → HH:MM
  if (strlen($start) >= 5) $start = substr($start, 0, 5);
  if (strlen($end) >= 5) $end = substr($end, 0, 5);
  fputcsv($out, [
    (string)($r['reservation_date'] ?? ''),
    (string)($r['classroom_name'] ?? ''),
    $start,
    $end,
    (string)($r['user_name'] ?? ''),
    (string)($r['title'] ?? ''),
    (string)($r['username'] ?? ''),
  ]);
}

fclose($out);
exit;
