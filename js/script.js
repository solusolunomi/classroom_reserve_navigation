document.addEventListener("DOMContentLoaded", () => {
  consumeClearFlag();

  initDateHeader();
  initSchedule();
  initReserveBarUI();
  initReservationModal(); // ←これ追加

  renderBlocksForCurrentDate();
  syncReserveBar();
});


/* =========================================================
   設定・状態
========================================================= */
const STORAGE_KEY = "selectedReservations_v1";
let selections = []; // { key, date, roomId, roomName, from, to }
let selectionSeq = 1;

/* =========================================================
   予約確定後に戻ったら選択を消す（complete.php→index.php?...&cleared=1）
========================================================= */
function consumeClearFlag() {
  const url = new URL(window.location.href);
  const cleared = url.searchParams.get("cleared");

  if (cleared === "1") {
    selections = [];
    selectionSeq = 1;
    try { sessionStorage.removeItem(STORAGE_KEY); } catch (_) {}

    // URLから cleared=1 を消す（見た目用）
    url.searchParams.delete("cleared");
    window.history.replaceState({}, "", url.toString());
  } else {
    restoreSelectionsFromSession();
  }
}

function saveSelectionsToSession() {
  try {
    sessionStorage.setItem(STORAGE_KEY, JSON.stringify(selections));
  } catch (_) {}
}

function restoreSelectionsFromSession() {
  try {
    const raw = sessionStorage.getItem(STORAGE_KEY);
    if (!raw) return;
    const parsed = JSON.parse(raw);
    if (!Array.isArray(parsed)) return;
    selections = parsed;

    let max = 0;
    for (const s of selections) {
      const n = Number(String(s.key || "").replace("sel_", ""));
      if (!Number.isNaN(n)) max = Math.max(max, n);
    }
    selectionSeq = max + 1;
  } catch (_) {}
}

/* =========================================================
   Header Date（URLのdateを更新してリロード）
========================================================= */
function initDateHeader() {
  const trigger = document.getElementById("dateTrigger");
  const picker = document.getElementById("datePicker");

  const yearEl = document.getElementById("yearText");
  const mdEl = document.getElementById("mdText");
  const weekEl = document.getElementById("weekText");

  const prevBtn = document.querySelector(".date-btn.prev");
  const nextBtn = document.querySelector(".date-btn.next");

  if (!trigger || !picker || !yearEl || !mdEl || !weekEl || !prevBtn || !nextBtn) return;

  const week = ["日", "月", "火", "水", "木", "金", "土"];

  const initial = (window.__SELECTED_DATE__ && /^\d{4}-\d{2}-\d{2}$/.test(window.__SELECTED_DATE__))
    ? new Date(window.__SELECTED_DATE__)
    : new Date();

  let currentDate = initial;

  function render(dateObj, shouldReload = false) {
    const y = dateObj.getFullYear();
    const m = String(dateObj.getMonth() + 1).padStart(2, "0");
    const d = String(dateObj.getDate()).padStart(2, "0");

    yearEl.textContent = `${y}`;
    mdEl.textContent = `${m}/${d}`;
    weekEl.textContent = `曜日：(${week[dateObj.getDay()]})`;
    picker.value = `${y}-${m}-${d}`;

    if (shouldReload) {
      const url = new URL(window.location.href);
      url.searchParams.set("date", `${y}-${m}-${d}`);
      // floor等は保持したまま
      window.location.href = url.toString();
    }
  }

  render(currentDate, false);

  trigger.addEventListener("click", () => {
    if (picker.showPicker) picker.showPicker();
    else picker.click();
  });

  picker.addEventListener("change", () => {
    if (!picker.value) return;
    currentDate = new Date(picker.value);
    render(currentDate, true);
  });

  prevBtn.addEventListener("click", () => {
    currentDate.setDate(currentDate.getDate() - 1);
    render(currentDate, true);
  });

  nextBtn.addEventListener("click", () => {
    currentDate.setDate(currentDate.getDate() + 1);
    render(currentDate, true);
  });
}

