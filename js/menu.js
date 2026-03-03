// js/menu.js
document.addEventListener("DOMContentLoaded", () => {
  /* =========================
     Drawer（ハンバーガーメニュー）
  ========================= */
  const openBtn = document.getElementById("menuOpenBtn");
  const overlay = document.getElementById("drawerOverlay");
  const drawer = document.getElementById("drawerMenu");
  const closeBtns = document.querySelectorAll("[data-drawer-close]");

  const hasDrawer = !!(openBtn && overlay && drawer);

  function openDrawer() {
    if (!hasDrawer) return;

    drawer.classList.add("is-open");
    overlay.classList.add("is-open")
    overlay.classList.add("is-show");
    drawer.setAttribute("aria-hidden", "false");
    overlay.setAttribute("aria-hidden", "false");
    openBtn.setAttribute("aria-expanded", "true");

    // スクロール固定
    document.documentElement.classList.add("no-scroll");
    document.body.classList.add("no-scroll");
  }

  function closeDrawer() {
    if (!hasDrawer) return;

    drawer.classList.remove("is-open");
    overlay.classList.remove("is-open")
    overlay.classList.remove("is-show");
    drawer.setAttribute("aria-hidden", "true");
    overlay.setAttribute("aria-hidden", "true");
    openBtn.setAttribute("aria-expanded", "false");

    // スクロール固定解除
    document.documentElement.classList.remove("no-scroll");
    document.body.classList.remove("no-scroll");
  }

  if (hasDrawer) {
    openBtn.addEventListener("click", (e) => {
      e.preventDefault();
      const isOpen = drawer.classList.contains("is-open");
      if (isOpen) closeDrawer();
      else openDrawer();
    });

    // オーバーレイ（暗い背景）クリックで閉じる
    overlay.addEventListener("click", closeDrawer);

    // 閉じるボタン（×など）
    closeBtns.forEach((btn) => btn.addEventListener("click", closeDrawer));

    // どこか別の場所をクリックしたら閉じる（ドロワー内クリックは除外）
    document.addEventListener(
      "click",
      (e) => {
        if (!drawer.classList.contains("is-open")) return;

        const target = e.target;
        const clickedInsideDrawer = drawer.contains(target);
        const clickedOpenBtn = openBtn.contains(target);

        if (!clickedInsideDrawer && !clickedOpenBtn) {
          closeDrawer();
        }
      },
      true
    );

    // Esc で閉じる
    document.addEventListener("keydown", (e) => {
      if (e.key === "Escape" && drawer.classList.contains("is-open")) {
        closeDrawer();
      }
    });
  }

  /* =========================
     繰り返し予約（チェックで表示）
     - reserve.php の repeat_weekly / repeat_until に対応
  ========================= */
  const repeatCb = document.getElementById("repeat_weekly");
  const repeatBody = document.getElementById("repeatBody");
  const repeatField = document.getElementById("repeatField");
  const repeatUntil = document.getElementById("repeat_until");

  if (repeatCb && repeatBody && repeatField) {
    function syncRepeatUI() {
      const on = repeatCb.checked;

      repeatField.classList.toggle("is-on", on);
      repeatBody.setAttribute("aria-hidden", on ? "false" : "true");

      if (repeatUntil) {
        // 繰り返すなら終了日必須。OFFなら必須解除して値もクリア
        repeatUntil.required = on;
        if (!on) repeatUntil.value = "";
      }
    }

    repeatCb.addEventListener("change", syncRepeatUI);
    syncRepeatUI();
  }
});
