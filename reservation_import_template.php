<?php
// CSVテンプレートDL（予約一括登録用）

header('Content-Type: text/csv; charset=UTF-8');
header('Content-Disposition: attachment; filename="reservation_import_template.csv"');

// Excel対策：UTF-8 BOMを付ける（文字化け防止）
echo "\xEF\xBB\xBF";

/*
【列の説明】
reservation_date : 日付（YYYY-MM-DD）
classroom_name   : 教室名（例：A11）
start_time       : 開始時刻（HH:MM）
end_time         : 終了時刻（HH:MM）
title            : 利用用途（必須）
user_name        : 表示名（省略可）
username         : ユーザーID（管理者用・省略可）
*/

// ヘッダ（※ bulk_import.php に対応）
echo "reservation_date,classroom_name,start_time,end_time,title,user_name,username\n";

// サンプル①：一般利用（username空）
echo "2026-03-01,A11,10:00,12:00,会議,山田太郎,\n";

// サンプル②：管理者利用（username指定）
echo "2026-03-02,A11,13:00,15:00,勉強会,,yamada\n";
