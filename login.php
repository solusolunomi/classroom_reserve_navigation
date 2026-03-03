<?php
require __DIR__ . "/db.php";
require __DIR__ . "/auth.php";

function h($s)
{
  return htmlspecialchars((string)$s, ENT_QUOTES, "UTF-8");
}

/*
  ★ここが重要：
  - どこから来てもログイン後は必ず index.php
  - back は「見た目（新規登録リンク等）」に残してもいいけど、遷移先には使わない
*/
$back = "index.php";
$error = "";

if ($_SERVER["REQUEST_METHOD"] === "POST") {
  $username = trim((string)($_POST["username"] ?? ""));
  $password = (string)($_POST["password"] ?? "");

  if ($username === "" || $password === "") {
    $error = "ユーザー名とパスワードを入力してください。";
  } else {
    // ★roleカラムは無い前提：is_admin / is_user を見る
    $stmt = $pdo->prepare("
      SELECT user_id, username, password_hash, is_admin, is_user
      FROM users
      WHERE username = ?
      LIMIT 1
    ");
    $stmt->execute([$username]);
    $u = $stmt->fetch(PDO::FETCH_ASSOC);

    if ($u && password_verify($password, (string)$u["password_hash"])) {

      // ★停止中ユーザはログイン不可（is_user=0）
      if (isset($u["is_user"]) && (int)$u["is_user"] === 0) {
        $error = "このアカウントは停止されています。";
      } else {
        // ★管理者判定：is_admin=1 なら role=admin にする
        $role = (!empty($u["is_admin"]) && (int)$u["is_admin"] === 1) ? "admin" : "user";

        $_SESSION["user"] = [
          "user_id" => $u["user_id"],
          "username" => $u["username"],
          "role" => $role
        ];

        // ★必ず index.php に戻す
        header("Location: index.php");
        exit;
      }
    } else {
      $error = "ユーザー名またはパスワードが違います。";
    }
  }
}
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
    <h1 class="title">ログイン</h1>

    <section class="card">

      <?php if ($error !== ""): ?>
        <p class="card-text" style="color:#b91c1c; font-weight:900;"><?= h($error) ?></p>
      <?php endif; ?>

      <form method="post" class="form-grid" style="margin-top:12px;">
        <!-- backは使わない（ログイン後は必ずindexへ） -->
        <input type="hidden" name="back" value="index.php">

        <div class="field">
          <label for="username">ユーザー名</label>
          <input type="text" id="username" name="username" required>
        </div>

        <div class="field">
          <label for="password">パスワード</label>
          <input type="password" id="password" name="password" required>
        </div>

        <div class="actions">
          <a class="clear-btn" href="register.php?back=<?= h(urlencode($back)) ?>" style="text-decoration:none; display:inline-flex; align-items:center; margin-right:36px;">
            新規登録
          </a>
          <button type="submit" class="reserve-btn">ログイン</button>
        </div>
      </form>

    </section>
  </main>

  <footer class="app-footer">2026補習© 教室管理ナビゲーション-教ナビ</footer>
</body>

</html>