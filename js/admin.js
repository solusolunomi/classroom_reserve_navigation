// js/admin.js
document.addEventListener("DOMContentLoaded", () => {
  const subtabs = document.getElementById("adminResvSubtabs");
  if (!subtabs) return;

  const buttons = Array.from(subtabs.querySelectorAll(".admin-subtab"));
  const panels = buttons
    .map((b) => document.getElementById(b.dataset.target))
    .filter(Boolean);

  const KEY = "admin.resv.subtab";

  function setActive(targetId, { save = true } = {}) {
    buttons.forEach((b) => b.classList.toggle("is-active", b.dataset.target === targetId));
    panels.forEach((p) => p.classList.toggle("is-active", p.id === targetId));
    if (save) {
      try { sessionStorage.setItem(KEY, targetId); } catch (_) {}
    }
    // パネル切り替え時に先頭に戻す（埋もれ防止）
    window.scrollTo({ top: 0, behavior: "instant" in window ? "instant" : "auto" });
  }

  // restore
  let initial = "adminResvList";
  try {
    const saved = sessionStorage.getItem(KEY);
    if (saved && document.getElementById(saved)) initial = saved;
  } catch (_) {}
  setActive(initial, { save: false });

  buttons.forEach((b) => {
    b.addEventListener("click", () => setActive(b.dataset.target));
  });
});
