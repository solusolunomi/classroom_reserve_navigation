<?php

if (!function_exists("h")) {
  function h($s)
  {
    return htmlspecialchars((string)$s, ENT_QUOTES, "UTF-8");
  }
}

if (!function_exists("safe_back_url")) {
  function safe_back_url(string $back, string $fallback = "index.php"): string
  {
    return $fallback;
  }
}

$__back = safe_back_url($_SERVER["REQUEST_URI"] ?? "index.php", "index.php");
?>

<div class="drawer-overlay" id="drawerOverlay" aria-hidden="true"></div>

<aside class="drawer" id="drawerMenu" aria-hidden="true">
  <div class="drawer__header">
    <div class="drawer__title">メニュー</div>
    <button type="button" class="drawer__close" aria-label="閉じる" data-drawer-close>×</button>
  </div>

  <div class="drawer__body">
    <?php if (function_exists("is_logged_in") && is_logged_in()): ?>
      <div class="drawer__user">
        ログイン中：<?= h(function_exists("current_username") ? current_username() : "") ?>
      </div>

      <nav class="drawer__nav">
        <a class="drawer__link" href="my_reservations.php">
          <span>マイページ</span>
          <span class="sub">自分の予約一覧</span>
        </a>

        <a class="drawer__link" href="login.php?back=<?= h(urlencode($__back)) ?>">
          <span>ユーザー切り替え</span>
          <span class="sub">別アカでログイン</span>
        </a>

        <a class="drawer__link" href="logout.php?next=<?= h(urlencode("login.php?back=" . urlencode($__back))) ?>">
          <span>ログアウト</span>
          <span class="sub">サインアウト</span>
        </a>
      </nav>

      <?php if (function_exists("is_admin") && is_admin()): ?>
        <div style="margin-top:14px; padding-top:14px; border-top:1px solid #eef2f7;">
          <div style="font-weight:900; color:#0f172a; margin-bottom:10px;">
            管理者メニュー
          </div>

          <nav class="drawer__nav">
            <a class="drawer__link" href="admin.php">
              <span>管理者ページ</span>
              <span class="sub">教室 / ユーザ / 予約を一元管理</span>
            </a>
          </nav>
        </div>
      <?php endif; ?>

    <?php else: ?>
      <nav class="drawer__nav">
        <a class="drawer__link" href="login.php?back=<?= h(urlencode($__back)) ?>">
          <span>ログイン</span>
          <span class="sub">サインイン</span>
        </a>

        <a class="drawer__link" href="register.php?back=<?= h(urlencode($__back)) ?>">
          <span>新規登録</span>
          <span class="sub">アカウント作成</span>
        </a>
      </nav>
    <?php endif; ?>
  </div>
</aside>

<script src="js/menu.js"></script>