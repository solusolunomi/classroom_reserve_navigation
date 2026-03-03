<?php
require __DIR__ . "/db.php";
require __DIR__ . "/auth.php";
require_login();


function h($s)
{
  return htmlspecialchars((string)$s, ENT_QUOTES, "UTF-8");
}

$reservationId = $_GET["reservation_id"] ?? "";
if (!preg_match("/^\d+$/", (string)$reservationId)) {
  http_response_code(400);
  exit("reservation_id が不正です");
}

$back = $_GET["back"] ?? "index.php";

$stmt = $pdo->prepare("
  SELECT r.reservation_id, r.classroom_id, r.reservation_date, r.start_time, r.end_time, r.user_name, r.user_id, r.title,
         c.classroom_name, c.floor
  FROM reservation r
  LEFT JOIN classroom c ON c.classroom_id = r.classroom_id
  WHERE r.reservation_id = ?
  LIMIT 1
");
$stmt->execute([(int)$reservationId]);
$res = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$res) {
  http_response_code(404);
  exit("予約が見つかりません");
}

require_owner_or_403($res["user_id"] ?? null);
?>
<!DOCTYPE html>
<html lang="ja">

<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>教室予約システム | 予約編集</title>
  <link rel="stylesheet" href="css/style.css">
</head>

<body>

  <?php include __DIR__ . "/app_header.php"; ?>

  <?php include __DIR__ . "/drawer.php"; ?>

  <main class="page">
    <h1 class="title">予約の編集 / キャンセル</h1>

    <section class="card">
      <h2 class="card-title">予約情報</h2>
      <div class="info-grid">
        <div class="info-item"><span class="info-label">教室：</span><span class="info-value"><?= h($res["classroom_name"] ?? "") ?></span></div>
        <div class="info-item"><span class="info-label">階　：</span><span class="info-value"><?= h($res["floor"] ?? "") ?></span></div>
        <div class="info-item"><span class="info-label">日付：</span><span class="info-value"><?= h($res["reservation_date"]) ?></span></div>
        <div class="info-item"><span class="info-label">時間：</span><span class="info-value"><?= h($res["start_time"]) ?> 〜 <?= h($res["end_time"]) ?></span></div>
      </div>
    </section>

    <section class="card">
      <h2 class="card-title">内容の変更</h2>

      <form action="update.php" method="post" class="form-grid">
        <input type="hidden" name="reservation_id" value="<?= h($res["reservation_id"]) ?>">
        <input type="hidden" name="back" value="<?= h($back) ?>">

        <div class="field">
          <label for="user_name">予約者の名前</label>
          <input type="text" id="user_name" name="user_name" class="form-input"
            value="<?= h($res["user_name"] ?? "") ?>" placeholder="例）横浜 太郎" required>
        </div>

        <div class="field">
          <label for="title">利用用途</label>
          <input type="text" id="title" name="title" class="form-input"
            value="<?= h($res["title"] ?? "") ?>" placeholder="例）会議、勉強会" required>
        </div>

        <div class="actions">
          <a class="clear-btn" href="<?= h($back) ?>" style="text-decoration:none; display:inline-flex; align-items:center; margin-right:96px;">戻る</a>
          <button type="submit" class="reserve-btn">変更を保存</button>
        </div>
      </form>
    </section>

    <section class="card danger">
      <h2 class="card-title">予約のキャンセル</h2>
      <p class="card-text">この予約を削除すると元に戻せません。</p>

      <form action="cancel.php" method="post" class="form-grid" style="margin-top:10px;">
        <input type="hidden" name="reservation_id" value="<?= h($res["reservation_id"]) ?>">
        <input type="hidden" name="back" value="<?= h($back) ?>">
        <button type="submit" class="danger-btn">予約をキャンセル</button>
      </form>
    </section>
  </main>

  <footer class="app-footer">2026補習© 教室管理ナビゲーション-教ナビ</footer>
</body>

</html>