/* =========================================================
   Schedule（ドラッグ選択 + DB予約描画 + DBクリックでedit.phpへ）
========================================================= */
function initSchedule() {
  const header = document.getElementById("timelineHeader");
  const lanes = document.querySelectorAll(".lane");
  const clearBtn = document.getElementById("clearSelectionBtn");

  if (!header || lanes.length === 0) return;

  const rootStyle = getComputedStyle(document.documentElement);
  const START_HOUR = Number(rootStyle.getPropertyValue("--start-hour").trim());
  const END_HOUR = Number(rootStyle.getPropertyValue("--end-hour").trim());

  const SNAP_MINUTES = 5;

  renderTimeHeader(header, START_HOUR, END_HOUR);

  let dragging = null; // { lane, date, roomId, roomName, startMinutes, blockEl }

  lanes.forEach((lane) => {

    // DB予約クリック：自分の予約だけ edit.php へ（他人のはポップアップ）
// DB予約クリック：詳細モーダル（自分なら「編集へ」ボタン表示）
    lane.addEventListener("click", (e) => {
      const target = e.target;
      if (!(target instanceof HTMLElement)) return;
      if (!target.classList.contains("db-block")) return;

      openReservationModalFromBlock(target);
    });


    lane.addEventListener("pointerdown", (e) => {
      // DB予約ブロック上からのドラッグ開始は無効（ついでに他人予約ならトースト）
      if (e.target instanceof HTMLElement && e.target.classList.contains("db-block")) {
        if (e.target.dataset.canEdit !== "1") {
          showToast("この予約は編集できません（他のユーザーの予約です）");
        }
        return;
      }

      // sel-block上のポインタダウン → 移動モード
      if (e.target instanceof HTMLElement && e.target.classList.contains("sel-block")) {
        const key = e.target.dataset.key;
        if (!key) return;
        const sel = selections.find(s => s.key === key);
        if (!sel) return;

        e.preventDefault();
        lane.setPointerCapture(e.pointerId);

        const rect = lane.getBoundingClientRect();
        const x = clamp(e.clientX - rect.left, 0, rect.width);
        const clickMinutes = snapXToMinutes(x, rect.width, START_HOUR, END_HOUR, SNAP_MINUTES);

        dragging = {
          mode: "move",
          lane,
          date: sel.date,
          roomId: sel.roomId,
          roomName: sel.roomName,
          key,
          originalFrom: sel.from,
          originalTo: sel.to,
          duration: sel.to - sel.from,
          offsetMinutes: clickMinutes - sel.from,
          blockEl: e.target,
          currentFrom: sel.from,
          currentTo: sel.to,
        };

        e.target.classList.add("is-dragging");
        return;
      }

      lane.setPointerCapture(e.pointerId);

      const rect = lane.getBoundingClientRect();
      const x = clamp(e.clientX - rect.left, 0, rect.width);
      const start = snapXToMinutes(x, rect.width, START_HOUR, END_HOUR, SNAP_MINUTES);

      const date = getSelectedDate();
      const roomId = lane.dataset.roomId || "";
      const roomName = lane.dataset.room || "";

      // DB予約に重なる開始点なら拒否
      if (isOverlappingDbReservations(date, roomId, start, start + SNAP_MINUTES)) {
        dragging = null;
        return;
      }

      const block = document.createElement("div");
      block.className = "block sel-block";
      lane.appendChild(block);

      dragging = { lane, date, roomId, roomName, startMinutes: start, blockEl: block };
      updateBlock(dragging, start, START_HOUR, END_HOUR);
    });

    lane.addEventListener("pointermove", (e) => {
      if (!dragging || dragging.lane !== lane) return;

      const rect = lane.getBoundingClientRect();
      const x = clamp(e.clientX - rect.left, 0, rect.width);

      if (dragging.mode === "move") {
        const totalMinutes = (END_HOUR - START_HOUR) * 60;
        const rawMinutes = (x / rect.width) * totalMinutes;
        let newFrom = Math.round((rawMinutes - dragging.offsetMinutes) / SNAP_MINUTES) * SNAP_MINUTES;
        newFrom = clamp(newFrom, 0, totalMinutes - dragging.duration);
        const newTo = newFrom + dragging.duration;

        dragging.currentFrom = newFrom;
        dragging.currentTo = newTo;

        const left = minutesToPercent(newFrom, START_HOUR, END_HOUR);
        const right = minutesToPercent(newTo, START_HOUR, END_HOUR);
        dragging.blockEl.style.left = `${left}%`;
        dragging.blockEl.style.width = `${right - left}%`;
        return;
      }

      const current = snapXToMinutes(x, rect.width, START_HOUR, END_HOUR, SNAP_MINUTES);
      updateBlock(dragging, current, START_HOUR, END_HOUR);
    });

    lane.addEventListener("pointerup", (e) => {
      if (!dragging || dragging.lane !== lane) return;

      if (dragging.mode === "move") {
        dragging.blockEl.classList.remove("is-dragging");
        const newFrom = dragging.currentFrom;
        const newTo = dragging.currentTo;

        const hasOverlapDb = isOverlappingDbReservations(dragging.date, dragging.roomId, newFrom, newTo);
        const hasOverlapSel = selections.some(s => {
          if (s.key === dragging.key) return false;
          if (s.date !== dragging.date) return false;
          if (String(s.roomId) !== String(dragging.roomId)) return false;
          return rangesOverlap(s.from, s.to, newFrom, newTo);
        });

        if (hasOverlapDb || hasOverlapSel) {
          const left = minutesToPercent(dragging.originalFrom, START_HOUR, END_HOUR);
          const right = minutesToPercent(dragging.originalTo, START_HOUR, END_HOUR);
          dragging.blockEl.style.left = `${left}%`;
          dragging.blockEl.style.width = `${right - left}%`;
          showToast("他の予約と重なるため移動できません");
        } else {
          const sel = selections.find(s => s.key === dragging.key);
          if (sel) {
            sel.from = newFrom;
            sel.to = newTo;
          }
          dragging.blockEl.dataset.from = String(newFrom);
          dragging.blockEl.dataset.to = String(newTo);
          saveSelectionsToSession();
          syncReserveBar();
        }

        dragging = null;
        return;
      }

      const rect = lane.getBoundingClientRect();
      const x = clamp(e.clientX - rect.left, 0, rect.width);
      const end = snapXToMinutes(x, rect.width, START_HOUR, END_HOUR, SNAP_MINUTES);

      const { from, to } = normalizeRange(dragging.startMinutes, end);

      if (to - from < SNAP_MINUTES) {
        dragging.blockEl.remove();
        dragging = null;
        return;
      }

      if (isOverlappingDbReservations(dragging.date, dragging.roomId, from, to)) {
        dragging.blockEl.remove();
        dragging = null;
        return;
      }

      if (isOverlappingSelections(dragging.date, dragging.roomId, from, to)) {
        dragging.blockEl.remove();
        dragging = null;
        return;
      }

      const key = `sel_${selectionSeq++}`;
      selections.push({
        key,
        date: dragging.date,
        roomId: dragging.roomId,
        roomName: dragging.roomName,
        from,
        to
      });

      saveSelectionsToSession();

      dragging.blockEl.classList.add("is-reserved");
      dragging.blockEl.dataset.key = key;
      dragging.blockEl.dataset.from = String(from);
      dragging.blockEl.dataset.to = String(to);

      dragging = null;

      syncReserveBar();
      rerenderSelectionBlocksForCurrentDate();
    });

    lane.addEventListener("pointercancel", () => {
      if (!dragging || dragging.lane !== lane) return;
      if (dragging.mode === "move") {
        dragging.blockEl.classList.remove("is-dragging");
        const left = minutesToPercent(dragging.originalFrom, START_HOUR, END_HOUR);
        const right = minutesToPercent(dragging.originalTo, START_HOUR, END_HOUR);
        dragging.blockEl.style.left = `${left}%`;
        dragging.blockEl.style.width = `${right - left}%`;
      } else {
        dragging.blockEl.remove();
      }
      dragging = null;
    });
  });

  // クリア（表示中の日付だけ消す）
  if (clearBtn) {
    clearBtn.addEventListener("click", () => {
      const date = getSelectedDate();
      selections = selections.filter(s => s.date !== date);
      saveSelectionsToSession();

      rerenderSelectionBlocksForCurrentDate();
      syncReserveBar();
    });
  }
}

