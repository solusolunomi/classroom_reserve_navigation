<?php
require __DIR__ . "/db.php";
require __DIR__ . "/auth.php";
require_login();

function h($s)
{
  return htmlspecialchars((string)$s, ENT_QUOTES, "UTF-8");
}

// 自分の表示色（users.color）。列が無い環境でも動くように安全に取得します。
$myColor = "";
$hasUserColorColumn = true;
try {
  // ★PostgreSQL対応：LIMIT削除
  $stmt = $pdo->prepare("SELECT color FROM users WHERE user_id = ?");
  $stmt->execute([current_user_id()]);
  $row = $stmt->fetch(PDO::FETCH_ASSOC);
  $myColor = (string)($row["color"] ?? "");
} catch (Throwable $e) {
  $hasUserColorColumn = false;
  $myColor = "";
}

// POST: 自分の色を更新
if ($_SERVER["REQUEST_METHOD"] === "POST") {
  $action = (string)($_POST["action"] ?? "");
  if ($action === "update_color") {
    if (!$hasUserColorColumn) {
      header("Location: my_reservations.php");
      exit;
    }

    $color = trim((string)($_POST["color"] ?? ""));
    // #RRGGBB のみ許可
    if (!preg_match('/^#[0-9a-fA-F]{6}$/', $color)) {
      header("Location: my_reservations.php");
      exit;
    }

    $uid = current_user_id();
    if ($uid !== null) {
      // ★PostgreSQL対応：LIMIT削除 + RETURNING
      $stmt = $pdo->prepare("
        UPDATE users
        SET color = ?
        WHERE user_id = ?
        RETURNING user_id
      ");
      $stmt->execute([$color, (int)$uid]);
      $stmt->fetchColumn();
    }

    header("Location: my_reservations.php");
    exit;
  }
}

/* =========================================================
   検索条件（GET）
========================================================= */
$from = (string)($_GET["from"] ?? "");
$to   = (string)($_GET["to"] ?? "");

/* デフォルト：今日〜7日後 */
$today = date("Y-m-d");
if (!preg_match("/^\d{4}-\d{2}-\d{2}$/", $from)) $from = $today;

$defaultTo = date("Y-m-d", strtotime($from . " +7 day"));
if (!preg_match("/^\d{4}-\d{2}-\d{2}$/", $to)) $to = $defaultTo;

/* from > to のとき入れ替え */
if ($from > $to) {
  $tmp = $from;
  $from = $to;
  $to = $tmp;
}

$me = current_user_id();
$username = current_username();

/* =========================================================
   自分の予約一覧取得
========================================================= */
$stmt = $pdo->prepare("
  SELECT r.reservation_id, r.classroom_id, r.reservation_date, r.start_time, r.end_time, r.user_name, r.title,
         c.classroom_name, c.floor
  FROM reservation r
  LEFT JOIN classroom c ON c.classroom_id = r.classroom_id
  WHERE r.user_id = ?
    AND r.reservation_date BETWEEN ? AND ?
  ORDER BY r.reservation_date ASC, r.start_time ASC
");
$stmt->execute([(int)$me, $from, $to]);
$list = $stmt->fetchAll(PDO::FETCH_ASSOC);

$back = "my_reservations.php?from=" . urlencode($from) . "&to=" . urlencode($to);
?>
<!DOCTYPE html>
<html lang="ja">

<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>教室予約システム | マイページ</title>
  <link rel="stylesheet" href="css/style.css">
</head>

<body>

  <?php include __DIR__ . "/app_header.php"; ?>

  <main class="page">
    <h1 class="title">自分の予約一覧</h1>

    <section class="card">
      <h2 class="card-title">表示期間</h2>

      <?php if ($hasUserColorColumn): ?>
        <div style="margin-top:10px; padding:10px 12px; border:1px solid #eef2f7; border-radius:12px; background:#fff;">
          <div style="font-weight:900; color:#0f172a; margin-bottom:8px;">自分の表示色</div>
          <form method="post" style="display:flex; gap:12px; align-items:center; flex-wrap:wrap;">
            <input type="hidden" name="action" value="update_color">
            <input type="color" name="color" value="<?= h($myColor !== "" ? $myColor : "#3B82F6") ?>" style="width:56px; height:40px; padding:0; border:none; background:transparent;">
            <div style="font-weight:800; color:#475569;">※この色が自分の予約ブロックに反映されます</div>
            <button type="submit" class="reserve-btn" style="padding:7px 12px;">保存</button>
          </form>
        </div>
      <?php endif; ?>

      <form method="get" class="form-grid" style="margin-top:12px;">
        <div class="field">
          <label for="from">開始日</label>
          <input type="date" id="from" name="from" value="<?= h($from) ?>" required>
        </div>

        <div class="field">
          <label for="to">終了日</label>
          <input type="date" id="to" name="to" value="<?= h($to) ?>" required>
        </div>

        <div class="actions">
          <div style="display:flex; gap:10px; align-items:center; justify-content:flex-end; flex-wrap:wrap; width:100%;">
            <a class="clear-btn" href="bulk_import.php" style="text-decoration:none;">CSV一括予約</a>
            <a class="clear-btn" href="export_reservations.php?scope=my&from=<?= h(urlencode($from)) ?>&to=<?= h(urlencode($to)) ?>" style="text-decoration:none;">CSVエクスポート</a>
            <button type="submit" class="reserve-btn">この期間で表示</button>
          </div>
        </div>
      </form>
    </section>

    <section class="card">
      <h2 class="card-title">予約（<?= h($from) ?> 〜 <?= h($to) ?>）</h2>

      <?php if (empty($list)): ?>
        <p class="card-text">この期間の予約はありません。</p>
      <?php else: ?>
        <div class="reserve-list-view" style="margin-top:12px;">
          <?php foreach ($list as $r): ?>
            <?php
            $editUrl = "edit.php?reservation_id=" . urlencode($r["reservation_id"]) . "&back=" . urlencode($back);
            ?>
            <div class="reserve-row" style="align-items:center;">
              <span class="badge"><?= h($r["reservation_date"]) ?></span>
              <span class="badge"><?= h($r["classroom_name"] ?? ("教室ID:" . $r["classroom_id"])) ?></span>
              <span class="badge"><?= h(($r["floor"] ?? "") !== "" ? ($r["floor"] . "階") : "-") ?></span>

              <?php
              $start = substr((string)$r["start_time"], 0, 5);
              $end   = substr((string)$r["end_time"], 0, 5);
              ?>
              <span class="reserve-main"><?= h($start) ?> 〜 <?= h($end) ?></span>

              <span style="font-weight:900; color:#334155;"><?= h($r["title"] ?? "") ?></span>

              <div style="margin-left:auto; display:flex; gap:10px; flex-wrap:wrap;">
                <a class="reserve-btn" href="<?= h($editUrl) ?>" style="text-decoration:none;">編集</a>

                <form action="cancel.php" method="post" onsubmit="return confirm('この予約をキャンセルしますか？');" style="margin:0;">
                  <input type="hidden" name="reservation_id" value="<?= h($r["reservation_id"]) ?>">
                  <input type="hidden" name="back" value="<?= h($back) ?>">
                  <button type="submit" class="danger-btn">削除</button>
                </form>
              </div>
            </div>
          <?php endforeach; ?>
        </div>
      <?php endif; ?>
    </section>
  </main>

  <footer class="app-footer">2026補習© 教室管理ナビゲーション-教ナビ</footer>

  <?php
  $__back = safe_back_url($_SERVER["REQUEST_URI"] ?? "index.php", "index.php");
  ?>
  <?php include __DIR__ . "/drawer.php"; ?>
</body>

</html>