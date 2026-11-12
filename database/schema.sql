-- ИС «ПосадДом»: схема базы данных posaddom (35 таблиц, MariaDB 10.4+ / MySQL 8, InnoDB, utf8mb4).
-- Сгенерировано по модели model.py; триггеры, представления и справочники лежат в отдельных файлах.

-- Справочник ролей доступа: диспетчер, сотрудник, руководитель, администратор.
CREATE TABLE `roles` (
  `id` TINYINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `code` VARCHAR(20) NOT NULL,
  `name` VARCHAR(50) NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_roles_code` (`code`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Бизнес-параметры «ключ - значение»: рабочие часы, порог предупреждения (25 %), срок хранения фото (3 года), число попыток входа и др.
CREATE TABLE `settings` (
  `key` VARCHAR(64) NOT NULL,
  `value` VARCHAR(255) NOT NULL,
  `description` VARCHAR(255) NULL DEFAULT NULL,
  PRIMARY KEY (`key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Справочник должностей: слесарь-сантехник, электромонтёр, плотник, уборщик, мастер участка, диспетчер.
CREATE TABLE `positions` (
  `id` SMALLINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `name` VARCHAR(80) NOT NULL,
  `is_executor` TINYINT(1) NOT NULL DEFAULT 1,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_positions_name` (`name`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Справочник статусов сотрудника: активен, в отпуске, на больничном, уволен.
CREATE TABLE `employee_statuses` (
  `id` TINYINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `code` VARCHAR(20) NOT NULL,
  `name` VARCHAR(50) NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_employee_statuses_code` (`code`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Подрядные организации, сотрудники которых участвуют в выполнении заявок.
CREATE TABLE `contractors` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `name` VARCHAR(150) NOT NULL,
  `inn` VARCHAR(12) NOT NULL,
  `phone` VARCHAR(20) NULL DEFAULT NULL,
  `is_active` TINYINT(1) NOT NULL DEFAULT 1,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_contractors_inn` (`inn`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Типы графиков: стандартная пятидневная неделя, сменный (день и ночь), круглосуточный для аварийно-диспетчерской службы.
CREATE TABLE `schedule_types` (
  `id` TINYINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `code` VARCHAR(20) NOT NULL,
  `name` VARCHAR(60) NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_schedule_types_code` (`code`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Карточки сотрудников УК и подрядных организаций: ФИО, должность, статус, тип графика, контакты, согласие на обработку персональных данных.
CREATE TABLE `employees` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `last_name` VARCHAR(50) NOT NULL,
  `first_name` VARCHAR(50) NOT NULL,
  `middle_name` VARCHAR(50) NULL DEFAULT NULL,
  `position_id` SMALLINT UNSIGNED NOT NULL,
  `status_id` TINYINT UNSIGNED NOT NULL,
  `schedule_type_id` TINYINT UNSIGNED NOT NULL,
  `contractor_id` INT UNSIGNED NULL DEFAULT NULL,
  `phone` VARCHAR(20) NOT NULL,
  `email` VARCHAR(255) NULL DEFAULT NULL,
  `hire_date` DATE NOT NULL,
  `dismissal_date` DATE NULL DEFAULT NULL,
  `pd_consent_at` DATETIME NULL DEFAULT NULL,
  `created_at` DATETIME NOT NULL,
  PRIMARY KEY (`id`),
  KEY `ix_employees_name` (`last_name`, `first_name`),
  KEY `ix_employees_status` (`status_id`),
  CONSTRAINT `fk_employees_position_id` FOREIGN KEY (`position_id`) REFERENCES `positions` (`id`),
  CONSTRAINT `fk_employees_status_id` FOREIGN KEY (`status_id`) REFERENCES `employee_statuses` (`id`),
  CONSTRAINT `fk_employees_schedule_type_id` FOREIGN KEY (`schedule_type_id`) REFERENCES `schedule_types` (`id`),
  CONSTRAINT `fk_employees_contractor_id` FOREIGN KEY (`contractor_id`) REFERENCES `contractors` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Недельный шаблон графика: рабочие дни, начало и окончание смены, перерыв.
CREATE TABLE `work_schedules` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `employee_id` INT UNSIGNED NOT NULL,
  `weekday` TINYINT UNSIGNED NOT NULL COMMENT '1 = понедельник ... 7 = воскресенье',
  `shift_type` ENUM('day','night','full') NOT NULL DEFAULT 'day',
  `start_time` TIME NOT NULL,
  `end_time` TIME NOT NULL,
  `break_start` TIME NULL DEFAULT NULL,
  `break_end` TIME NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_work_schedules_day` (`employee_id`, `weekday`),
  CONSTRAINT `ck_work_schedules_weekday` CHECK (`weekday` BETWEEN 1 AND 7),
  CONSTRAINT `fk_work_schedules_employee_id` FOREIGN KEY (`employee_id`) REFERENCES `employees` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Исключения из графика на конкретную дату: отпуск, больничный, подмена, выходной или особые часы (уникальная пара employee_id + exception_date).
CREATE TABLE `schedule_exceptions` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `employee_id` INT UNSIGNED NOT NULL,
  `exception_date` DATE NOT NULL,
  `start_time` TIME NULL DEFAULT NULL,
  `end_time` TIME NULL DEFAULT NULL,
  `is_day_off` TINYINT(1) NOT NULL DEFAULT 0,
  `reason` VARCHAR(255) NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_schedule_exceptions_date` (`employee_id`, `exception_date`),
  CONSTRAINT `fk_schedule_exceptions_employee_id` FOREIGN KEY (`employee_id`) REFERENCES `employees` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Справочник статусов клиента: собственник, наниматель, представитель по доверенности.
CREATE TABLE `client_statuses` (
  `id` TINYINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `code` VARCHAR(20) NOT NULL,
  `name` VARCHAR(60) NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_client_statuses_code` (`code`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Справочник клиентов: ФИО, телефон, e-mail, статус, согласие на уведомления и на обработку персональных данных.
CREATE TABLE `clients` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `last_name` VARCHAR(50) NOT NULL,
  `first_name` VARCHAR(50) NOT NULL,
  `middle_name` VARCHAR(50) NULL DEFAULT NULL,
  `phone` VARCHAR(20) NOT NULL,
  `email` VARCHAR(255) NULL DEFAULT NULL,
  `status_id` TINYINT UNSIGNED NOT NULL,
  `notify_consent` TINYINT(1) NOT NULL DEFAULT 0,
  `pd_consent_at` DATETIME NOT NULL,
  `created_at` DATETIME NOT NULL,
  PRIMARY KEY (`id`),
  KEY `ix_clients_phone` (`phone`),
  KEY `ix_clients_name` (`last_name`, `first_name`),
  CONSTRAINT `fk_clients_status_id` FOREIGN KEY (`status_id`) REFERENCES `client_statuses` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Иерархический справочник категорий услуг (самоссылка parent_id): сантехнические, электромонтажные, столярные работы, уборка, благоустройство.
CREATE TABLE `service_categories` (
  `id` SMALLINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `parent_id` SMALLINT UNSIGNED NULL DEFAULT NULL,
  `name` VARCHAR(100) NOT NULL,
  PRIMARY KEY (`id`),
  CONSTRAINT `fk_service_categories_parent_id` FOREIGN KEY (`parent_id`) REFERENCES `service_categories` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Справочник услуг: код, наименование, единица измерения, нормативный срок, признак платности, стоимость, основание или нормативный документ.
CREATE TABLE `services` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `code` VARCHAR(20) NOT NULL,
  `category_id` SMALLINT UNSIGNED NOT NULL,
  `name` VARCHAR(200) NOT NULL,
  `description` TEXT NULL DEFAULT NULL,
  `unit` VARCHAR(20) NOT NULL,
  `norm_hours` DECIMAL(5,1) NOT NULL,
  `is_paid` TINYINT(1) NOT NULL DEFAULT 0,
  `price` DECIMAL(10,2) NULL DEFAULT NULL,
  `basis` VARCHAR(255) NULL DEFAULT NULL,
  `norm_document` VARCHAR(255) NULL DEFAULT NULL,
  `is_archived` TINYINT(1) NOT NULL DEFAULT 0,
  `created_at` DATETIME NOT NULL,
  `updated_at` DATETIME NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_services_code` (`code`),
  KEY `ix_services_category` (`category_id`),
  KEY `ix_services_archived` (`is_archived`),
  CONSTRAINT `ck_services_price` CHECK (is_paid=0 OR price IS NOT NULL),
  CONSTRAINT `fk_services_category_id` FOREIGN KEY (`category_id`) REFERENCES `service_categories` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Справочник категорий заявок с нормативами сроков: аварийная, срочная, плановая; префикс номера и вес для автоназначения.
CREATE TABLE `request_categories` (
  `id` TINYINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `code` VARCHAR(20) NOT NULL,
  `name` VARCHAR(50) NOT NULL,
  `prefix` CHAR(1) NOT NULL,
  `response_minutes` SMALLINT UNSIGNED NULL DEFAULT NULL,
  `response_work_days` TINYINT UNSIGNED NULL DEFAULT NULL,
  `resolve_hours` SMALLINT UNSIGNED NOT NULL,
  `weight` TINYINT UNSIGNED NOT NULL,
  `calendar_time` TINYINT(1) NOT NULL DEFAULT 1,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_request_categories_code` (`code`),
  CONSTRAINT `ck_request_categories_resp` CHECK (`response_minutes` IS NOT NULL OR `response_work_days` IS NOT NULL)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Справочник из восьми статусов заявки: новая, принята в работу, назначен исполнитель, выполняется, выполнена, закрыта, отклонена, отменена.
CREATE TABLE `request_statuses` (
  `id` TINYINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `code` VARCHAR(20) NOT NULL,
  `name` VARCHAR(50) NOT NULL,
  `is_final` TINYINT(1) NOT NULL DEFAULT 0,
  `sort_order` TINYINT UNSIGNED NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_request_statuses_code` (`code`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Справочник каналов обращения: телефон, сайт, ГИС ЖКХ, электронная почта, личное обращение.
CREATE TABLE `request_channels` (
  `id` TINYINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `code` VARCHAR(20) NOT NULL,
  `name` VARCHAR(60) NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_request_channels_code` (`code`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Счётчик сквозной нумерации заявок по календарным годам (обновляется в транзакции).
CREATE TABLE `request_number_seq` (
  `year` SMALLINT UNSIGNED NOT NULL,
  `last_value` INT UNSIGNED NOT NULL DEFAULT 0,
  PRIMARY KEY (`year`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Учётные записи пользователей системы (логин, хеш пароля, роль, счётчик неудачных входов и срок блокировки); привязаны к сотрудникам.
CREATE TABLE `users` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `login` VARCHAR(50) NOT NULL,
  `password_hash` VARCHAR(255) NOT NULL,
  `role_id` TINYINT UNSIGNED NOT NULL,
  `employee_id` INT UNSIGNED NULL DEFAULT NULL,
  `email` VARCHAR(255) NULL DEFAULT NULL,
  `is_active` TINYINT(1) NOT NULL DEFAULT 1,
  `failed_attempts` TINYINT UNSIGNED NOT NULL DEFAULT 0,
  `locked_until` DATETIME NULL DEFAULT NULL,
  `last_login_at` DATETIME NULL DEFAULT NULL,
  `must_change_password` TINYINT(1) NOT NULL DEFAULT 0,
  `created_at` DATETIME NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_users_login` (`login`),
  UNIQUE KEY `uq_users_employee_id` (`employee_id`),
  KEY `ix_users_role` (`role_id`),
  CONSTRAINT `fk_users_role_id` FOREIGN KEY (`role_id`) REFERENCES `roles` (`id`),
  CONSTRAINT `fk_users_employee_id` FOREIGN KEY (`employee_id`) REFERENCES `employees` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Журнал аудита: кто, когда и какое действие выполнил (вход, правка заявки, смена справочников, выгрузка отчётов).
CREATE TABLE `audit_log` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `user_id` INT UNSIGNED NULL DEFAULT NULL,
  `action` VARCHAR(50) NOT NULL,
  `entity_type` VARCHAR(40) NOT NULL,
  `entity_id` INT UNSIGNED NULL DEFAULT NULL,
  `old_value` TEXT NULL DEFAULT NULL,
  `new_value` TEXT NULL DEFAULT NULL,
  `ip_address` VARCHAR(45) NULL DEFAULT NULL,
  `created_at` DATETIME NOT NULL,
  PRIMARY KEY (`id`),
  KEY `ix_audit_created` (`created_at`),
  KEY `ix_audit_user` (`user_id`, `created_at`),
  CONSTRAINT `fk_audit_log_user_id` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Участки обслуживания (территориальные зоны) с мастером участка.
CREATE TABLE `sections` (
  `id` SMALLINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `name` VARCHAR(80) NOT NULL,
  `master_id` INT UNSIGNED NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_sections_name` (`name`),
  CONSTRAINT `fk_sections_master_id` FOREIGN KEY (`master_id`) REFERENCES `employees` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Специализация сотрудника: связь «многие ко многим» между сотрудниками и услугами, которые он вправе выполнять.
CREATE TABLE `employee_services` (
  `employee_id` INT UNSIGNED NOT NULL,
  `service_id` INT UNSIGNED NOT NULL,
  PRIMARY KEY (`employee_id`, `service_id`),
  CONSTRAINT `fk_employee_services_employee_id` FOREIGN KEY (`employee_id`) REFERENCES `employees` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_employee_services_service_id` FOREIGN KEY (`service_id`) REFERENCES `services` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Многоквартирные дома, находящиеся в управлении УК: адрес, участок, этажность, число подъездов и помещений.
CREATE TABLE `houses` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `section_id` SMALLINT UNSIGNED NOT NULL,
  `address` VARCHAR(255) NOT NULL,
  `floors` TINYINT UNSIGNED NOT NULL,
  `entrances` TINYINT UNSIGNED NOT NULL,
  `premises_count` SMALLINT UNSIGNED NOT NULL,
  `year_built` SMALLINT UNSIGNED NULL DEFAULT NULL,
  `is_active` TINYINT(1) NOT NULL DEFAULT 1,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_houses_address` (`address`),
  KEY `ix_houses_section` (`section_id`),
  CONSTRAINT `fk_houses_section_id` FOREIGN KEY (`section_id`) REFERENCES `sections` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Помещения дома: квартиры и нежилые помещения.
CREATE TABLE `premises` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `house_id` INT UNSIGNED NOT NULL,
  `number` VARCHAR(10) NOT NULL,
  `type` ENUM('residential','non_residential') NOT NULL DEFAULT 'residential',
  `area` DECIMAL(7,2) NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_premises_house_number` (`house_id`, `number`),
  CONSTRAINT `fk_premises_house_id` FOREIGN KEY (`house_id`) REFERENCES `houses` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Связь «многие ко многим» между клиентами и помещениями (у клиента может быть несколько помещений, у помещения - несколько собственников).
CREATE TABLE `client_premises` (
  `client_id` INT UNSIGNED NOT NULL,
  `premise_id` INT UNSIGNED NOT NULL,
  `since_date` DATE NULL DEFAULT NULL,
  PRIMARY KEY (`client_id`, `premise_id`),
  CONSTRAINT `fk_client_premises_client_id` FOREIGN KEY (`client_id`) REFERENCES `clients` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_client_premises_premise_id` FOREIGN KEY (`premise_id`) REFERENCES `premises` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Журнал изменений справочника услуг: дата, время, сотрудник, поле, прежнее и новое значение.
CREATE TABLE `service_changes` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `service_id` INT UNSIGNED NOT NULL,
  `changed_by` INT UNSIGNED NOT NULL,
  `changed_at` DATETIME NOT NULL,
  `field_name` VARCHAR(40) NOT NULL,
  `old_value` VARCHAR(255) NULL DEFAULT NULL,
  `new_value` VARCHAR(255) NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  CONSTRAINT `fk_service_changes_service_id` FOREIGN KEY (`service_id`) REFERENCES `services` (`id`),
  CONSTRAINT `fk_service_changes_changed_by` FOREIGN KEY (`changed_by`) REFERENCES `users` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Основная таблица: заявки на техническое обслуживание и текущий ремонт.
CREATE TABLE `requests` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `request_number` VARCHAR(20) NOT NULL,
  `received_at` DATETIME NOT NULL,
  `client_id` INT UNSIGNED NOT NULL,
  `house_id` INT UNSIGNED NOT NULL,
  `premise_id` INT UNSIGNED NULL DEFAULT NULL,
  `category_id` TINYINT UNSIGNED NOT NULL,
  `service_id` INT UNSIGNED NOT NULL,
  `channel_id` TINYINT UNSIGNED NOT NULL,
  `status_id` TINYINT UNSIGNED NOT NULL,
  `description` TEXT NOT NULL,
  `assignee_id` INT UNSIGNED NULL DEFAULT NULL,
  `created_by` INT UNSIGNED NOT NULL,
  `manual_assign_required` TINYINT(1) NOT NULL DEFAULT 0,
  `arrive_due` DATETIME NOT NULL,
  `due_at` DATETIME NOT NULL,
  `warn_at` DATETIME NOT NULL,
  `warning_sent` TINYINT(1) NOT NULL DEFAULT 0,
  `arrived_at` DATETIME NULL DEFAULT NULL,
  `completed_at` DATETIME NULL DEFAULT NULL,
  `closed_at` DATETIME NULL DEFAULT NULL,
  `is_overdue` TINYINT(1) NOT NULL DEFAULT 0,
  `updated_at` DATETIME NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_requests_request_number` (`request_number`),
  KEY `ix_requests_status_due` (`status_id`, `due_at`),
  KEY `ix_requests_assignee` (`assignee_id`, `status_id`),
  KEY `ix_requests_house` (`house_id`),
  KEY `ix_requests_received` (`received_at`),
  KEY `ix_requests_client` (`client_id`),
  KEY `ix_requests_queue` (`manual_assign_required`, `status_id`),
  KEY `ix_requests_overdue` (`is_overdue`),
  CONSTRAINT `ck_requests_due` CHECK (`due_at` >= `received_at`),
  CONSTRAINT `fk_requests_client_id` FOREIGN KEY (`client_id`) REFERENCES `clients` (`id`),
  CONSTRAINT `fk_requests_house_id` FOREIGN KEY (`house_id`) REFERENCES `houses` (`id`),
  CONSTRAINT `fk_requests_premise_id` FOREIGN KEY (`premise_id`) REFERENCES `premises` (`id`),
  CONSTRAINT `fk_requests_category_id` FOREIGN KEY (`category_id`) REFERENCES `request_categories` (`id`),
  CONSTRAINT `fk_requests_service_id` FOREIGN KEY (`service_id`) REFERENCES `services` (`id`),
  CONSTRAINT `fk_requests_channel_id` FOREIGN KEY (`channel_id`) REFERENCES `request_channels` (`id`),
  CONSTRAINT `fk_requests_status_id` FOREIGN KEY (`status_id`) REFERENCES `request_statuses` (`id`),
  CONSTRAINT `fk_requests_assignee_id` FOREIGN KEY (`assignee_id`) REFERENCES `employees` (`id`),
  CONSTRAINT `fk_requests_created_by` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Неизменяемая история статусов заявки (дата, время, сотрудник); запись и удаление запрещены триггером.
CREATE TABLE `request_status_history` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `request_id` INT UNSIGNED NOT NULL,
  `status_id` TINYINT UNSIGNED NOT NULL,
  `changed_by` INT UNSIGNED NULL DEFAULT NULL,
  `changed_at` DATETIME NOT NULL,
  `comment` VARCHAR(255) NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `ix_rsh_request` (`request_id`, `changed_at`),
  CONSTRAINT `fk_request_status_history_request_id` FOREIGN KEY (`request_id`) REFERENCES `requests` (`id`),
  CONSTRAINT `fk_request_status_history_status_id` FOREIGN KEY (`status_id`) REFERENCES `request_statuses` (`id`),
  CONSTRAINT `fk_request_status_history_changed_by` FOREIGN KEY (`changed_by`) REFERENCES `users` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- История назначений исполнителей: автоматическое или ручное, признак назначения вне графика, причина переназначения.
CREATE TABLE `request_assignments` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `request_id` INT UNSIGNED NOT NULL,
  `employee_id` INT UNSIGNED NOT NULL,
  `assigned_by` INT UNSIGNED NULL DEFAULT NULL,
  `mode` ENUM('auto','manual') NOT NULL DEFAULT 'auto',
  `off_schedule` TINYINT(1) NOT NULL DEFAULT 0,
  `assigned_at` DATETIME NOT NULL,
  `unassigned_at` DATETIME NULL DEFAULT NULL,
  `reason` VARCHAR(255) NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `ix_ra_request` (`request_id`, `assigned_at`),
  KEY `ix_ra_employee` (`employee_id`, `assigned_at`),
  CONSTRAINT `fk_request_assignments_request_id` FOREIGN KEY (`request_id`) REFERENCES `requests` (`id`),
  CONSTRAINT `fk_request_assignments_employee_id` FOREIGN KEY (`employee_id`) REFERENCES `employees` (`id`),
  CONSTRAINT `fk_request_assignments_assigned_by` FOREIGN KEY (`assigned_by`) REFERENCES `users` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Комментарии сотрудников; после закрытия заявки допускается только комментарий с пометкой о дополнительном обращении.
CREATE TABLE `request_comments` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `request_id` INT UNSIGNED NOT NULL,
  `author_id` INT UNSIGNED NOT NULL,
  `text` TEXT NOT NULL,
  `is_additional_contact` TINYINT(1) NOT NULL DEFAULT 0,
  `created_at` DATETIME NOT NULL,
  PRIMARY KEY (`id`),
  KEY `ix_rc_request` (`request_id`, `created_at`),
  CONSTRAINT `fk_request_comments_request_id` FOREIGN KEY (`request_id`) REFERENCES `requests` (`id`),
  CONSTRAINT `fk_request_comments_author_id` FOREIGN KEY (`author_id`) REFERENCES `users` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Фотоматериалы заявки: исходное состояние и подтверждение выполнения; файл на сервере, в БД - путь, дата, автор и срок хранения.
CREATE TABLE `request_photos` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `request_id` INT UNSIGNED NOT NULL,
  `uploaded_by` INT UNSIGNED NOT NULL,
  `stage` ENUM('initial','final') NOT NULL DEFAULT 'initial',
  `file_name` VARCHAR(255) NOT NULL,
  `file_path` VARCHAR(255) NOT NULL,
  `file_size` INT UNSIGNED NOT NULL,
  `uploaded_at` DATETIME NOT NULL,
  `retention_until` DATE NOT NULL,
  PRIMARY KEY (`id`),
  KEY `ix_rp_request` (`request_id`),
  KEY `ix_rp_retention` (`retention_until`),
  CONSTRAINT `fk_request_photos_request_id` FOREIGN KEY (`request_id`) REFERENCES `requests` (`id`),
  CONSTRAINT `fk_request_photos_uploaded_by` FOREIGN KEY (`uploaded_by`) REFERENCES `users` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Правила автоматического назначения, заданные администратором: категории, участок, приоритет, предельная нагрузка, допуск вне графика.
CREATE TABLE `assignment_rules` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `name` VARCHAR(100) NOT NULL,
  `request_category_id` TINYINT UNSIGNED NULL DEFAULT NULL,
  `service_category_id` SMALLINT UNSIGNED NULL DEFAULT NULL,
  `section_id` SMALLINT UNSIGNED NULL DEFAULT NULL,
  `priority` TINYINT UNSIGNED NOT NULL DEFAULT 10,
  `max_load` TINYINT UNSIGNED NOT NULL,
  `allow_off_schedule` TINYINT(1) NOT NULL DEFAULT 0,
  `is_active` TINYINT(1) NOT NULL DEFAULT 1,
  PRIMARY KEY (`id`),
  CONSTRAINT `fk_assignment_rules_request_category_id` FOREIGN KEY (`request_category_id`) REFERENCES `request_categories` (`id`),
  CONSTRAINT `fk_assignment_rules_service_category_id` FOREIGN KEY (`service_category_id`) REFERENCES `service_categories` (`id`),
  CONSTRAINT `fk_assignment_rules_section_id` FOREIGN KEY (`section_id`) REFERENCES `sections` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Начисления по платным услугам (одно начисление на заявку) для отчёта по платным услугам.
CREATE TABLE `charges` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `request_id` INT UNSIGNED NOT NULL,
  `service_id` INT UNSIGNED NOT NULL,
  `amount` DECIMAL(10,2) NOT NULL,
  `basis` VARCHAR(255) NOT NULL,
  `status` ENUM('accrued','cancelled') NOT NULL DEFAULT 'accrued',
  `created_by` INT UNSIGNED NOT NULL,
  `created_at` DATETIME NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_charges_request_id` (`request_id`),
  KEY `ix_charges_created` (`created_at`),
  CONSTRAINT `fk_charges_request_id` FOREIGN KEY (`request_id`) REFERENCES `requests` (`id`),
  CONSTRAINT `fk_charges_service_id` FOREIGN KEY (`service_id`) REFERENCES `services` (`id`),
  CONSTRAINT `fk_charges_created_by` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Уведомления сотрудникам и клиентам: новая заявка, смена статуса, приближение срока, просрочка, SMS и e-mail клиенту.
CREATE TABLE `notifications` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `recipient_type` ENUM('employee','client') NOT NULL,
  `employee_id` INT UNSIGNED NULL DEFAULT NULL,
  `client_id` INT UNSIGNED NULL DEFAULT NULL,
  `request_id` INT UNSIGNED NULL DEFAULT NULL,
  `channel` ENUM('site','email','messenger','sms') NOT NULL,
  `type` VARCHAR(40) NOT NULL,
  `message` TEXT NOT NULL,
  `status` ENUM('new','sent','failed','read') NOT NULL DEFAULT 'new',
  `created_at` DATETIME NOT NULL,
  `sent_at` DATETIME NULL DEFAULT NULL,
  `read_at` DATETIME NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `ix_notif_employee` (`employee_id`, `channel`, `status`),
  KEY `ix_notif_client` (`client_id`),
  KEY `ix_notif_request` (`request_id`),
  CONSTRAINT `fk_notifications_employee_id` FOREIGN KEY (`employee_id`) REFERENCES `employees` (`id`),
  CONSTRAINT `fk_notifications_client_id` FOREIGN KEY (`client_id`) REFERENCES `clients` (`id`),
  CONSTRAINT `fk_notifications_request_id` FOREIGN KEY (`request_id`) REFERENCES `requests` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Очередь обмена с внешними системами (ГИС ЖКХ, РСО, SMS-шлюз, почта): сохраняет сообщения при недоступности сервиса и повторяет отправку.
CREATE TABLE `integration_queue` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `system` ENUM('gis_gkh','rso','sms','email','messenger') NOT NULL,
  `request_id` INT UNSIGNED NULL DEFAULT NULL,
  `direction` ENUM('out','in') NOT NULL,
  `payload` TEXT NOT NULL,
  `status` ENUM('pending','sent','error') NOT NULL DEFAULT 'pending',
  `attempts` TINYINT UNSIGNED NOT NULL DEFAULT 0,
  `last_error` VARCHAR(255) NULL DEFAULT NULL,
  `next_try_at` DATETIME NULL DEFAULT NULL,
  `created_at` DATETIME NOT NULL,
  `processed_at` DATETIME NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `ix_queue_status` (`status`, `next_try_at`),
  KEY `ix_queue_request` (`request_id`),
  CONSTRAINT `fk_integration_queue_request_id` FOREIGN KEY (`request_id`) REFERENCES `requests` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Привязка сотрудника к объектам обслуживания (многоквартирным домам): связь «многие ко многим».
CREATE TABLE `employee_houses` (
  `employee_id` INT UNSIGNED NOT NULL,
  `house_id` INT UNSIGNED NOT NULL,
  PRIMARY KEY (`employee_id`, `house_id`),
  CONSTRAINT `fk_employee_houses_employee_id` FOREIGN KEY (`employee_id`) REFERENCES `employees` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_employee_houses_house_id` FOREIGN KEY (`house_id`) REFERENCES `houses` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