/* =========================================================
   Reserve Bar UI（開閉）
========================================================= */
function initReserveBarUI() {
  const bar = document.getElementById("reserveBar");
  const toggleBtn = document.getElementById("reserveToggleBtn");
  const panel = document.getElementById("reservePanel");
  if (!bar || !toggleBtn || !panel) return;

  toggleBtn.addEventListener("click", () => {
    const expanded = toggleBtn.getAttribute("aria-expanded") === "true";
    toggleBtn.setAttribute("aria-expanded", String(!expanded));
    panel.hidden = expanded;
  });
}

/* =========================================================
   Reserve Bar 表示同期（プロっぽく：一覧＋個別削除ボタン付き）
========================================================= */
function syncReserveBar() {
  const bar = document.getElementById("reserveBar");
  const summary = document.getElementById("reserveSummary");
  const list = document.getElementById("reserveList");
  const jsonEl = document.getElementById("selectionsJson");
  const reserveBtn = document.getElementById("reserveBtn");
  if (!bar || !summary || !list || !jsonEl || !reserveBtn) return;

  const count = selections.length;
  if (count === 0) {
    bar.setAttribute("aria-hidden", "true");
    bar.classList.remove("is-show");
    summary.textContent = "0件";
    list.innerHTML = "";
    jsonEl.value = "";
    reserveBtn.disabled = true;
    return;
  }

  bar.setAttribute("aria-hidden", "false");
  bar.classList.add("is-show");
  summary.textContent = `${count}件`;

  list.innerHTML = "";

  const rootStyle = getComputedStyle(document.documentElement);
  const START_HOUR = Number(rootStyle.getPropertyValue("--start-hour").trim());

  selections.forEach((s) => {
    const item = document.createElement("div");
    item.className = "reserve-item";

    const meta = document.createElement("div");
    meta.className = "reserve-item__meta";

    const dateEl = document.createElement("span");
    dateEl.className = "reserve-item__date";
    dateEl.textContent = s.date;

    const roomEl = document.createElement("span");
    roomEl.className = "reserve-item__room";
    roomEl.textContent = s.roomName || `教室ID:${s.roomId}`;

    const timeEl = document.createElement("span");
    timeEl.className = "reserve-item__time";
    timeEl.textContent = `${minutesToClock(s.from, START_HOUR)}〜${minutesToClock(s.to, START_HOUR)}`;

    meta.appendChild(dateEl);
    meta.appendChild(roomEl);
    meta.appendChild(timeEl);

    const removeBtn = document.createElement("button");
    removeBtn.type = "button";
    removeBtn.className = "reserve-item__remove";
    removeBtn.setAttribute("aria-label", "この選択を削除");
    removeBtn.textContent = "×";

    removeBtn.addEventListener("click", () => {
      selections = selections.filter(x => x.key !== s.key);
      saveSelectionsToSession();
      rerenderSelectionBlocksForCurrentDate();
      syncReserveBar();
    });

    item.appendChild(meta);
    item.appendChild(removeBtn);
    list.appendChild(item);
  });

  jsonEl.value = JSON.stringify(selections);
  reserveBtn.disabled = false;
}

