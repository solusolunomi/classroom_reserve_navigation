<?php
// auth.php
if (session_status() === PHP_SESSION_NONE) {
  session_start();
}



function is_logged_in(): bool {
  return isset($_SESSION["user"]) && is_array($_SESSION["user"]) && isset($_SESSION["user"]["user_id"]);
}

function current_user_id(): ?int {
  return is_logged_in() ? (int)$_SESSION["user"]["user_id"] : null;
}

function current_username(): string {
  return is_logged_in() ? (string)($_SESSION["user"]["username"] ?? "") : "";
}

function require_login(): void {
  if (!is_logged_in()) {
    $back = $_SERVER["REQUEST_URI"] ?? "index.php";
    header("Location: login.php?back=" . urlencode($back));
    exit;
  }
}

/**
 * back を安全化（同一サイト内の相対パスだけ許可）
 * - index.php?... のようなものだけ通す
 */
function safe_back_url(string $back, string $fallback = "index.php"): string {
  $back = trim($back);
  if ($back === "") return $fallback;

  // 絶対URLや // から始まるものは拒否
  if (preg_match('#^(https?:)?//#i', $back)) return $fallback;

  // 改行混入対策
  $back = str_replace(["\r", "\n"], "", $back);

  // このプロジェクトは基本 index.php / edit.php など同階層なので、
  // 先頭が英数字/._- で、途中に "://" がなければOKにする（厳しめ）
  if (!preg_match('#^[a-zA-Z0-9._/-]+(\?.*)?$#', $back)) return $fallback;
  if (strpos($back, "://") !== false) return $fallback;

  return $back;
}

/**
 * 所有者チェック（違ったら403で止める）
 */
function require_owner_or_403(?int $ownerUserId): void {
  if (is_admin()) return;

  $me = current_user_id();
  if ($me === null || $ownerUserId === null || (int)$ownerUserId !== (int)$me) {
    http_response_code(403);
    exit("権限がありません");
  }
}

function is_admin(): bool {
  return is_logged_in() && (string)($_SESSION["user"]["role"] ?? "user") === "admin";
}
function require_admin(): void {
  require_login();
  if (!is_admin()) {
    http_response_code(403);
    exit("管理者権限がありません");
  }
}
