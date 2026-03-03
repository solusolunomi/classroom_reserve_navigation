<?php
require __DIR__ . "/db.php";
require_once __DIR__ . "/auth.php";

/*
  auth.php 側に require_admin() が無い/名前が違う環境でも
  admin.php 単体で確実に管理者チェックできるようにする（機能は増やすだけ、消さない）
*/
if (!function_exists("require_admin")) {
  function require_admin(): void
  {
    require_login();
    if (!is_admin()) {
      http_response_code(403);
      exit("権限がありません");
    }
  }
}

require_admin();

function h($s)
{
  return htmlspecialchars((string)$s, ENT_QUOTES, "UTF-8");
}

$tab = $_GET["tab"] ?? "reservations";
$tab = in_array($tab, ["reservations", "classrooms", "users"], true) ? $tab : "reservations";

/*
  画面に通知を出したくないなら、$flash は使わない（表示もしない）
  ただし今のままだと ?msg=... が来るので、変数は残しておく（機能は消さない）
*/
$flash = "";

/* =========================================================
   POST処理（同一ページで簡易管理）
========================================================= */
if ($_SERVER["REQUEST_METHOD"] === "POST") {
  $action = $_POST["action"] ?? "";

  // 予約：CSV一括登録（管理者）
  if ($action === "reservation_import_csv") {
    // 結果はセッションに積んでGET側で表示（ページ上部の通知は出さない）
    $_SESSION["admin_import_report"] = [
      "ok" => 0,
      "ng" => 0,
      "errors" => [],
    ];

    if (!isset($_FILES["csv_file"]) || !is_uploaded_file($_FILES["csv_file"]["tmp_name"])) {
      $_SESSION["admin_import_report"]["errors"][] = "CSVファイルが選択されていません";
      $_SESSION["admin_import_report"]["ng"]++;
      header("Location: admin.php?tab=reservations");
      exit;
    }

    $tmp = (string)$_FILES["csv_file"]["tmp_name"];
    $fp = fopen($tmp, "r");
    if ($fp === false) {
      $_SESSION["admin_import_report"]["errors"][] = "CSVファイルを開けません";
      $_SESSION["admin_import_report"]["ng"]++;
      header("Location: admin.php?tab=reservations");
      exit;
    }

    // ExcelのCSVはSJISが多いので、最初の1行で判定してUTF-8に寄せる
    $firstRaw = fgets($fp);
    if ($firstRaw === false) {
      fclose($fp);
      $_SESSION["admin_import_report"]["errors"][] = "CSVが空です";
      $_SESSION["admin_import_report"]["ng"]++;
      header("Location: admin.php?tab=reservations");
      exit;
    }
    $enc = mb_detect_encoding($firstRaw, ["UTF-8", "SJIS-win", "CP932", "EUC-JP"], true);
    if ($enc === false) $enc = "UTF-8";
    $firstLine = ($enc === "UTF-8") ? $firstRaw : mb_convert_encoding($firstRaw, "UTF-8", $enc);

    $lines = [];
    $lines[] = $firstLine;
    while (($raw = fgets($fp)) !== false) {
      $lines[] = ($enc === "UTF-8") ? $raw : mb_convert_encoding($raw, "UTF-8", $enc);
    }
    fclose($fp);

    // 教室名→ID
    $roomMap = [];
    $rooms = $pdo->query("SELECT classroom_id, classroom_name FROM classroom")->fetchAll(PDO::FETCH_ASSOC);
    foreach ($rooms as $rr) {
      $roomMap[(string)$rr["classroom_name"]] = (int)$rr["classroom_id"];
    }

    // username→user_id
    $userMap = [];
    $uu = $pdo->query("SELECT user_id, username FROM users")->fetchAll(PDO::FETCH_ASSOC);
    foreach ($uu as $row) {
      $userMap[(string)$row["username"]] = (int)$row["user_id"];
    }

    // repeat_group_id があるか（将来拡張用） ※PostgreSQL対応
    $hasRepeatGroup = false;
    try {
      $st = $pdo->prepare("
        SELECT 1
        FROM information_schema.columns
        WHERE table_schema = 'public'
          AND table_name = 'reservation'
          AND column_name = 'repeat_group_id'
        LIMIT 1
      ");
      $st->execute();
      $hasRepeatGroup = (bool)$st->fetchColumn();
    } catch (Throwable $e) {
      $hasRepeatGroup = false;
    }

    $isHeader = true;
    $lineNo = 0;

    $pdo->beginTransaction();
    try {
      foreach ($lines as $line) {
        $lineNo++;
        $line = trim((string)$line);
        if ($line === "") continue;

        $cols = str_getcsv($line);

        // 想定フォーマット：
        // reservation_date, classroom_name(or classroom_id), start_time, end_time, user_name, title, (optional) username

        // ヘッダー行っぽい場合はスキップ
        if ($isHeader) {
          $isHeader = false;
          $head = implode(",", array_map("strtolower", $cols));
          if (strpos($head, "reservation_date") !== false || strpos($head, "date") !== false) {
            continue;
          }
        }

        $date = trim((string)($cols[0] ?? ""));
        $roomKey = trim((string)($cols[1] ?? ""));
        $start = trim((string)($cols[2] ?? ""));
        $end = trim((string)($cols[3] ?? ""));
        $userName = trim((string)($cols[4] ?? ""));
        $title = trim((string)($cols[5] ?? ""));
        $username = trim((string)($cols[6] ?? ""));

        if ($date === "" || $roomKey === "" || $start === "" || $end === "") {
          $_SESSION["admin_import_report"]["ng"]++;
          $_SESSION["admin_import_report"]["errors"][] = "{$lineNo}行目: 必須項目が不足しています";
          continue;
        }
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
          $_SESSION["admin_import_report"]["ng"]++;
          $_SESSION["admin_import_report"]["errors"][] = "{$lineNo}行目: 日付形式が不正です（YYYY-MM-DD）";
          continue;
        }
        if (!preg_match('/^\d{2}:\d{2}$/', $start) || !preg_match('/^\d{2}:\d{2}$/', $end)) {
          $_SESSION["admin_import_report"]["ng"]++;
          $_SESSION["admin_import_report"]["errors"][] = "{$lineNo}行目: 時刻形式が不正です（HH:MM）";
          continue;
        }
        if ($start >= $end) {
          $_SESSION["admin_import_report"]["ng"]++;
          $_SESSION["admin_import_report"]["errors"][] = "{$lineNo}行目: 開始時刻が終了時刻以上です";
          continue;
        }

        // 教室ID
        $classroomId = null;
        if (preg_match('/^\d+$/', $roomKey)) {
          $classroomId = (int)$roomKey;
        } else {
          $classroomId = $roomMap[$roomKey] ?? null;
        }
        if ($classroomId === null) {
          $_SESSION["admin_import_report"]["ng"]++;
          $_SESSION["admin_import_report"]["errors"][] = "{$lineNo}行目: 教室が見つかりません（{$roomKey}）";
          continue;
        }

        // user_id
        $uid = null;
        if ($username !== "") {
          $uid = $userMap[$username] ?? null;
          if ($uid === null) {
            $_SESSION["admin_import_report"]["ng"]++;
            $_SESSION["admin_import_report"]["errors"][] = "{$lineNo}行目: ユーザーが見つかりません（{$username}）";
            continue;
          }
        } else {
          $uid = current_user_id();
        }

        // 既存予約との重複チェック
        $chk = $pdo->prepare("
          SELECT COUNT(*) AS c
          FROM reservation
          WHERE classroom_id = ?
            AND reservation_date = ?
            AND NOT (end_time <= ? OR start_time >= ?)
        ");
        $chk->execute([$classroomId, $date, $start . ":00", $end . ":00"]);
        $c = (int)($chk->fetch(PDO::FETCH_ASSOC)["c"] ?? 0);
        if ($c > 0) {
          $_SESSION["admin_import_report"]["ng"]++;
          $_SESSION["admin_import_report"]["errors"][] = "{$lineNo}行目: 既存予約と重複（{$date} {$roomKey} {$start}-{$end}）";
          continue;
        }

        if ($hasRepeatGroup) {
          $ins = $pdo->prepare("
            INSERT INTO reservation (classroom_id, user_id, reservation_date, start_time, end_time, user_name, title, repeat_group_id)
            VALUES (?, ?, ?, ?, ?, ?, ?, NULL)
          ");
          $ins->execute([$classroomId, $uid, $date, $start . ":00", $end . ":00", $userName, $title]);
        } else {
          $ins = $pdo->prepare("
            INSERT INTO reservation (classroom_id, user_id, reservation_date, start_time, end_time, user_name, title)
            VALUES (?, ?, ?, ?, ?, ?, ?)
          ");
          $ins->execute([$classroomId, $uid, $date, $start . ":00", $end . ":00", $userName, $title]);
        }

        $_SESSION["admin_import_report"]["ok"]++;
      }

      $pdo->commit();
    } catch (Throwable $e) {
      $pdo->rollBack();
      $_SESSION["admin_import_report"]["errors"][] = "インポート中にエラーが発生しました：" . $e->getMessage();
    }

    header("Location: admin.php?tab=reservations");
    exit;
  }

  // 教室：追加
  if ($action === "classroom_add") {
    $name = trim($_POST["classroom_name"] ?? "");
    $floor = trim($_POST["floor"] ?? "");

    if ($name !== "" && $floor !== "") {
      $stmt = $pdo->prepare("INSERT INTO classroom (classroom_name, floor) VALUES (?, ?)");
      $stmt->execute([$name, $floor]);
      header("Location: admin.php?tab=classrooms"); // 通知は出さない
      exit;
    }
    header("Location: admin.php?tab=classrooms");
    exit;
  }

  // 教室：更新（PostgreSQL対応：LIMIT削除 + RETURNING）
  if ($action === "classroom_update") {
    $id = $_POST["classroom_id"] ?? "";
    $name = trim($_POST["classroom_name"] ?? "");
    $floor = trim($_POST["floor"] ?? "");
    if (preg_match("/^\d+$/", (string)$id) && $name !== "" && $floor !== "") {
      $stmt = $pdo->prepare("
        UPDATE classroom
        SET classroom_name = ?, floor = ?
        WHERE classroom_id = ?
        RETURNING classroom_id
      ");
      $stmt->execute([$name, $floor, (int)$id]);
      $updated = $stmt->fetchColumn();
      if (!$updated) {
        // 対象が無い場合でもUIは変えない（通知も出さない）
      }
      header("Location: admin.php?tab=classrooms"); // 通知は出さない
      exit;
    }
    header("Location: admin.php?tab=classrooms");
    exit;
  }

  // 教室：削除（PostgreSQL対応：LIMIT削除 + RETURNING）
  if ($action === "classroom_delete") {
    $id = $_POST["classroom_id"] ?? "";
    if (preg_match("/^\d+$/", (string)$id)) {
      // 予約がある教室は削除しない（事故防止）
      $chk = $pdo->prepare("SELECT COUNT(*) AS c FROM reservation WHERE classroom_id = ?");
      $chk->execute([(int)$id]);
      $c = (int)($chk->fetch(PDO::FETCH_ASSOC)["c"] ?? 0);
      if ($c > 0) {
        header("Location: admin.php?tab=classrooms"); // 通知は出さない
        exit;
      }

      $stmt = $pdo->prepare("
        DELETE FROM classroom
        WHERE classroom_id = ?
        RETURNING classroom_id
      ");
      $stmt->execute([(int)$id]);
      $deleted = $stmt->fetchColumn();
      if (!$deleted) {
        // 対象が無い場合でもUIは変えない（通知も出さない）
      }

      header("Location: admin.php?tab=classrooms"); // 通知は出さない
      exit;
    }
    header("Location: admin.php?tab=classrooms");
    exit;
  }

  // ユーザ：管理者切り替え（PostgreSQL対応：LIMIT削除 + RETURNING）
  if ($action === "user_toggle_admin") {
    $id = $_POST["user_id"] ?? "";
    if (preg_match("/^\d+$/", (string)$id)) {
      $stmt = $pdo->prepare("
        UPDATE users
        SET is_admin = 1 - is_admin
        WHERE user_id = ?
        RETURNING user_id
      ");
      $stmt->execute([(int)$id]);
      $updated = $stmt->fetchColumn();
      if (!$updated) {
        // 対象が無い場合でもUIは変えない
      }
      header("Location: admin.php?tab=users"); // 通知は出さない
      exit;
    }
    header("Location: admin.php?tab=users");
    exit;
  }

  // ユーザ：停止/解除（is_user 1=有効 / 0=停止）（PostgreSQL対応）
  if ($action === "user_toggle_active") {
    $id = $_POST["user_id"] ?? "";
    if (preg_match("/^\d+$/", (string)$id)) {
      $targetId = (int)$id;
      $me = current_user_id();

      // ★自分自身は停止できない
      if ($me !== null && $targetId === (int)$me) {
        header("Location: admin.php?tab=users"); // 通知は出さない
        exit;
      }

      // 0/1 を反転
      $stmt = $pdo->prepare("
        UPDATE users
        SET is_user = 1 - is_user
        WHERE user_id = ?
        RETURNING user_id
      ");
      $stmt->execute([$targetId]);
      $updated = $stmt->fetchColumn();
      if (!$updated) {
        // 対象が無い場合でもUIは変えない
      }

      header("Location: admin.php?tab=users"); // 通知は出さない
      exit;
    }
    header("Location: admin.php?tab=users");
    exit;
  }

  // ユーザ：パスワードリセット（指定パスワードに変更）（PostgreSQL対応）
  if ($action === "user_reset_password") {
    $id = $_POST["user_id"] ?? "";
    $newpw = (string)($_POST["new_password"] ?? "");
    if (preg_match("/^\d+$/", (string)$id) && $newpw !== "") {
      $hash = password_hash($newpw, PASSWORD_DEFAULT);
      $stmt = $pdo->prepare("
        UPDATE users
        SET password_hash = ?
        WHERE user_id = ?
        RETURNING user_id
      ");
      $stmt->execute([$hash, (int)$id]);
      $updated = $stmt->fetchColumn();
      if (!$updated) {
        // 対象が無い場合でもUIは変えない
      }
      header("Location: admin.php?tab=users"); // 通知は出さない
      exit;
    }
    header("Location: admin.php?tab=users");
    exit;
  }

  // 予約：削除（管理者は全削除OK）（PostgreSQL対応）
  if ($action === "reservation_delete") {
    $rid = $_POST["reservation_id"] ?? "";
    if (preg_match("/^\d+$/", (string)$rid)) {
      $stmt = $pdo->prepare("
        DELETE FROM reservation
        WHERE reservation_id = ?
        RETURNING reservation_id
      ");
      $stmt->execute([(int)$rid]);
      $deleted = $stmt->fetchColumn();
      if (!$deleted) {
        // 対象が無い場合でもUIは変えない
      }
      header("Location: admin.php?tab=reservations"); // 通知は出さない
      exit;
    }
    header("Location: admin.php?tab=reservations");
    exit;
  }

  // 予約：複数削除（管理者は全削除OK）
  if ($action === "reservation_delete_bulk") {
    $ids = $_POST["reservation_ids"] ?? [];
    $ids2 = [];
    if (is_array($ids)) {
      foreach ($ids as $v) {
        $v = (string)$v;
        if (preg_match("/^\d+$/", $v)) $ids2[] = (int)$v;
      }
    }
    $ids2 = array_values(array_unique($ids2));

    if (count($ids2) > 0) {
      $placeholders = implode(",", array_fill(0, count($ids2), "?"));
      $stmt = $pdo->prepare("DELETE FROM reservation WHERE reservation_id IN ($placeholders)");
      $stmt->execute($ids2);
    }
    header("Location: admin.php?tab=reservations"); // 通知は出さない
    exit;
  }

  header("Location: admin.php?tab=" . urlencode($tab));
  exit;
}

/* =========================================================
   データ取得
========================================================= */

// 予約一覧（最新200）
$reservations = $pdo->query("
  SELECT
    r.reservation_id,
    r.reservation_date,
    r.start_time,
    r.end_time,
    r.user_name,
    r.title,
    r.user_id,
    c.classroom_name,
    c.floor
  FROM reservation r
  LEFT JOIN classroom c ON c.classroom_id = r.classroom_id
  ORDER BY r.reservation_date DESC, r.start_time DESC
  LIMIT 200
")->fetchAll(PDO::FETCH_ASSOC);

// 教室一覧
$classrooms = $pdo->query("
  SELECT classroom_id, classroom_name, floor
  FROM classroom
  ORDER BY floor, classroom_name
")->fetchAll(PDO::FETCH_ASSOC);

// ユーザ一覧（★is_user を追加）
$users = $pdo->query("
  SELECT user_id, username, is_admin, is_user, created_at
  FROM users
  ORDER BY user_id
")->fetchAll(PDO::FETCH_ASSOC);

$__back = safe_back_url($_SERVER["REQUEST_URI"] ?? "admin.php", "admin.php");
?>
<!DOCTYPE html>
<html lang="ja">

<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>教室予約システム | 管理画面</title>
  <link rel="stylesheet" href="css/style.css">
  <link rel="stylesheet" href="css/admin.css">
</head>

<body class="admin-page">

  <?php include __DIR__ . "/app_header.php"; ?>

  <?php include __DIR__ . "/drawer.php"; ?>

  <main class="page">
    <h1 class="title">管理画面</h1>

    <!-- ★通知は出したくないので表示ブロックは置かない（処理は残してる） -->
    <!--

  -->

    <div class="admin-tabs">
      <a class="admin-tab <?= $tab === "reservations" ? "is-active" : "" ?>" href="admin.php?tab=reservations">予約</a>
      <a class="admin-tab <?= $tab === "classrooms" ? "is-active" : "" ?>" href="admin.php?tab=classrooms">教室</a>
      <a class="admin-tab <?= $tab === "users" ? "is-active" : "" ?>" href="admin.php?tab=users">ユーザ</a>
    </div>

    <?php if ($tab === "reservations"): ?>
      <section class="card">
        <h2 class="card-title">予約一覧（最新200件）</h2>

        <div class="admin-resv-tools">
          <div style="font-weight:900; color:#0f172a;">エクスポート / インポート</div>
          <div style="display:flex; gap:10px; flex-wrap:wrap;">
            <a class="clear-btn" href="export_reservations.php?scope=all" style="text-decoration:none;">CSVエクスポート</a>
            <a class="clear-btn" href="reservation_import_template.php" style="text-decoration:none;">テンプレCSVをDL</a>
          </div>
        </div>


        <div class="admin-subtabs" id="adminResvSubtabs">
          <button type="button" class="admin-subtab is-active" data-target="adminResvList">予約一覧</button>
          <button type="button" class="admin-subtab" data-target="adminResvCsv">CSV一括登録</button>
        </div>

        <div class="admin-resv-panel is-active" id="adminResvList">
          <div class="admin-bulkbar">
            <label class="bulk-check">
              <input type="checkbox" id="adminSelectAll">
              <span>まとめて選択</span>
            </label>

            <button type="submit"
              class="danger-btn"
              id="adminBulkDeleteBtn"
              form="adminBulkTableForm"
              disabled
              onclick="return confirm('選択した予約を削除します。よろしいですか？');">
              選択を削除
            </button>
          </div>

          <div class="admin-reservations-layout">

            <div class="admin-reservations-scroll">
              <form method="post" action="admin.php?tab=reservations" id="adminBulkTableForm" style="margin:0;">
                <input type="hidden" name="action" value="reservation_delete_bulk">
                <table class="admin-table">
                  <thead>
                    <tr>
                      <th class="col-check"></th>
                      <th>ID</th>
                      <th>日付</th>
                      <th>時間</th>
                      <th>教室</th>
                      <th>予約者</th>
                      <th>用途</th>
                      <th>操作</th>
                    </tr>
                  </thead>
                  <tbody>
                    <?php foreach ($reservations as $r): ?>
                      <?php
                      $rid = (int)$r["reservation_id"];
                      $date = (string)$r["reservation_date"];
                      $time = substr((string)$r["start_time"], 0, 5) . "〜" . substr((string)$r["end_time"], 0, 5);
                      $room = (string)($r["classroom_name"] ?? "");
                      $floor = (string)($r["floor"] ?? "");
                      $who = (string)($r["user_name"] ?? "");
                      $what = (string)($r["title"] ?? "");
                      $back = "admin.php?tab=reservations";
                      $editUrl = "edit.php?reservation_id=" . urlencode((string)$rid) . "&back=" . urlencode($back);
                      ?>
                      <tr>
                        <td class="col-check">
                          <input type="checkbox" class="bulk-item" name="reservation_ids[]" value="<?= h($rid) ?>">
                        </td>
                        <td><?= h($rid) ?></td>
                        <td><?= h($date) ?></td>
                        <td><?= h($time) ?></td>
                        <td><?= h($room) ?><?= $floor !== "" ? "（" . h($floor) . "階）" : "" ?></td>
                        <td><?= h($who) ?></td>
                        <td><?= h($what) ?></td>
                        <td class="admin-actions">
                          <a class="reserve-btn" href="<?= h($editUrl) ?>" style="text-decoration:none; padding:7px 10px;">編集</a>
                          <button type="button" class="danger-btn admin-del-one" data-rid="<?= h($rid) ?>" style="padding:7px 10px;">削除</button>
                        </td>
                      </tr>
                    <?php endforeach; ?>

                    <?php if (count($reservations) === 0): ?>
                      <tr>
                        <td colspan="8" style="padding:14px; color:#64748b; font-weight:800;">予約がありません</td>
                      </tr>
                    <?php endif; ?>
                  </tbody>
                </table>
              </form>
            </div>

            <form method="post" action="admin.php?tab=reservations" id="adminSingleDeleteForm" style="display:none;">
              <input type="hidden" name="action" value="reservation_delete">
              <input type="hidden" name="reservation_id" id="adminSingleDeleteId" value="">
            </form>

            <script>
              (function() {
                const all = document.getElementById('adminSelectAll');
                const items = Array.from(document.querySelectorAll('#adminBulkTableForm .bulk-item'));
                const btn = document.getElementById('adminBulkDeleteBtn');
                const singleForm = document.getElementById('adminSingleDeleteForm');
                const singleId = document.getElementById('adminSingleDeleteId');

                function sync() {
                  const checked = items.filter(i => i.checked).length;
                  btn.disabled = checked === 0;
                  if (!all) return;
                  if (items.length === 0) {
                    all.checked = false;
                    all.indeterminate = false;
                    return;
                  }
                  all.checked = checked === items.length;
                  all.indeterminate = checked > 0 && checked < items.length;
                }

                if (all) {
                  all.addEventListener('change', () => {
                    items.forEach(i => i.checked = all.checked);
                    sync();
                  });
                }
                items.forEach(i => i.addEventListener('change', sync));
                sync();

                document.querySelectorAll('.admin-del-one').forEach(btnEl => {
                  btnEl.addEventListener('click', () => {
                    const rid = btnEl.getAttribute('data-rid');
                    if (!rid) return;
                    if (!confirm('この予約を削除しますか？')) return;
                    singleId.value = rid;
                    singleForm.submit();
                  });
                });
              })();
            </script>

            <script>
              (function() {
                const root = document.getElementById('adminResvSubtabs');
                if (!root) return;
                const btns = Array.from(root.querySelectorAll('.admin-subtab'));
                const panels = {
                  adminResvList: document.getElementById('adminResvList'),
                  adminResvCsv: document.getElementById('adminResvCsv')
                };

                function activate(id) {
                  btns.forEach(b => b.classList.toggle('is-active', b.dataset.target === id));
                  Object.entries(panels).forEach(([pid, el]) => {
                    if (!el) return;
                    el.classList.toggle('is-active', pid === id);
                  });
                  try {
                    sessionStorage.setItem('admin_resv_panel', id);
                  } catch (_) {}
                }
                btns.forEach(b => b.addEventListener('click', () => activate(b.dataset.target)));
                try {
                  const saved = sessionStorage.getItem('admin_resv_panel');
                  if (saved && panels[saved]) activate(saved);
                } catch (_) {}
              })();
            </script>

          </div>

        </div>

        <div class="admin-resv-panel" id="adminResvCsv">

          <div class="admin-panel__title" style="margin-bottom:10px;">CSVで一括登録（管理者）</div>

          <div class="admin-import-box">
            <div class="admin-import-help">
              <div style="color:#475569; font-weight:800; line-height:1.6;">
                形式：<code>reservation_date,classroom_name,start_time,end_time,user_name,title,username</code><br>
                ※ username は任意（空ならあなたのアカウントで登録）<br>
                ※ 一般ユーザーは <a href="bulk_import.php" style="font-weight:900;">CSV一括予約</a> を使ってね
              </div>

              <?php if (!empty($_SESSION['admin_import_report'])): ?>
                <?php $rep = $_SESSION['admin_import_report'];
                unset($_SESSION['admin_import_report']); ?>
                <div class="admin-import-log">
                  <div style="font-weight:900;">結果：成功 <?= h((string)($rep['ok'] ?? 0)) ?> 件 / 失敗 <?= h((string)($rep['ng'] ?? 0)) ?> 件</div>
                  <?php if (!empty($rep['errors'])): ?>
                    <div style="margin-top:10px; color:#b91c1c; font-weight:900;">エラー詳細</div>
                    <ul style="margin:8px 0 0 18px; color:#b91c1c; font-weight:800; line-height:1.6;">
                      <?php foreach ((array)$rep['errors'] as $e): ?>
                        <li><?= h((string)$e) ?></li>
                      <?php endforeach; ?>
                    </ul>
                  <?php endif; ?>
                </div>
              <?php endif; ?>
            </div>

            <form method="post" enctype="multipart/form-data" class="admin-import-form" action="admin.php?tab=reservations">
              <input type="hidden" name="action" value="reservation_import_csv">
              <div class="field">
                <label>CSVファイル</label>
                <input type="file" name="csv_file" accept=".csv,text/csv" required>
              </div>
              <div class="actions">
                <button type="submit" class="reserve-btn">アップロード</button>
              </div>
            </form>
          </div>
        </div>
        </div>
        </div>

      </section>
    <?php endif; ?>

    <?php if ($tab === "classrooms"): ?>
      <section class="card">
        <h2 class="card-title">教室一覧</h2>

        <div class="admin-grid">
          <div class="admin-panel">
            <div class="admin-panel__title">教室を追加</div>

            <form method="post" class="form-grid" action="admin.php?tab=classrooms">
              <input type="hidden" name="action" value="classroom_add">

              <div class="field">
                <label>教室名</label>
                <input name="classroom_name" required>
              </div>

              <div class="field">
                <label>階</label>
                <input name="floor" placeholder="例）2" required>
              </div>

              <div class="actions">
                <button class="reserve-btn" type="submit">追加</button>
              </div>
            </form>
          </div>

          <div class="admin-panel">
            <div class="admin-panel__title">教室を編集</div>

            <div class="admin-list">
              <?php foreach ($classrooms as $c): ?>
                <div class="admin-list__item">
                  <form method="post" class="admin-inline" action="admin.php?tab=classrooms">
                    <input type="hidden" name="action" value="classroom_update">
                    <input type="hidden" name="classroom_id" value="<?= h($c["classroom_id"]) ?>">

                    <input class="admin-input" name="classroom_name" value="<?= h($c["classroom_name"]) ?>" required>
                    <input class="admin-input admin-input--mini" name="floor" value="<?= h($c["floor"]) ?>" required>

                    <button class="reserve-btn" type="submit" style="padding:7px 10px;">保存</button>

                    <button class="danger-btn" type="submit"
                      formmethod="post"
                      formaction="admin.php?tab=classrooms"
                      onclick="return false;"
                      style="display:none;"></button>
                  </form>

                  <form method="post" action="admin.php?tab=classrooms"
                    onsubmit="return confirm('この教室を削除しますか？（予約がある場合は削除できません）');">
                    <input type="hidden" name="action" value="classroom_delete">
                    <input type="hidden" name="classroom_id" value="<?= h($c["classroom_id"]) ?>">
                    <button class="danger-btn" type="submit" style="padding:7px 10px;">削除</button>
                  </form>
                </div>
              <?php endforeach; ?>

              <?php if (count($classrooms) === 0): ?>
                <div style="padding:10px; color:#64748b; font-weight:800;">教室がありません</div>
              <?php endif; ?>
            </div>
          </div>
        </div>
      </section>
    <?php endif; ?>

    <?php if ($tab === "users"): ?>
      <section class="card">
        <h2 class="card-title">ユーザ一覧</h2>

        <div class="admin-table-wrap">
          <table class="admin-table">
            <thead>
              <tr>
                <th>ID</th>
                <th>ユーザ名</th>
                <th>状態</th>
                <th>管理者</th>
                <th>作成日</th>
                <th>操作</th>
              </tr>
            </thead>
            <tbody>
              <?php foreach ($users as $u): ?>
                <?php
                $uid = (int)$u["user_id"];
                $isActive = !empty($u["is_user"]); // 1=有効, 0=停止
                $isMe = (current_user_id() !== null && (int)current_user_id() === $uid);
                ?>
                <tr>
                  <td><?= h($u["user_id"]) ?></td>
                  <td><?= h($u["username"]) ?></td>

                  <td>
                    <span class="admin-badge <?= $isActive ? "is-on" : "" ?>">
                      <?= $isActive ? "有効" : "停止" ?>
                    </span>
                  </td>

                  <td>
                    <span class="admin-badge <?= !empty($u["is_admin"]) ? "is-on" : "" ?>">
                      <?= !empty($u["is_admin"]) ? "ON" : "OFF" ?>
                    </span>
                  </td>

                  <td><?= h($u["created_at"] ?? "") ?></td>

                  <td class="admin-actions">
                    <form method="post" action="admin.php?tab=users">
                      <input type="hidden" name="action" value="user_toggle_admin">
                      <input type="hidden" name="user_id" value="<?= h($u["user_id"]) ?>">
                      <button type="submit" class="reserve-btn" style="padding:7px 10px;">
                        管理者切替
                      </button>
                    </form>

                    <form method="post" action="admin.php?tab=users" class="admin-reset">
                      <input type="hidden" name="action" value="user_reset_password">
                      <input type="hidden" name="user_id" value="<?= h($u["user_id"]) ?>">
                      <input class="admin-input" name="new_password" placeholder="新しいPW" required>
                      <button type="submit" class="clear-btn" style="padding:7px 10px;">
                        PW変更
                      </button>
                    </form>

                    <!-- ★PW変更の右横：停止/解除 -->
                    <form method="post" action="admin.php?tab=users"
                      onsubmit="return confirm('このユーザを<?= $isActive ? "停止" : "解除" ?>しますか？');">
                      <input type="hidden" name="action" value="user_toggle_active">
                      <input type="hidden" name="user_id" value="<?= h($u["user_id"]) ?>">

                      <?php if ($isMe): ?>
                        <button type="button" class="clear-btn" style="padding:7px 10px; opacity:.55; cursor:not-allowed;" disabled>
                          停止不可
                        </button>
                      <?php else: ?>
                        <button type="submit" class="<?= $isActive ? "danger-btn" : "reserve-btn" ?>" style="padding:7px 10px;">
                          <?= $isActive ? "停止" : "解除" ?>
                        </button>
                      <?php endif; ?>
                    </form>

                  </td>
                </tr>
              <?php endforeach; ?>

              <?php if (count($users) === 0): ?>
                <tr>
                  <td colspan="6" style="padding:14px; color:#64748b; font-weight:800;">ユーザがいません</td>
                </tr>
              <?php endif; ?>
            </tbody>
          </table>
        </div>
      </section>
    <?php endif; ?>

  </main>

  <footer class="app-footer">2026補習© 教室管理ナビゲーション-教ナビ</footer>

  <!-- Drawer（直書き） -->
  <div class="drawer-overlay" id="drawerOverlay" aria-hidden="true"></div>

  <aside class="drawer" id="drawerMenu" aria-hidden="true">
    <div class="drawer__header">
      <div class="drawer__title">メニュー</div>
      <button type="button" class="drawer__close" aria-label="閉じる" data-drawer-close>×</button>
    </div>

    <div class="drawer__body">
      <div class="drawer__user">
        ログイン中：<?= h(current_username()) ?>
      </div>

      <nav class="drawer__nav">
        <a class="drawer__link" href="index.php">
          <span>ホーム</span>
          <span class="sub">予約状況へ戻る</span>
        </a>

        <a class="drawer__link" href="my_reservations.php">
          <span>マイページ</span>
          <span class="sub">自分の予約一覧</span>
        </a>

        <a class="drawer__link" href="login.php">
          <span>ユーザー切り替え</span>
          <span class="sub">別アカでログイン</span>
        </a>

        <a class="drawer__link" href="logout.php?next=<?= h(urlencode("login.php?back=" . urlencode($__back))) ?>">
          <span>ログアウト</span>
          <span class="sub">サインアウト</span>
        </a>

        <div style="margin-top:14px; padding-top:14px; border-top:1px solid #eef2f7;">
          <div style="font-weight:900; color:#0f172a; margin-bottom:10px;">
            管理者メニュー
          </div>

          <a class="drawer__link" href="admin.php?tab=reservations">
            <span>管理画面</span>
            <span class="sub">予約・教室・ユーザを一元管理</span>
          </a>
        </div>
      </nav>
    </div>
  </aside>
  <script src="js/admin.js"></script>
</body>

</html>