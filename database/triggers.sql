-- Триггеры целостности ИС «ПосадДом». Операторы разделены строкой "--#".

-- История статусов неизменяема
CREATE TRIGGER trg_rsh_no_update BEFORE UPDATE ON request_status_history FOR EACH ROW
BEGIN
  SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'История статусов заявки не подлежит изменению';
END
--#
CREATE TRIGGER trg_rsh_no_delete BEFORE DELETE ON request_status_history FOR EACH ROW
BEGIN
  SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'История статусов заявки не подлежит удалению';
END
--#
-- Помещение должно принадлежать дому заявки (при создании)
CREATE TRIGGER trg_requests_premise_ins BEFORE INSERT ON requests FOR EACH ROW
BEGIN
  IF NEW.premise_id IS NOT NULL
     AND (SELECT house_id FROM premises WHERE id = NEW.premise_id) <> NEW.house_id THEN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Помещение не принадлежит выбранному дому';
  END IF;
END
--#
-- Закрытая заявка: менять можно только служебные поля (updated_at, is_overdue); помещение должно принадлежать дому
CREATE TRIGGER trg_requests_upd BEFORE UPDATE ON requests FOR EACH ROW
BEGIN
  IF OLD.status_id = (SELECT id FROM request_statuses WHERE code = 'closed') THEN
    IF NOT (NEW.request_number <=> OLD.request_number AND NEW.received_at <=> OLD.received_at
        AND NEW.client_id <=> OLD.client_id AND NEW.house_id <=> OLD.house_id AND NEW.premise_id <=> OLD.premise_id
        AND NEW.category_id <=> OLD.category_id AND NEW.service_id <=> OLD.service_id AND NEW.channel_id <=> OLD.channel_id
        AND NEW.status_id <=> OLD.status_id AND NEW.description <=> OLD.description AND NEW.assignee_id <=> OLD.assignee_id
        AND NEW.created_by <=> OLD.created_by AND NEW.manual_assign_required <=> OLD.manual_assign_required
        AND NEW.arrive_due <=> OLD.arrive_due AND NEW.due_at <=> OLD.due_at AND NEW.warn_at <=> OLD.warn_at
        AND NEW.warning_sent <=> OLD.warning_sent AND NEW.arrived_at <=> OLD.arrived_at
        AND NEW.completed_at <=> OLD.completed_at AND NEW.closed_at <=> OLD.closed_at) THEN
      SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Закрытая заявка не подлежит редактированию';
    END IF;
  END IF;
  IF NEW.premise_id IS NOT NULL
     AND (SELECT house_id FROM premises WHERE id = NEW.premise_id) <> NEW.house_id THEN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Помещение не принадлежит выбранному дому';
  END IF;
END
--#
-- После закрытия допускается только комментарий с пометкой о дополнительном обращении
CREATE TRIGGER trg_comments_closed BEFORE INSERT ON request_comments FOR EACH ROW
BEGIN
  IF NEW.is_additional_contact = 0
     AND (SELECT status_id FROM requests WHERE id = NEW.request_id) = (SELECT id FROM request_statuses WHERE code = 'closed') THEN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'К закрытой заявке можно добавить только комментарий о дополнительном обращении';
  END IF;
END
--#
-- Фото нельзя удалять раньше окончания срока хранения и пока заявка не закрыта
CREATE TRIGGER trg_photos_retention BEFORE DELETE ON request_photos FOR EACH ROW
BEGIN
  IF OLD.retention_until > CURDATE()
     OR (SELECT status_id FROM requests WHERE id = OLD.request_id) <> (SELECT id FROM request_statuses WHERE code = 'closed') THEN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Фото нельзя удалить раньше окончания срока хранения';
  END IF;
END