/* =========================================================
   描画（DB予約 + 選択）
========================================================= */
function renderBlocksForCurrentDate() {
  renderDbReservationsForCurrentDate();
  rerenderSelectionBlocksForCurrentDate();
}

function renderDbReservationsForCurrentDate() {
  const lanes = document.querySelectorAll(".lane");
  if (lanes.length === 0) return;

  lanes.forEach(lane => {
    lane.querySelectorAll(".db-block").forEach(b => b.remove());
  });

  const rootStyle = getComputedStyle(document.documentElement);
  const START_HOUR = Number(rootStyle.getPropertyValue("--start-hour").trim());
  const END_HOUR = Number(rootStyle.getPropertyValue("--end-hour").trim());

  const date = getSelectedDate();
  const db = Array.isArray(window.__DB_RESERVATIONS__) ? window.__DB_RESERVATIONS__ : [];

  for (const lane of lanes) {
    const roomId = lane.dataset.roomId || "";

    const relevant = db
      .map(r => normalizeDbReservationToRelativeMinutes(r, START_HOUR))
      .filter(x => x && String(x.date) === String(date) && String(x.roomId) === String(roomId));

    for (const r of relevant) {
      const block = document.createElement("div");
      block.className = "block db-block";
      block.dataset.reservationId = String(r.reservationId);

      // ★編集できるのは「自分の予約」または「管理者」
      const me = window.__CURRENT_USER_ID__;
      const isAdmin = (window.__IS_ADMIN__ === true);

      const isMine = (me != null && r.userId != null && String(me) === String(r.userId));
      const canEdit = (isAdmin || isMine);

      block.dataset.canEdit = canEdit ? "1" : "0";
      block.dataset.isMine = isMine ? "1" : "0"; // ついでに持たせとく（後で便利）

      /* 自分の予約なら青 */
      if (isMine) {
        block.classList.add("is-mine");
      } else {
        block.classList.remove("is-mine");
      }

	  /* ★個人別の色（users.color） */
	  if (typeof r.userColor === "string" && /^#[0-9a-fA-F]{6}$/.test(r.userColor)) {
	    block.style.background = r.userColor;
	  }

      /* 編集不可なら薄く＆カーソル */
      if (!canEdit) {
        block.style.cursor = "not-allowed";
        block.style.opacity = "0.75";
      } else {
        block.style.cursor = "pointer";
        block.style.opacity = "1";
      }


      const left = minutesToPercent(r.from, START_HOUR, END_HOUR);
      const right = minutesToPercent(r.to, START_HOUR, END_HOUR);
      block.style.left = `${left}%`;
      block.style.width = `${right - left}%`;

      const who = (r.userName && String(r.userName).trim()) ? String(r.userName).trim() : "（未入力）";
      const what = (r.title && String(r.title).trim()) ? String(r.title).trim() : "（未入力）";

      block.textContent = `${what}：${who}`;
            // ★モーダル表示用データ
      block.dataset.roomName = String(lane.dataset.room || "");
      block.dataset.date = String(date);
      block.dataset.startLabel = String(r.startLabel);
      block.dataset.endLabel = String(r.endLabel);
      block.dataset.titleText = String(what);
      block.dataset.userName = String(who);

      block.title = `${who} / ${what}\n${r.startLabel}〜${r.endLabel}`;

      lane.appendChild(block);
    }
  }
}

