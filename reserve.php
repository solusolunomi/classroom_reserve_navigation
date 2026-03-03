<?php
require __DIR__ . "/db.php";
require __DIR__ . "/auth.php";
require_login();

function h($s)
{
  return htmlspecialchars((string)$s, ENT_QUOTES, "UTF-8");
}

function minutesToTime(int $minutes, int $startHour = 8): string
{
  $total = $minutes + $startHour * 60;
  $hour = intdiv($total, 60);
  $min  = $total % 60;
  return sprintf("%02d:%02d", $hour, $min);
}

function clamp_int(int $v, int $min, int $max): int
{
  if ($v < $min) return $min;
  if ($v > $max) return $max;
  return $v;
}

/* =========================================================
   受け取り
========================================================= */
$selectionsJsonArray = $_POST["selections_json"] ?? "";
$selectionsWrapped   = $_POST["selections"] ?? ($_GET["selections"] ?? "");

$floor = (string)($_POST["floor"] ?? ($_GET["floor"] ?? "all"));
$date  = (string)($_POST["date"]  ?? ($_GET["date"]  ?? ""));
$back  = (string)($_POST["back"]  ?? ($_GET["back"]  ?? ""));

if ($back === "") {
  if ($date !== "" && preg_match("/^\d{4}-\d{2}-\d{2}$/", $date)) {
    $back = "index.php?date=" . urlencode($date) . "&floor=" . urlencode($floor ?: "all");
  } else {
    $back = "index.php?floor=" . urlencode($floor ?: "all");
  }
}

/* =========================================================
   items抽出（優先順位：配列JSON → itemsラップ）
========================================================= */
$items = [];

// A) selections_json（配列）
if ($selectionsJsonArray !== "") {
  $decoded = json_decode($selectionsJsonArray, true);
  if (is_array($decoded)) $items = $decoded;
}

// B) selections（itemsラップ）
if (empty($items) && $selectionsWrapped !== "") {
  $decoded = json_decode($selectionsWrapped, true);
  if (is_array($decoded) && isset($decoded["items"]) && is_array($decoded["items"])) {
    $items = $decoded["items"];
  }
}

/* =========================================================
   表示用に整形（キー揺れ吸収 + 営業時間(8-18) + 5分丸め）
========================================================= */
$START_HOUR = 7;
$END_HOUR   = 19;
$TOTAL_MIN  = ($END_HOUR - $START_HOUR) * 60; // 720
$SNAP       = 5;

$viewItems = [];

foreach ($items as $it) {
  if (!is_array($it)) continue;

  $rid = (string)($it["room_id"] ?? ($it["roomId"] ?? ($it["roomID"] ?? "")));
  $rnm = (string)($it["room_name"] ?? ($it["roomName"] ?? ($it["room"] ?? "")));
  $dt  = (string)($it["date"] ?? "");

  $fRaw = $it["from"] ?? null;
  $tRaw = $it["to"] ?? null;

  if ($rid === "" || $dt === "" || !is_numeric($fRaw) || !is_numeric($tRaw)) continue;
  if (!preg_match("/^\d{4}-\d{2}-\d{2}$/", $dt)) continue;

  $f = (int)$fRaw;
  $t = (int)$tRaw;

  if ($t < $f) {
    $tmp = $f;
    $f = $t;
    $t = $tmp;
  }
  if ($t === $f) continue;

  // 営業時間内へ丸め
  $f = clamp_int($f, 0, $TOTAL_MIN);
  $t = clamp_int($t, 0, $TOTAL_MIN);

  // 5分刻みへ丸め
  $f = (int)round($f / $SNAP) * $SNAP;
  $t = (int)round($t / $SNAP) * $SNAP;

  // 変な逆転を保険で修正
  if ($t <= $f) continue;

  $viewItems[] = [
    "room_id"   => $rid,
    "room_name" => $rnm,
    "date"      => $dt,
    "from"      => $f,
    "to"        => $t,
  ];
}

?>
<!DOCTYPE html>
<html lang="ja">

<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>教室予約システム | 予約確認</title>
  <link rel="stylesheet" href="css/style.css">
