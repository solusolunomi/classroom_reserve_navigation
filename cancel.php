<?php
require __DIR__ . "/db.php";
require __DIR__ . "/auth.php";
require_login();

if ($_SERVER["REQUEST_METHOD"] !== "POST") {
  http_response_code(405);
  exit("Method Not Allowed");
}

$back = $_POST["back"] ?? "index.php";

// fetch等で JSON を期待してるか判定
$accept = $_SERVER["HTTP_ACCEPT"] ?? "";
$isJson = (stripos($accept, "application/json") !== false);

// =========================
// 単体 or 複数対応
//  - reservation_id: 単体
//  - reservation_ids[]: 複数
// =========================
$reservationIds = [];

if (isset($_POST["reservation_ids"]) && is_array($_POST["reservation_ids"])) {
  foreach ($_POST["reservation_ids"] as $v) {
    $v = (string)$v;
    if (preg_match("/^\d+$/", $v)) {
      $reservationIds[] = (int)$v;
    }
  }
} else {
  $single = $_POST["reservation_id"] ?? "";
  if (preg_match("/^\d+$/", (string)$single)) {
    $reservationIds[] = (int)$single;
  }
}

$reservationIds = array_values(array_unique($reservationIds));

if (count($reservationIds) === 0) {
  if ($isJson) {
    header("Content-Type: application/json; charset=UTF-8");
    echo json_encode(["ok" => false, "message" => "reservation_id が不正です"], JSON_UNESCAPED_UNICODE);
    exit;
  }
  http_response_code(400);
  exit("reservation_id が不正です");
}

// 上限（事故防止）
if (count($reservationIds) > 300) {
  if ($isJson) {
    header("Content-Type: application/json; charset=UTF-8");
    echo json_encode(["ok" => false, "message" => "一度に削除できる件数が多すぎます"], JSON_UNESCAPED_UNICODE);
    exit;
  }
  http_response_code(400);
  exit("一度に削除できる件数が多すぎます");
}

// 権限チェック（管理者は全削除OK）
$isAdmin = is_admin();

try {
  // 対象の user_id を取得
  $placeholders = implode(",", array_fill(0, count($reservationIds), "?"));
  $check = $pdo->prepare("SELECT reservation_id, user_id FROM reservation WHERE reservation_id IN ($placeholders)");
  $check->execute($reservationIds);
  $rows = $check->fetchAll(PDO::FETCH_ASSOC);

  $foundMap = [];
  foreach ($rows as $r) {
    $foundMap[(int)$r["reservation_id"]] = $r["user_id"];
  }

  // 存在しないIDも含まれていた場合はスキップ扱いにする（エラーにしない）
  $allowedIds = [];
  foreach ($reservationIds as $rid) {
    if (!isset($foundMap[$rid])) continue;

    if (!$isAdmin) {
      // 所有者チェック
      require_owner_or_403($foundMap[$rid] ?? null);
    }
    $allowedIds[] = $rid;
  }

  if (count($allowedIds) === 0) {
    if ($isJson) {
      header("Content-Type: application/json; charset=UTF-8");
      echo json_encode(["ok" => true, "deleted" => 0], JSON_UNESCAPED_UNICODE);
      exit;
    }
    header("Location: " . $back);
    exit;
  }

  // 削除
  $pdo->beginTransaction();
  $ph = implode(",", array_fill(0, count($allowedIds), "?"));
  $del = $pdo->prepare("DELETE FROM reservation WHERE reservation_id IN ($ph)");
  $del->execute($allowedIds);
  $deleted = $del->rowCount();
  $pdo->commit();

  if ($isJson) {
    header("Content-Type: application/json; charset=UTF-8");
    echo json_encode(["ok" => true, "deleted" => $deleted], JSON_UNESCAPED_UNICODE);
    exit;
  }

  // 画面遷移（件数だけ付与しておく）
  $sep = (strpos($back, "?") === false) ? "?" : "&";
  header("Location: " . $back . $sep . "deleted=" . urlencode((string)$deleted));
  exit;

} catch (Throwable $e) {
  if ($pdo->inTransaction()) $pdo->rollBack();

  if ($isJson) {
    header("Content-Type: application/json; charset=UTF-8");
    echo json_encode(["ok" => false, "message" => "削除に失敗しました"], JSON_UNESCAPED_UNICODE);
    exit;
  }

  http_response_code(500);
  exit("削除に失敗しました");
}
