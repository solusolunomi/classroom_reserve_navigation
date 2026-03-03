<?php
require __DIR__ . "/db.php";
require __DIR__ . "/auth.php";
require_login();


/* =========================================
  共通：HTMLエスケープ
========================================= */
function h($s)
{
  return htmlspecialchars((string)$s, ENT_QUOTES, "UTF-8");
}

/* =========================================
  分（8:00基準のオフセット）→ "HH:MM:SS"
========================================= */
function minutesToTimeSql(int $minutes, int $startHour = 8): string
{
  $total = $minutes + $startHour * 60;
  $hour = intdiv($total, 60);
  $min  = $total % 60;
  return sprintf("%02d:%02d:00", $hour, $min);
}

/* =========================================
  POST受け取り
========================================= */
$userName = trim($_POST["user_name"] ?? "");
$purpose  = trim($_POST["purpose"] ?? "");
$selectionsJson = $_POST["selections"] ?? "";

$title = $purpose;

$errors = [];
$items = [];

if ($userName === "") $errors[] = "予約者の名前が未入力です。";
if ($purpose === "")  $errors[] = "使用用途が未入力です。";
if ($selectionsJson === "") $errors[] = "予約データが送信されていません。";

/* selections(JSON)を復元 */
if (empty($errors)) {
  $decoded = json_decode($selectionsJson, true);
  if (!is_array($decoded) || !isset($decoded["items"]) || !is_array($decoded["items"])) {
    $errors[] = "予約データの形式が不正です。";
  } else {
    $items = $decoded["items"];
  }
}

/* itemsを整形 */
$normalized = [];

if (empty($errors)) {
  foreach ($items as $it) {
    $roomId = $it["room_id"] ?? $it["roomId"] ?? "";
    $roomId = (string)$roomId;

    $roomName = (string)($it["room_name"] ?? $it["roomName"] ?? "");
    $date = (string)($it["date"] ?? "");
    $from = $it["from"] ?? null;
    $to   = $it["to"] ?? null;

    if ($roomId === "" || $date === "" || !preg_match("/^\d{4}-\d{2}-\d{2}$/", $date) || !is_numeric($from) || !is_numeric($to)) {
      continue;
    }

    $from = (int)$from;
    $to   = (int)$to;

    if ($to < $from) {
      $tmp = $from;
      $from = $to;
      $to = $tmp;
    }
    if ($to === $from) continue;

    // v1.3: 営業時間（7:00〜19:00）& 5分刻みの安全対策
    $MAX_REL = (19 - 7) * 60;
    $from = (int)round($from / 5) * 5;
    $to   = (int)round($to   / 5) * 5;
    if ($from < 0) $from = 0;
    if ($to > $MAX_REL) $to = $MAX_REL;
    if ($from >= $to) continue;

    $normalized[] = [
      "room_id"   => $roomId,
      "room_name" => $roomName,
      "date"      => $date,
      "from"      => $from,
      "to"        => $to,
      "start_time_sql" => minutesToTimeSql($from, 7),
      "end_time_sql"   => minutesToTimeSql($to, 7),
    ];
  }

  if (empty($normalized)) {
    $errors[] = "有効な予約データがありません。";
  }
}

/* =========================================
  繰り返し（毎週）予約
  - reserve.php から repeat_weekly / repeat_until を受け取る
  - まずは A案：reservation に都度INSERT（表示展開はしない）
========================================= */
$repeatWeekly = !empty($_POST["repeat_weekly"]);
$repeatUntil  = trim((string)($_POST["repeat_until"] ?? ""));
if ($repeatWeekly) {
  if (!preg_match("/^\d{4}-\d{2}-\d{2}$/", $repeatUntil)) {
    $repeatWeekly = false;
    $repeatUntil  = "";
  }
}

if ($repeatWeekly && $repeatUntil !== "" && empty($errors)) {
  try {
    $until = new DateTime($repeatUntil);
    $expanded = [];
    foreach ($normalized as $r) {
      $expanded[] = $r;
      $d = new DateTime($r["date"]);
      while (true) {
        $d->modify("+7 day");
        if ($d > $until) break;
        $nr = $r;
        $nr["date"] = $d->format("Y-m-d");
        $expanded[] = $nr;
      }
    }
    $normalized = $expanded;
  } catch (Throwable $e) {
    // 不正な日付等は繰り返し無効にする
    $repeatWeekly = false;
    $repeatUntil = "";
  }
}

/* =========================================
  DB登録（複数件）
========================================= */
$insertedCount = 0;
$overlaps = [];