</head>

<body>

  <?php include __DIR__ . "/app_header.php"; ?>

  <?php include __DIR__ . "/drawer.php"; ?>

  <main class="page">
    <h1 class="title">予約確認</h1>

    <section class="card">
      <h2 class="card-title">予約内容</h2>

      <?php if (empty($viewItems)): ?>
        <p class="card-text">予約内容がありません。最初の画面に戻って選択してください。</p>
        <div class="actions">
          <a class="clear-btn" href="<?= h($back) ?>" style="text-decoration:none; display:inline-flex; align-items:center;">戻る</a>
        </div>
      <?php else: ?>
        <div class="reserve-edit-list" id="reserveEditList"></div>
      <?php endif; ?>
    </section>

    <?php if (!empty($viewItems)): ?>
      <section class="card">
        <h2 class="card-title">予約者情報</h2>

        <form action="complete.php" method="post" class="form-grid" id="reserveForm">
          <input type="hidden" id="selectionsJson" name="selections" value="">
          <input type="hidden" name="floor" value="<?= h($floor) ?>">
          <input type="hidden" name="back" value="<?= h($back) ?>">

          <div class="field">
            <label for="user_name">予約者の名前</label>
            <input type="text" id="user_name" name="user_name" value="<?= h(current_username()) ?>">
          </div>

          <div class="field">
            <label for="purpose">利用用途</label>
            <input type="text" id="purpose" name="purpose" required placeholder="例）会議、勉強会">
          </div>

          <div class="field repeat-field" id="repeatField">
            <label class="repeat-label" for="repeat_weekly">
              <input type="checkbox" id="repeat_weekly" name="repeat_weekly" value="1">
              <span>繰り返し（毎週）</span>
            </label>

            <div class="repeat-body" id="repeatBody" aria-hidden="true">
              <div class="repeat-row">
                <label for="repeat_until" class="repeat-until-label">終了日</label>
                <input type="date" id="repeat_until" name="repeat_until" class="repeat-date">
              </div>
              <p class="repeat-help">（この日まで毎週同じ予約を作成）</p>
            </div>
          </div>

          <div class="actions">
            <a class="clear-btn" href="<?= h($back) ?>" style="text-decoration:none; display:inline-flex; align-items:center;">戻る</a>
            <button type="submit" class="reserve-btn">予約確定</button>
          </div>
        </form>
      </section>
    <?php endif; ?>
  </main>

  <footer class="app-footer">2026補習© 教室管理ナビゲーション-教ナビ</footer>

  <script>
    // ---------- データ ----------
    const START_HOUR = <?= (int)$START_HOUR ?>;
    const END_HOUR = <?= (int)$END_HOUR ?>;
    const TOTAL_MIN = (END_HOUR - START_HOUR) * 60;
    const SNAP = <?= (int)$SNAP ?>;

    /** @type {{room_id:string,room_name:string,date:string,from:number,to:number}[]} */
    let items = <?= json_encode($viewItems, JSON_UNESCAPED_UNICODE) ?>;

    // ---------- util ----------
    const pad2 = (n) => String(n).padStart(2, "0");
    const clamp = (v, min, max) => Math.max(min, Math.min(max, v));
    const toTime = (m) => {
      const total = m + START_HOUR * 60;
      const hh = Math.floor(total / 60);
      const mm = total % 60;
      return `${pad2(hh)}:${pad2(mm)}`;
    };
    const snap = (m) => Math.round(m / SNAP) * SNAP;

    function serializeSelections() {
      // complete.php 互換：itemsラップ（from/toは分）
      const payload = {
        items: items.map(x => ({
          room_id: x.room_id,
          room_name: x.room_name,
          date: x.date,
          from: x.from,
          to: x.to
        }))
      };
      document.getElementById("selectionsJson").value = JSON.stringify(payload);
    }

    // ---------- UIレンダ ----------
    function renderList() {
      const root = document.getElementById("reserveEditList");
      if (!root) return;
      root.innerHTML = "";

      items.forEach((it, idx) => {
        const row = document.createElement("div");
        row.className = "reserve-edit-row";
        row.dataset.idx = String(idx);

        row.innerHTML = `
        <div class="reserve-edit-meta">
          <span class="badge">${escapeHtml(it.date)}</span>
          <span class="badge">${escapeHtml(it.room_name || "教室")}</span>
        </div>

        <div class="reserve-edit-time">
          <div class="time-box">
                        <div class="time-stepper">
              <button type="button" class="step-btn" data-act="start-minus" aria-label="開始 -5分">−</button>
              <div class="time-read" data-role="start">${toTime(it.from)}</div>
              <button type="button" class="step-btn" data-act="start-plus" aria-label="開始 +5分">＋</button>
            </div>
          </div>

          <div class="time-sep">〜</div>

          <div class="time-box">
                        <div class="time-stepper">
              <button type="button" class="step-btn" data-act="end-minus" aria-label="終了 -5分">−</button>
              <div class="time-read" data-role="end">${toTime(it.to)}</div>
              <button type="button" class="step-btn" data-act="end-plus" aria-label="終了 +5分">＋</button>
            </div>
          </div>
        </div>
`;

        root.appendChild(row);
        updateButtons(row, it);
      });

      serializeSelections();
    }

    function updateButtons(row, it) {
      const btn = (act) => row.querySelector(`.step-btn[data-act="${act}"]`);
      const disable = (el, on) => {
        if (!el) return;
        el.disabled = !!on;
        el.classList.toggle("is-disabled", !!on);
      };

      disable(btn("start-minus"), it.from - SNAP < 0);
      disable(btn("start-plus"), it.from + SNAP >= it.to);
      disable(btn("end-minus"), it.to - SNAP <= it.from);
      disable(btn("end-plus"), it.to + SNAP > TOTAL_MIN);
    }

    function applyStep(idx, act) {
      const it = items[idx];
      if (!it) return;

      if (act === "start-minus") it.from = snap(clamp(it.from - SNAP, 0, it.to - SNAP));
      if (act === "start-plus") it.from = snap(clamp(it.from + SNAP, 0, it.to - SNAP));
      if (act === "end-minus") it.to = snap(clamp(it.to - SNAP, it.from + SNAP, TOTAL_MIN));
      if (act === "end-plus") it.to = snap(clamp(it.to + SNAP, it.from + SNAP, TOTAL_MIN));

      const row = document.querySelector(`.reserve-edit-row[data-idx="${idx}"]`);
      if (row) {
        const startEl = row.querySelector('[data-role="start"]');
        const endEl = row.querySelector('[data-role="end"]');
        if (startEl) startEl.textContent = toTime(it.from);
        if (endEl) endEl.textContent = toTime(it.to);
        updateButtons(row, it);
      }
      serializeSelections();
    }

    function escapeHtml(str) {
      return String(str).replace(/[&<>"']/g, (m) => ({
        "&": "&amp;",
        "<": "&lt;",
        ">": "&gt;",
        '"': "&quot;",
        "'": "&#39;"
      })[m]);
    }

    // ---------- Repeat UI ----------
    function syncRepeatUI() {
      const cb = document.getElementById("repeat_weekly");
      const body = document.getElementById("repeatBody");
      const until = document.getElementById("repeat_until");
      if (!cb || !body || !until) return;

      const on = cb.checked;
      body.style.display = on ? "block" : "none";
      body.setAttribute("aria-hidden", on ? "false" : "true");
      until.required = on;
      if (!on) until.value = "";
    }

    document.addEventListener("click", (e) => {
      const btn = e.target.closest ? e.target.closest(".step-btn") : null;
      if (!btn) return;
      const row = btn.closest(".reserve-edit-row");
      if (!row) return;
      const idx = Number(row.dataset.idx || "-1");
      const act = btn.dataset.act || "";
      if (idx >= 0 && act) applyStep(idx, act);
    });

    document.getElementById("repeat_weekly")?.addEventListener("change", syncRepeatUI);

    // 初期描画
    renderList();
    syncRepeatUI();
  </script>
</body>

</html>