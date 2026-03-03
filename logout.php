<?php
require __DIR__ . "/auth.php";

if (session_status() === PHP_SESSION_NONE) {
  session_start();
}

// セッション破棄
$_SESSION = [];
session_destroy();

// next があればそこへ（安全化）
$next = (string)($_GET["next"] ?? "");
$next = safe_back_url(urldecode($next), "index.php");

header("Location: " . $next);
exit;
