<?php
require __DIR__ . "/db.php";
require __DIR__ . "/auth.php";
require_login();

function h($s)
{
  return htmlspecialchars((string)$s, ENT_QUOTES, "UTF-8");
}

if ($_SERVER["REQUEST_METHOD"] !== "POST") {
  http_response_code(405);
  exit("Method Not Allowed");
}

$reservationId = $_POST["reservation_id"] ?? "";
$title = trim($_POST["title"] ?? "");
$userName = trim($_POST["user_name"] ?? "");
$back = $_POST["back"] ?? "index.php";

if (!preg_match("/^\d+$/", (string)$reservationId)) {
  http_response_code(400);
  exit("reservation_id が不正です");
}

// ★所有者チェック（そのまま維持）
$check = $pdo->prepare("SELECT user_id FROM reservation WHERE reservation_id = ? LIMIT 1");
$check->execute([(int)$reservationId]);
$row = $check->fetch(PDO::FETCH_ASSOC);
require_owner_or_403($row["user_id"] ?? null);

if ($title === "" || $userName === "") {
  header("Location: " . $back);
  exit;
}

try {
  // Postgresでは UPDATE ... LIMIT が使えないので RETURNING を使う
  $stmt = $pdo->prepare("
    UPDATE reservation
    SET title = ?, user_name = ?
    WHERE reservation_id = ?
    RETURNING reservation_id
  ");
  $stmt->execute([$title, $userName, (int)$reservationId]);

  $updatedId = $stmt->fetchColumn();
  if (!$updatedId) {
    // 対象がなかった場合（念のため）
    http_response_code(404);
    exit("更新対象の予約が見つかりませんでした");
  }
} catch (Throwable $e) {
  // Cloud Runログに出す
  error_log("UPDATE.PHP ERROR: " . $e->getMessage());
  http_response_code(500);
  exit("更新でエラーが発生しました: " . $e->getMessage());
}

header("Location: " . $back);
exit;