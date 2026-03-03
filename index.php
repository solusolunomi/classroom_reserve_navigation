<?php
require __DIR__ . "/db.php";
require __DIR__ . "/auth.php";
require_login();


function h($s)
{
  return htmlspecialchars((string)$s, ENT_QUOTES, "UTF-8");
}

/* =========================================================
   ① floor一覧（プルダウン用）
========================================================= */
$floors = $pdo->query("
  SELECT DISTINCT floor
  FROM classroom
  WHERE floor IS NOT NULL
  ORDER BY floor
")->fetchAll(PDO::FETCH_ASSOC);

$floors = array_map(fn($r) => (string)$r["floor"], $floors);

/* =========================================================
   ② 選択中floor（GET）
========================================================= */
$selectedFloor = $_GET["floor"] ?? "all";
$selectedFloor = (string)$selectedFloor;

/* =========================================================
   ②-1 選択中の日付（予約表示用）
========================================================= */
$selectedDate = $_GET["date"] ?? date("Y-m-d");
if (!preg_match("/^\d{4}-\d{2}-\d{2}$/", $selectedDate)) {
  $selectedDate = date("Y-m-d");
}

/* =========================================================
   ③ 教室一覧（floor絞り込み）
========================================================= */
if ($selectedFloor === "all") {
  $stmt = $pdo->query("
    SELECT classroom_id, classroom_name, floor
    FROM classroom
    ORDER BY floor, classroom_name
  ");
  $rooms = $stmt->fetchAll(PDO::FETCH_ASSOC);
} else {
  $stmt = $pdo->prepare("
    SELECT classroom_id, classroom_name, floor
    FROM classroom
    WHERE floor = ?
    ORDER BY classroom_name
  ");
  $stmt->execute([$selectedFloor]);
  $rooms = $stmt->fetchAll(PDO::FETCH_ASSOC);
}

/* =========================================================
   ④ 表示中の教室＆日付の予約を取得（赤ブロック表示用）
========================================================= */
$reservations = [];
$roomIds = array_map(fn($r) => (int)$r["classroom_id"], $rooms);

if (count($roomIds) > 0) {
  $placeholders = implode(",", array_fill(0, count($roomIds), "?"));

  // users.color がある環境では user_color も返す（個人色表示用）
  $params = array_merge([$selectedDate], $roomIds);
  try {
    $sql = "
	      SELECT
	        r.reservation_id, r.classroom_id, r.user_id, r.reservation_date, r.start_time, r.end_time, r.user_name, r.title,
	        u.color AS user_color
	      FROM reservation r
	      LEFT JOIN users u ON u.user_id = r.user_id
	      WHERE r.reservation_date = ?
	        AND r.classroom_id IN ($placeholders)
	      ORDER BY r.classroom_id, r.start_time
	    ";
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $reservations = $stmt->fetchAll(PDO::FETCH_ASSOC);
  } catch (Throwable $e) {
    // フォールバック（users.color が無い）
    $sql = "
	      SELECT reservation_id, classroom_id, user_id, reservation_date, start_time, end_time, user_name, title
	      FROM reservation
	      WHERE reservation_date = ?
	        AND classroom_id IN ($placeholders)
	      ORDER BY classroom_id, start_time
	    ";
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $reservations = $stmt->fetchAll(PDO::FETCH_ASSOC);
  }
}

$backUrl = "index.php?date=" . urlencode($selectedDate) . "&floor=" . urlencode($selectedFloor);
?>
<!DOCTYPE html>
<html lang="ja">

<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>教室予約システム | ホーム</title>
  <link rel="stylesheet" href="css/style.css">
</head>

<body>

  <?php
  // ヘッダー中央に日付ピッカーを出す（indexのみ）
  $header_show_date = true;
  $header_date_value = $selectedDate;
  $header_floor_value = $selectedFloor;
  include __DIR__ . "/app_header.php";
  ?>

  <?php include __DIR__ . "/drawer.php"; ?>

  <main class="page">
    <h1 class="title">本日の利用状況</h1>

    <!-- schedule自体をGETフォームにする -->
    <form method="get" class="schedule" id="scheduleForm">

      <!-- floor変更しても date が落ちないように -->
      <input type="hidden" name="date" value="<?= h($selectedDate) ?>">


      <div class="time-header">
        <div class="corner corner-filter">
          <label class="filter-label">
            階：
            <select name="floor" class="filter-select" onchange="this.form.submit()">
              <option value="all" <?php if ($selectedFloor === "all") echo "selected"; ?>>すべて</option>
              <?php foreach ($floors as $f): ?>
                <option value="<?= h($f) ?>" <?php if ($selectedFloor === (string)$f) echo "selected"; ?>>
                  <?= h($f) ?>階
                </option>
              <?php endforeach; ?>
            </select>
          </label>
          <noscript><button type="submit">絞り込む</button></noscript>
        </div>

        <div class="timeline" id="timelineHeader"></div>
      </div>

      <div class="rows">
        <?php foreach ($rooms as $room): ?>
          <div class="row">
            <div class="room">
              <?= h($room["classroom_name"]) ?>
            </div>

            <div
              class="lane"
              data-room="<?= h($room["classroom_name"]) ?>"
              data-room-id="<?= h($room["classroom_id"]) ?>"></div>
          </div>
        <?php endforeach; ?>
      </div>
    </form>


    <!-- Reserve Bar（formネスト回避で schedule の外） -->
    <div class="reserve-bar" id="reserveBar" aria-hidden="true">
      <div class="reserve-bar__inner">
        <div class="reserve-bar__left">
          <div class="reserve-bar__summary" id="reserveSummary">0件</div>

          <button
            type="button"
            class="reserve-toggle"
            id="reserveToggleBtn"
            aria-expanded="false">
            詳細
          </button>
        </div>

        <div class="reserve-bar__actions">
          <button type="button" class="clear-btn" id="clearSelectionBtn">選択をクリア</button>

          <form action="reserve.php" method="post" id="reserveForm" class="reserve-form">
            <input type="hidden" name="selections_json" id="selectionsJson" value="">
            <input type="hidden" name="floor" value="<?= h($selectedFloor) ?>">
            <input type="hidden" name="date" value="<?= h($selectedDate) ?>">
            <input type="hidden" name="back" value="<?= h($backUrl) ?>">
            <button type="submit" class="reserve-btn" id="reserveBtn" disabled>予約する</button>
          </form>
        </div>
      </div>

      <div class="reserve-panel" id="reservePanel" hidden>
        <div class="reserve-list" id="reserveList"></div>
      </div>
    </div>
    <!-- 予約詳細モーダル -->
    <div class="modal" id="reservationModal" hidden>
      <div class="modal__backdrop" data-modal-close></div>

      <div class="modal__panel" role="dialog" aria-modal="true" aria-labelledby="modalTitle">
        <div class="modal__header">
          <div class="modal__title" id="modalTitle">予約詳細</div>
          <button type="button" class="modal__close" aria-label="閉じる" data-modal-close>×</button>
        </div>

        <div class="modal__body">
          <div class="modal__line">
            <span class="modal__label">教室</span>
            <span class="modal__value" id="modalRoom">-</span>
          </div>

          <div class="modal__line">
            <span class="modal__label">日付</span>
            <span class="modal__value" id="modalDate">-</span>
          </div>

          <div class="modal__line">
            <span class="modal__label">時間</span>
            <span class="modal__value" id="modalTime">-</span>
          </div>

          <div class="modal__line">
            <span class="modal__label">用途</span>
            <span class="modal__value" id="modalTitleText">-</span>
          </div>

          <div class="modal__line">
            <span class="modal__label">予約者</span>
            <span class="modal__value" id="modalUser">-</span>
          </div>

          <div class="modal__hint" id="modalHint" hidden>
            この予約は編集できません（他のユーザーの予約です）
          </div>
        </div>

        <div class="modal__footer">
          <a href="#" class="reserve-btn" id="modalEditBtn" style="text-decoration:none;" hidden>編集へ</a>
        </div>
      </div>
    </div>

  </main>

  <footer class="app-footer">2026補習© 教室管理ナビゲーション-教ナビ</footer>

  <script>
    window.__SELECTED_DATE__ = <?= json_encode($selectedDate, JSON_UNESCAPED_UNICODE) ?>;
    window.__DB_RESERVATIONS__ = <?= json_encode($reservations, JSON_UNESCAPED_UNICODE) ?>;
    window.__CURRENT_USER_ID__ = <?= json_encode(current_user_id(), JSON_UNESCAPED_UNICODE) ?>;

    // ★追加：管理者フラグ
    window.__IS_ADMIN__ = <?= json_encode(is_admin(), JSON_UNESCAPED_UNICODE) ?>;
  </script>



  <script src="js/script.js"></script>
  <?php
  // ドロワーで使う戻り先（ログイン/切替用）
  $__back = safe_back_url($_SERVER["REQUEST_URI"] ?? "index.php", "index.php");
  ?>
</body>

</html>