function rerenderSelectionBlocksForCurrentDate() {
  const lanes = document.querySelectorAll(".lane");
  if (lanes.length === 0) return;

  lanes.forEach(lane => {
    lane.querySelectorAll(".sel-block").forEach(b => b.remove());
  });

  const rootStyle = getComputedStyle(document.documentElement);
  const START_HOUR = Number(rootStyle.getPropertyValue("--start-hour").trim());
  const END_HOUR = Number(rootStyle.getPropertyValue("--end-hour").trim());

  const date = getSelectedDate();

  for (const lane of lanes) {
    const roomId = lane.dataset.roomId || "";
    const items = selections.filter(s => s.date === date && String(s.roomId) === String(roomId));

    for (const s of items) {
      const block = document.createElement("div");
      block.className = "block sel-block is-reserved";

      const left = minutesToPercent(s.from, START_HOUR, END_HOUR);
      const right = minutesToPercent(s.to, START_HOUR, END_HOUR);
      block.style.left = `${left}%`;
      block.style.width = `${right - left}%`;

      lane.appendChild(block);
    }
  }
}

/* =========================================================
   重なり判定
========================================================= */
function isOverlappingSelections(date, roomId, from, to) {
  return selections.some(s => {
    if (s.date !== date) return false;
    if (String(s.roomId) !== String(roomId)) return false;
    return rangesOverlap(s.from, s.to, from, to);
  });
}

function isOverlappingDbReservations(date, roomId, from, to) {
  const rootStyle = getComputedStyle(document.documentElement);
  const START_HOUR = Number(rootStyle.getPropertyValue("--start-hour").trim());

  const db = Array.isArray(window.__DB_RESERVATIONS__) ? window.__DB_RESERVATIONS__ : [];
  const relevant = db
    .map(r => normalizeDbReservationToRelativeMinutes(r, START_HOUR))
    .filter(x => x && String(x.date) === String(date) && String(x.roomId) === String(roomId));

  return relevant.some(r => rangesOverlap(r.from, r.to, from, to));
}

function rangesOverlap(a1, a2, b1, b2) {
  return Math.max(a1, b1) < Math.min(a2, b2);
}

