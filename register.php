<?php
require __DIR__ . "/db.php";
require __DIR__ . "/auth.php";

function h($s)
{
  return htmlspecialchars((string)$s, ENT_QUOTES, "UTF-8");
}

$back = safe_back_url((string)($_GET["back"] ?? ""), "index.php");
$error = "";

if ($_SERVER["REQUEST_METHOD"] === "POST") {
  $username  = trim((string)($_POST["username"] ?? ""));
  $password  = (string)($_POST["password"] ?? "");
  $password2 = (string)($_POST["password2"] ?? "");
  $back = safe_back_url((string)($_POST["back"] ?? "index.php"), "index.php");

  if ($username === "" || $password === "" || $password2 === "") {
    $error = "未入力があります。";
  } elseif ($password !== $password2) {
    $error = "パスワードが一致しません。";
  } elseif (mb_strlen($username) > 50) {
    $error = "ユーザー名が長すぎます。";
  } else {
    try {
      $hash = password_hash($password, PASSWORD_DEFAULT);
      $stmt = $pdo->prepare("INSERT INTO users (username, password_hash) VALUES (?, ?)");
      $stmt->execute([$username, $hash]);

      $uid = (int)$pdo->lastInsertId();
      $_SESSION["user"] = [
        "user_id" => $uid,
        "username" => $username
      ];

      header("Location: " . $back);
      exit;
    } catch (Throwable $e) {
      $error = "登録できませんでした（ユーザー名が既に使われている可能性があります）。";
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
    <h1 class="title">新規登録</h1>

    <section class="card">

      <?php if ($error !== ""): ?>
        <p class="card-text" style="color:#b91c1c; font-weight:900;"><?= h($error) ?></p>
      <?php endif; ?>

      <form method="post" class="form-grid" style="margin-top:12px;">
        <input type="hidden" name="back" value="<?= h($back) ?>">

        <div class="field">
          <label for="username">ユーザー名</label>
          <input type="text" id="username" name="username" required>
        </div>

        <div class="field">
          <label for="password">パスワード</label>
          <input type="password" id="password" name="password" required>
        </div>

        <div class="field">
          <label for="password2">パスワード（確認）</label>
          <input type="password" id="password2" name="password2" required>
        </div>

        <div class="actions">
          <a class="clear-btn" href="login.php?back=<?= h(urlencode($back)) ?>" style="text-decoration:none; display:inline-flex; align-items:center; margin-right:14px;">
            戻る
          </a>
          <button type="submit" class="reserve-btn">登録してログイン</button>
        </div>
      </form>

    </section>
  </main>

  <footer class="app-footer">2026補習© 教室管理ナビゲーション-教ナビ</footer>
</body>

</html>