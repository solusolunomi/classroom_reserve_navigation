<?php
// 共通ヘッダー（全ページ統一）
// index.php だけ日付表示を出したい場合は、呼び出し側で以下をセットする:
//   $header_show_date = true;
?>
<header class="app-header">
  <div class="header-left">
    <a href="index.php" class="header-title" aria-label="ホーム">
      <img src="images/logo.jpg" alt="教室予約システム" class="header-logo"
           onerror="this.style.display='none'; this.nextElementSibling.style.display='inline';">
      <span class="header-title-text" style="display:none;">教室予約システム</span>
    </a>
  </div>

  <?php if (!empty($header_show_date)): ?>
    <div class="header-center">
      <button type="button" class="date-btn prev" aria-label="前日">‹</button>

      <div class="date-display">
        <button type="button" class="date-trigger" id="dateTrigger" aria-label="日付を選択">
          <div class="date-year" id="yearText"></div>
          <div class="date-md" id="mdText"></div>
          <div class="date-week" id="weekText"></div>
        </button>

        <!-- クリック/prev/nextで値を更新し、URLのdateを更新してリロード（js/script.js） -->
        <input type="date" class="date-picker" id="datePicker" aria-label="日付">
      </div>

      <button type="button" class="date-btn next" aria-label="翌日">›</button>
    </div>
  <?php endif; ?>

  <div class="header-right">
    <button class="menu-btn" id="menuOpenBtn" type="button" aria-label="メニュー" aria-expanded="false">☰</button>
  </div>
</header>
