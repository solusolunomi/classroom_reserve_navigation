<?php
require __DIR__ . "/db.php";
require __DIR__ . "/auth.php";
require_login();

function h($s) {
  return htmlspecialchars((string)$s, ENT_QUOTES, "UTF-8");
}

$errors = [];
$results = [
  "inserted" => 0,
  "skipped" => 0,
  "skipped_rows" => [],
];

// 教室名→ID 変換用
$classroomMap = [];
try {
  $rows = $pdo->query("SELECT classroom_id, classroom_name FROM classroom")->fetchAll(PDO::FETCH_ASSOC);
  foreach ($rows as $r) {
    $classroomMap[(string)$r["classroom_name"]] = (int)$r["classroom_id"]; 
  }
} catch (Throwable $e) {
  $errors[] = "教室一覧の取得に失敗しました。";
}

// CSVを読み込み
if ($_SERVER["REQUEST_METHOD"] === "POST") {
  if (!isset($_FILES["csv_file"]) || !is_uploaded_file($_FILES["csv_file"]["tmp_name"])) {
    $errors[] = "CSVファイルを選択してください。";
  } else {
    $tmp = $_FILES["csv_file"]["tmp_name"];
    $fp = fopen($tmp, "r");
    if (!$fp) {
      $errors[] = "CSVファイルを読み込めませんでした。";
    } else {
      // 1行目: ヘッダ（区切り文字ゆらぎ対策：カンマ/セミコロン/タブを自動判定）
      $firstLine = fgets($fp);
      if ($firstLine === false) {
        $errors[] = "CSVの1行目（ヘッダ）が読み取れません。";
      } else {
        // UTF-8 BOM（Excel等）対策：先頭にBOMが入ることがある
        $firstLine = preg_replace('/^\xEF\xBB\xBF/', '', $firstLine);

        // 区切り文字を推定（Excel/地域設定により ; 区切りになることがある）
        $comma = substr_count($firstLine, ',');
        $semi  = substr_count($firstLine, ';');
        $tab   = substr_count($firstLine, "\t");
        $delimiter = ',';
        if ($semi > $comma && $semi >= $tab) {
          $delimiter = ';';
        } elseif ($tab > $comma && $tab > $semi) {
          $delimiter = "\t";
        }

        $header = str_getcsv($firstLine, $delimiter);
        if (!is_array($header) || count($header) === 0) {
          $errors[] = "CSVの1行目（ヘッダ）が読み取れません。";
        } else {
          // 期待ヘッダ（順不同OK）
          $header = array_map(fn($v) => trim((string)$v), $header);
          $idx = array_flip($header);

        $required = ["reservation_date", "classroom_name", "start_time", "end_time", "title"]; 
        foreach ($required as $k) {
          if (!array_key_exists($k, $idx)) {
            $errors[] = "CSVヘッダに '{$k}' がありません。テンプレートをダウンロードして使ってください。";
          }
        }
        }
      }

      if (empty($errors)) {
        $checkStmt = $pdo->prepare("
          SELECT reservation_id
          FROM reservation
          WHERE classroom_id = ?
            AND reservation_date = ?
            AND start_time < ?
            AND end_time   > ?
          LIMIT 1
        ");

        // repeat_group_id があるか（無ければ通常INSERT）
        $hasRepeatGroup = false;
        try {
          $cols = $pdo->query("SHOW COLUMNS FROM reservation")->fetchAll(PDO::FETCH_ASSOC);
          foreach ($cols as $col) {
            if ((string)($col['Field'] ?? '') === 'repeat_group_id') {
              $hasRepeatGroup = true;
              break;
            }
          }
        } catch (Throwable $e) {
          $hasRepeatGroup = false;
        }

        if ($hasRepeatGroup) {
          $insertStmt = $pdo->prepare("
            INSERT INTO reservation (title, user_name, user_id, classroom_id, reservation_date, start_time, end_time, repeat_group_id)
            VALUES (?, ?, ?, ?, ?, ?, ?, NULL)
          ");
        } else {
          $insertStmt = $pdo->prepare("
            INSERT INTO reservation (title, user_name, user_id, classroom_id, reservation_date, start_time, end_time)
            VALUES (?, ?, ?, ?, ?, ?, ?)
          ");
        }

        $accepted = []; // バッチ内重複チェック

        try {
          $pdo->beginTransaction();

          $rowNo = 1;
          while (($row = fgetcsv($fp, 0, $delimiter)) !== false) {
            $rowNo++;
            if (!is_array($row)) continue;

            // 末尾の空行スキップ
            $allEmpty = true;
            foreach ($row as $v) {
              if (trim((string)$v) !== "") { $allEmpty = false; break; }
            }
            if ($allEmpty) continue;

            $get = function(string $key) use ($row, $idx) {
              $i = $idx[$key] ?? null;
              if ($i === null) return "";
              return trim((string)($row[$i] ?? ""));
            };

$reservationDate = $get("reservation_date");
// 日付ゆらぎ対策（Excel等で 2026/3/1 や 2026-3-1 になることがある）
if (preg_match('/^(\d{4})[\/\-\.](\d{1,2})[\/\-\.](\d{1,2})$/', $reservationDate, $m)) {
  $reservationDate = sprintf('%04d-%02d-%02d', (int)$m[1], (int)$m[2], (int)$m[3]);
}
            $classroomName   = $get("classroom_name");
            $startTimeRaw    = $get("start_time");
            $endTimeRaw      = $get("end_time");
            $title           = $get("title");
            $userName        = array_key_exists("user_name", $idx) ? $get("user_name") : "";
            $username        = array_key_exists("username", $idx) ? $get("username") : "";

            // user_name が空なら、ログイン中のユーザ名を使う
            if ($userName === "") $userName = current_username();

            // 非管理者は、username を無視して必ず自分の予約として登録
            $targetUserId = current_user_id();
            if (function_exists('is_admin') && is_admin() && $username !== "") {
              // 管理者のみ：username で所有者を指定できる
              $ust = $pdo->prepare("SELECT user_id, username FROM users WHERE username = ? LIMIT 1");
              $ust->execute([$username]);
              $uu = $ust->fetch(PDO::FETCH_ASSOC);
              if ($uu) {
                $targetUserId = (int)$uu["user_id"];
                // user_name 未指定なら、アカウント名を表示名として使う
                if ($get("user_name") === "") {
                  $userName = (string)$uu["username"];
                }
              }
            }

            
            // 日付ゆらぎ対策（Excelなどで 2026/3/1 のようになることがある）
            $reservationDate = trim((string)$reservationDate);
            if (preg_match('/^(\d{4})[\/\-](\d{1,2})[\/\-](\d{1,2})$/', $reservationDate, $m)) {
              $reservationDate = sprintf('%04d-%02d-%02d', (int)$m[1], (int)$m[2], (int)$m[3]);
            }
$note = "";

            if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $reservationDate)) {
              $note = "日付形式が不正";
            } elseif ($classroomName === "" || !isset($classroomMap[$classroomName])) {
              $note = "教室名が不正";
            } elseif ($title === "") {
              $note = "用途(title)が未入力";
            }

            // 時刻整形（HH:MM or HH:MM:SS）
            $normTime = function(string $t): string {
              $t = trim($t);
              if (preg_match('/^\d{1,2}:\d{2}$/', $t)) {
                $p = explode(':', $t);
                return sprintf('%02d:%02d:00', (int)$p[0], (int)$p[1]);
              }
              if (preg_match('/^\d{1,2}:\d{2}:\d{2}$/', $t)) {
                $p = explode(':', $t);
                return sprintf('%02d:%02d:%02d', (int)$p[0], (int)$p[1], (int)$p[2]);
              }
              return "";
            };

            $startTime = $normTime($startTimeRaw);
            $endTime   = $normTime($endTimeRaw);
            if ($note === "") {
              if ($startTime === "" || $endTime === "") {
                $note = "時刻形式が不正";
              } elseif ($startTime >= $endTime) {
                $note = "開始/終了が不正";
              }
            }

            if ($note !== "") {
              $results["skipped"]++;
              $results["skipped_rows"][] = ["row"=>$rowNo, "note"=>$note, "data"=>$row];
              continue;
            }

            $classroomId = $classroomMap[$classroomName];

            // バッチ内重複チェック
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
              $results["skipped"]++;
              $results["skipped_rows"][] = ["row"=>$rowNo, "note"=>"CSV内で時間が重複", "data"=>$row];
              continue;
            }

            // 既存予約との重複
            $checkStmt->execute([$classroomId, $reservationDate, $endTime, $startTime]);
            $hit = $checkStmt->fetch();
            if ($hit) {
              $results["skipped"]++;
              $results["skipped_rows"][] = ["row"=>$rowNo, "note"=>"既存予約と重複", "data"=>$row];
              continue;
            }

            // INSERT
            $insertStmt->execute([$title, $userName, $targetUserId, $classroomId, $reservationDate, $startTime, $endTime]);
            $results["inserted"]++;
            $accepted[$key][] = ['start' => $startTime, 'end' => $endTime];
          }

          $pdo->commit();
        } catch (Throwable $e) {
          if ($pdo->inTransaction()) $pdo->rollBack();
          $errors[] = "登録処理でエラーが発生しました：" . $e->getMessage();
        }
      }

      fclose($fp);
    }
  }
}
?>
<!DOCTYPE html>
<html lang="ja">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>教室予約システム | CSV一括予約</title>
  <link rel="stylesheet" href="css/style.css">
