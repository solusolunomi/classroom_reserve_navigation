@-- 定期予約（A案：都度INSERT）を束ねるための列
-- 既に追加済みなら実行不要
ALTER TABLE reservation
  ADD COLUMN repeat_group_id BIGINT NULL AFTER end_time;

-- よく使う検索（教室×日×時間）
CREATE INDEX idx_reservation_room_date_time
  ON reservation (classroom_id, reservation_date, start_time, end_time);