/* =========================================================
   DB予約データ正規化（start_time/end_time → 相対分）
========================================================= */
function normalizeDbReservationToRelativeMinutes(r, START_HOUR) {
  if (!r) return null;

  const reservationId = r.reservation_id ?? r.reservationId ?? r.id;
  const roomId = r.classroom_id ?? r.room_id ?? r.roomId;
  const date = r.date ?? r.reservation_date ?? r.reservationDate;

  // ★追加：予約の所有者ID
  const userId = r.user_id ?? r.userId ?? null;
	// ★追加：ユーザー色（users.color）
	const userColor = r.user_color ?? r.userColor ?? r.usercolor ?? "";

  const userName = r.user_name ?? r.userName ?? "";
  const title = r.title ?? r.purpose ?? r.what ?? "";

  if (!reservationId || !roomId || !date) return null;

  const st = r.start_time ?? r.startTime;
  const et = r.end_time ?? r.endTime;
  if (!st || !et) return null;

  const startAbs = timeStringToMinutes(st);
  const endAbs = timeStringToMinutes(et);

  const base = START_HOUR * 60;
  const from = startAbs - base;
  const to = endAbs - base;

  const startLabel = minutesAbsToClockLabel(startAbs);
  const endLabel = minutesAbsToClockLabel(endAbs);

	// ★変更：返すオブジェクトに userId / userColor を含める
	return { reservationId, roomId, date, from, to, startLabel, endLabel, userName, title, userId, userColor };
}

function timeStringToMinutes(t) {
  const s = String(t);
  const m = s.match(/^(\d{1,2}):(\d{2})(?::(\d{2}))?$/);
  if (!m) return 0;
  const hh = Number(m[1]);
  const mm = Number(m[2]);
  return hh * 60 + mm;
}

function minutesAbsToClockLabel(absMinutes) {
  const h = Math.floor(absMinutes / 60);
  const m = absMinutes % 60;
  return `${h}:${String(m).padStart(2, "0")}`;
}

/* =========================================================
   時間ヘッダー
========================================================= */
function renderTimeHeader(container, START_HOUR, END_HOUR) {
  container.innerHTML = "";

  const hours = END_HOUR - START_HOUR;

  // wrapper
  const wrap = document.createElement("div");
  wrap.className = "timeline-header";

  // numbers row (outside of grid)
  const numbersRow = document.createElement("div");
  numbersRow.className = "timeline-hours";

  for (let i = 1; i < hours; i++) {
    const hour = START_HOUR + i;

    const el = document.createElement("div");
    el.className = "time-label";
    el.textContent = String(hour);

    const leftPct = (i / hours) * 100;
    el.style.left = `${leftPct}%`;

    // place numbers on exact boundary (00 minutes)
    // first = left aligned, last = right aligned, middle = centered on boundary
    if (i === 0) {
      el.style.transform = "translateX(0)";
    } else if (i === hours) {
      el.style.transform = "translateX(-100%)";
    } else {
      el.style.transform = "translateX(-50%)";
    }

    numbersRow.appendChild(el);
  }

  // axis strip (grid just under the numbers)
  const axisRow = document.createElement("div");
  axisRow.className = "timeline-axis";
  axisRow.setAttribute("aria-hidden", "true");

  wrap.appendChild(numbersRow);
  wrap.appendChild(axisRow);

  container.appendChild(wrap);
}


/* =========================================================
   util
========================================================= */
function getSelectedDate() {
  if (window.__SELECTED_DATE__ && /^\d{4}-\d{2}-\d{2}$/.test(window.__SELECTED_DATE__)) {
    return window.__SELECTED_DATE__;
  }
  const picker = document.getElementById("datePicker");
  if (picker && picker.value) return picker.value;
  const d = new Date();
  const y = d.getFullYear();
  const m = String(d.getMonth() + 1).padStart(2, "0");
  const dd = String(d.getDate()).padStart(2, "0");
  return `${y}-${m}-${dd}`;
}

function snapXToMinutes(x, width, START_HOUR, END_HOUR, SNAP_MINUTES) {
  const totalMinutes = (END_HOUR - START_HOUR) * 60;
  const minutes = (x / width) * totalMinutes;
  const snapped = Math.round(minutes / SNAP_MINUTES) * SNAP_MINUTES;
  return clamp(snapped, 0, totalMinutes);
}

function minutesToPercent(min, START_HOUR, END_HOUR) {
  const totalMinutes = (END_HOUR - START_HOUR) * 60;
  return (min / totalMinutes) * 100;
}