</head>
<body>

<?php include __DIR__ . "/app_header.php"; ?>

<main class="page">
  <h1 class="title">CSV一括予約</h1>

  <section class="card">
    <h2 class="card-title">テンプレート</h2>
    <p class="card-text" style="font-weight:800; color:#475569;">
      まずはテンプレートをダウンロードして、その形式のままCSVを作ってアップロードしてね。
      <br>※ <b>user_name</b> は予約一覧に表示する「予約者名」。空ならログイン中のユーザ名になる。
      <br>※ <b>username</b> は「どのアカウントの予約として登録するか」。<b>管理者だけ</b>使える（一般ユーザは無視され、自分の予約として登録される）。
    </p>
    <div class="actions" style="justify-content:flex-start; gap:10px;">
      <a class="clear-btn" href="reservation_import_template.php" style="text-decoration:none;">テンプレートCSVをダウンロード</a>
      <a class="clear-btn" href="index.php" style="text-decoration:none;">戻る</a>
    </div>
  </section>

  <section class="card" style="margin-top:12px;">
    <h2 class="card-title">CSVをアップロード</h2>

    <?php if (!empty($errors)): ?>
      <div class="card-text" style="color:#b91c1c; font-weight:900;">
        <?php foreach ($errors as $e): ?>
          <div>・<?= h($e) ?></div>
        <?php endforeach; ?>
      </div>
    <?php endif; ?>

    <?php if ($_SERVER["REQUEST_METHOD"] === "POST" && empty($errors)): ?>
      <div class="card-text" style="font-weight:900;">
        登録：<?= h($results["inserted"]) ?> 件 / スキップ：<?= h($results["skipped"]) ?> 件
      </div>
      <?php if (!empty($results["skipped_rows"])): ?>
        <div class="card-text" style="margin-top:8px; color:#475569; font-weight:800;">
          スキップ理由（先頭20件まで表示）：
          <?php foreach (array_slice($results["skipped_rows"], 0, 20) as $sr): ?>
            <div>・<?= h($sr["row"]) ?>行目：<?= h($sr["note"]) ?></div>
          <?php endforeach; ?>
        </div>
      <?php endif; ?>
    <?php endif; ?>

    <form method="post" enctype="multipart/form-data" class="form-grid" style="margin-top:12px;">
      <div class="field">
        <label for="csv_file">CSVファイル</label>
        <input type="file" id="csv_file" name="csv_file" accept=".csv,text/csv" required>
      </div>

      <div class="actions actions-reserve" style="margin-top:14px;">
        <a class="clear-btn" href="index.php" style="text-decoration:none;">戻る</a>
        <button type="submit" class="reserve-btn reserve-btn--wide">一括登録</button>
      </div>
    </form>
  </section>

</main>

<footer class="app-footer">2025補習© 教室予約システム</footer>

<?php
$__back = safe_back_url($_SERVER["REQUEST_URI"] ?? "bulk_import.php", "index.php");
include __DIR__ . "/drawer.php";
?>
</body>
</html>