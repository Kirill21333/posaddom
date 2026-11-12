-- Справочники и настройки ИС «ПосадДом»

INSERT INTO roles (code, name) VALUES
('dispatcher', 'Диспетчер'), ('employee', 'Сотрудник'), ('manager', 'Руководитель'), ('admin', 'Администратор');

INSERT INTO request_categories (code, name, prefix, response_minutes, response_work_days, resolve_hours, weight, calendar_time) VALUES
('emergency', 'Аварийная', 'А', 30, NULL, 24, 3, 1),
('urgent', 'Срочная', 'С', 120, NULL, 72, 2, 1),
('planned', 'Плановая', 'П', NULL, 1, 168, 1, 1);

INSERT INTO request_statuses (code, name, is_final, sort_order) VALUES
('new', 'Новая', 0, 1), ('accepted', 'Принята в работу', 0, 2), ('assigned', 'Назначен исполнитель', 0, 3),
('in_progress', 'Выполняется', 0, 4), ('done', 'Выполнена', 0, 5), ('closed', 'Закрыта', 1, 6),
('rejected', 'Отклонена', 1, 7), ('cancelled', 'Отменена', 1, 8);

INSERT INTO request_channels (code, name) VALUES
('phone', 'Телефон'), ('site', 'Сайт'), ('gis_gkh', 'ГИС ЖКХ'), ('email', 'Электронная почта'), ('in_person', 'Личное обращение');

INSERT INTO employee_statuses (code, name) VALUES
('active', 'Активен'), ('vacation', 'В отпуске'), ('sick', 'На больничном'), ('dismissed', 'Уволен');

INSERT INTO schedule_types (code, name) VALUES
('five_day', 'Пятидневная неделя'), ('shift', 'Сменный'), ('round_clock', 'Круглосуточный');

INSERT INTO client_statuses (code, name) VALUES
('owner', 'Собственник'), ('tenant', 'Наниматель'), ('representative', 'Представитель по доверенности');

INSERT INTO positions (name, is_executor) VALUES
('Слесарь-сантехник', 1), ('Электромонтёр', 1), ('Плотник', 1), ('Уборщик', 1), ('Мастер участка', 1),
('Диспетчер', 0), ('Главный инженер', 0), ('Системный администратор', 0);

INSERT INTO sections (name) VALUES ('Участок №1'), ('Участок №2'), ('Участок №3'), ('Участок №4'), ('Участок №5');

INSERT INTO settings (`key`, `value`, description) VALUES
('business_start', '09:00', 'Начало рабочего дня (для плановых заявок)'),
('business_end', '18:00', 'Окончание рабочего дня'),
('warn_percent', '25', 'Предупреждение, когда до срока устранения осталось менее N % времени'),
('login_max_attempts', '5', 'Число неудачных попыток входа до блокировки'),
('login_lock_minutes', '15', 'Длительность блокировки учётной записи, мин'),
('photo_retention_years', '3', 'Срок хранения фотографий заявок, лет'),
('default_max_load', '9', 'Предельная нагрузка исполнителя по умолчанию (сумма весов открытых заявок)'),
('integrations_enabled', '1', 'Внешние интеграции доступны (0 - имитация недоступности, сообщения копятся в очереди)'),
('photo_max_mb', '10', 'Максимальный размер фотографии, МБ');