function updateBlock(state, currentMinutes, START_HOUR, END_HOUR) {
  const { from, to } = normalizeRange(state.startMinutes, currentMinutes);
  const left = minutesToPercent(from, START_HOUR, END_HOUR);
  const right = minutesToPercent(to, START_HOUR, END_HOUR);

  state.blockEl.style.left = `${left}%`;
  state.blockEl.style.width = `${right - left}%`;
}

function normalizeRange(a, b) {
  return a <= b ? { from: a, to: b } : { from: b, to: a };
}

function clamp(v, min, max) {
  return Math.max(min, Math.min(max, v));
}

function minutesToClock(relMinutes, START_HOUR) {
  const total = relMinutes + START_HOUR * 60;
  const h = Math.floor(total / 60);
  const m = total % 60;
  return `${h}:${String(m).padStart(2, "0")}`;
}

/* =========================================================
   Toast（権限なし等の軽い通知）
========================================================= */
let __toastTimer = null;

function showToast(message) {
  let el = document.getElementById("toast");
  if (!el) {
    el = document.createElement("div");
    el.id = "toast";
    el.className = "toast";
    el.setAttribute("role", "status");
    el.setAttribute("aria-live", "polite");
    document.body.appendChild(el);
  }

  el.textContent = String(message || "");
  el.classList.add("is-show");

  if (__toastTimer) clearTimeout(__toastTimer);
  __toastTimer = setTimeout(() => {
    el.classList.remove("is-show");
  }, 2200);
}
/* =========================================================
   Reservation Modal（予約詳細ポップアップ）
========================================================= */
function initReservationModal() {
  const modal = document.getElementById("reservationModal");
  if (!modal) return;

  // 閉じる（× / backdrop / 閉じるボタン）
  modal.addEventListener("click", (e) => {
    const t = e.target;
    if (!(t instanceof HTMLElement)) return;
    if (t.hasAttribute("data-modal-close")) {
      closeReservationModal();
    }
  });

  // Escで閉じる
  document.addEventListener("keydown", (e) => {
    if (e.key === "Escape") {
      const m = document.getElementById("reservationModal");
      if (m && !m.hidden) closeReservationModal();
    }
  });
}


// ★既存のDOMContentLoaded内に「initReservationModal();」を追加する
// どこでもいい（initReserveBarUI() の後とか）
/*
document.addEventListener("DOMContentLoaded", () => {
  ...
  initReservationModal();
  ...
});
*/

function openReservationModalFromBlock(blockEl) {
  const modal = document.getElementById("reservationModal");
  if (!modal) return;

  const room = blockEl.dataset.roomName || "（不明）";
  const date = blockEl.dataset.date || "（不明）";
  const time = `${blockEl.dataset.startLabel || "-"}〜${blockEl.dataset.endLabel || "-"}`;
  const titleText = blockEl.dataset.titleText || "（未入力）";
  const userName = blockEl.dataset.userName || "（未入力）";

  const canEdit = blockEl.dataset.canEdit === "1";
  const rid = blockEl.dataset.reservationId || "";

  const roomEl = document.getElementById("modalRoom");
  const dateEl = document.getElementById("modalDate");
  const timeEl = document.getElementById("modalTime");
  const titleEl = document.getElementById("modalTitleText");
  const userEl = document.getElementById("modalUser");
  const hintEl = document.getElementById("modalHint");
  const editBtn = document.getElementById("modalEditBtn");

  if (roomEl) roomEl.textContent = room;
  if (dateEl) dateEl.textContent = date;
  if (timeEl) timeEl.textContent = time;
  if (titleEl) titleEl.textContent = titleText;
  if (userEl) userEl.textContent = userName;

  if (hintEl) hintEl.hidden = canEdit; // 編集不可のときだけ出す

  if (editBtn) {
    if (canEdit && rid) {
      const back = window.location.href;
      const url = new URL("edit.php", window.location.href);
      url.searchParams.set("reservation_id", rid);
      url.searchParams.set("back", back);

      editBtn.href = url.toString();
      editBtn.hidden = false;
    } else {
      editBtn.hidden = true;
      editBtn.href = "#";
    }
  }

  modal.hidden = false;
}

function closeReservationModal() {
  const modal = document.getElementById("reservationModal");
  if (!modal) return;
  modal.hidden = true;
}