if (empty($errors)) {
  try {
    // repeat_group_id があるか（PostgreSQL / Supabase 対応）
    $hasRepeatGroup = false;
    try {
      $stmt = $pdo->prepare("
        SELECT 1
        FROM information_schema.columns
        WHERE table_schema = 'public'
          AND table_name = 'reservation'
          AND column_name = 'repeat_group_id'
        LIMIT 1
      ");
      $stmt->execute();
      $hasRepeatGroup = (bool)$stmt->fetchColumn();
    } catch (Throwable $e) {
      $hasRepeatGroup = false;
    }

    // ★ここでトランザクション開始（ここより前でSQLエラーを起こさない）
    $pdo->beginTransaction();

    // 同じ定期予約を束ねるID（列が無い環境ではNULL）
    $repeatGroupId = null;
    if ($repeatWeekly && $hasRepeatGroup) {
      $repeatGroupId = (int)(time() . str_pad((string)random_int(0, 9999), 4, '0', STR_PAD_LEFT));
    }

    $checkStmt = $pdo->prepare("
      SELECT reservation_id
      FROM reservation
      WHERE classroom_id = ?
        AND reservation_date = ?
        AND start_time < ?
        AND end_time   > ?
      LIMIT 1
    ");

    if ($hasRepeatGroup) {
      $insertStmt = $pdo->prepare("
        INSERT INTO reservation (title, user_name, user_id, classroom_id, reservation_date, start_time, end_time, repeat_group_id)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?)
      ");
    } else {
      $insertStmt = $pdo->prepare("
        INSERT INTO reservation (title, user_name, user_id, classroom_id, reservation_date, start_time, end_time)
        VALUES (?, ?, ?, ?, ?, ?, ?)
      ");
    }

    // 選択内（繰り返し展開後も含む）での重複チェック用
    $accepted = [];

    foreach ($normalized as $r) {
      $classroomId = (int)$r["room_id"];
      $reservationDate = $r["date"];
      $startTime = $r["start_time_sql"];
      $endTime   = $r["end_time_sql"];

      // ①選択内の重複（同じ教室・同じ日で時間が重なる）
      $key = $classroomId . '|' . $reservationDate;
      if (!isset($accepted[$key])) $accepted[$key] = [];
      $conflictInBatch = false;
      foreach ($accepted[$key] as $a) {
        if (!($a['end'] <= $startTime || $a['start'] >= $endTime)) {
          $conflictInBatch = true;
          break;
        }
      }
      if ($conflictInBatch) {
        $r['note'] = '選択内で時間が重複';
        $overlaps[] = $r;
        continue;
      }

      $checkStmt->execute([$classroomId, $reservationDate, $endTime, $startTime]);
      $hit = $checkStmt->fetch();

      if ($hit) {
        $r['note'] = '既存予約と重複';
        $overlaps[] = $r;
        continue;
      }

      $params = [
        $title,
        $userName,
        current_user_id(),
        $classroomId,
        $reservationDate,
        $startTime,
        $endTime,
      ];
      if ($hasRepeatGroup) {
        $params[] = $repeatGroupId;
      }

      $insertStmt->execute($params);

      $insertedCount++;

      $accepted[$key][] = ['start' => $startTime, 'end' => $endTime];
    }

    $pdo->commit();
  } catch (Throwable $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    $errors[] = "DB登録でエラーが発生しました。： " . $e->getMessage();
  }
}

/* =========================================
  戻り先URL（緑の選択を消す cleared=1 を付ける）
========================================================= */
$backDate  = $normalized[0]["date"] ?? date("Y-m-d");
$backFloor = (string)($_POST["floor"] ?? ($_GET["floor"] ?? "all"));

$homeUrl = "index.php?date=" . urlencode($backDate)
  . "&floor=" . urlencode($backFloor)
  . "&cleared=1";
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
    <h1 class="title">予約結果</h1>

    <section class="card">
      <h2 class="card-title">結果</h2>

      <?php if (!empty($errors)): ?>
        <ul class="card-text">
          <?php foreach ($errors as $msg): ?>
            <li><?= h($msg) ?></li>
          <?php endforeach; ?>
        </ul>

        <div class="actions">
          <a class="clear-btn" href="<?= h($homeUrl) ?>" style="text-decoration:none; display:inline-flex; align-items:center;">
            戻る
          </a>
        </div>

      <?php else: ?>
        <p class="card-text">
          登録できた予約： <strong><?= h($insertedCount) ?></strong> 件
        </p>

        <?php if (!empty($overlaps)): ?>
          <p class="card-text" style="margin-top:10px;">
            ※重なりがあったため登録しなかった予約（<?= h(count($overlaps)) ?>件）：
          </p>

          <div class="reserve-list-view">
            <?php foreach ($overlaps as $v): ?>
              <div class="reserve-row">
                <span class="badge"><?= h($v["date"]) ?></span>
                <span class="badge"><?= h($v["room_name"]) ?></span>
                <?php if (!empty($v['note'])): ?>
                  <span class="badge" style="border-color:#fecaca; color:#b91c1c; background:#fff1f2; font-weight:900;">
                    <?= h((string)$v['note']) ?>
                  </span>
                <?php endif; ?>
                <span class="reserve-main"><?= h(substr((string)$v["start_time_sql"], 0, 5)) ?> 〜 <?= h(substr((string)$v["end_time_sql"], 0, 5)) ?></span>
              </div>
            <?php endforeach; ?>
          </div>
        <?php endif; ?>

        <div class="actions" style="margin-top:14px;">
          <a class="clear-btn" href="<?= h($homeUrl) ?>" style="text-decoration:none; display:inline-flex; align-items:center;">
            ホームへ戻る
          </a>
        </div>
      <?php endif; ?>
    </section>
  </main>

  <footer class="app-footer">2026補習© 教室管理ナビゲーション-教ナビ</footer>
</body>

</html>