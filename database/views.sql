-- Представления ИС «ПосадДом»

-- Нагрузка сотрудника: сумма весов категорий по открытым заявкам (назначена / выполняется)
CREATE OR REPLACE VIEW v_employee_load AS
SELECT e.id AS employee_id,
       CONCAT(e.last_name, ' ', e.first_name, ' ', COALESCE(e.middle_name, '')) AS full_name,
       COALESCE(SUM(c.weight), 0) AS load_value,
       COUNT(r.id) AS open_requests
FROM employees e
LEFT JOIN requests r ON r.assignee_id = e.id
     AND r.status_id IN (SELECT id FROM request_statuses WHERE code IN ('assigned', 'in_progress'))
LEFT JOIN request_categories c ON c.id = r.category_id
GROUP BY e.id, e.last_name, e.first_name, e.middle_name;

-- Соблюдение сроков по категориям и месяцам поступления
CREATE OR REPLACE VIEW v_sla_report AS
SELECT c.code AS category_code, c.name AS category_name,
       DATE_FORMAT(r.received_at, '%Y-%m') AS ym,
       COUNT(*) AS total,
       SUM(r.completed_at IS NOT NULL) AS completed,
       SUM(r.completed_at IS NOT NULL AND r.completed_at <= r.due_at) AS in_time,
       SUM((r.completed_at IS NOT NULL AND r.completed_at > r.due_at)
           OR (r.completed_at IS NULL AND s.is_final = 0 AND r.due_at < NOW())) AS overdue,
       SUM(r.arrived_at IS NOT NULL AND r.arrived_at <= r.arrive_due) AS arrive_in_time,
       SUM((r.arrived_at IS NOT NULL AND r.arrived_at > r.arrive_due)
           OR (r.arrived_at IS NULL AND s.is_final = 0 AND r.arrive_due < NOW())) AS arrive_violations,
       ROUND(AVG(CASE WHEN r.completed_at IS NOT NULL THEN TIMESTAMPDIFF(MINUTE, r.received_at, r.completed_at) / 60 END), 1) AS avg_resolve_hours
FROM requests r
JOIN request_categories c ON c.id = r.category_id
JOIN request_statuses s ON s.id = r.status_id
WHERE s.code NOT IN ('rejected', 'cancelled')
GROUP BY c.code, c.name, DATE_FORMAT(r.received_at, '%Y-%m');

-- Просроченные заявки: не выполнены к сроку (в т.ч. выполненные позже срока) с величиной просрочки в минутах
CREATE OR REPLACE VIEW v_overdue_requests AS
SELECT r.id AS request_id, r.request_number, r.received_at, r.due_at, r.assignee_id,
       r.category_id, r.service_id, r.house_id, r.status_id, s.code AS status_code,
       r.completed_at,
       TIMESTAMPDIFF(MINUTE, r.due_at, COALESCE(r.completed_at, NOW())) AS overdue_minutes
FROM requests r
JOIN request_statuses s ON s.id = r.status_id
WHERE s.code NOT IN ('rejected', 'cancelled')
  AND ((r.completed_at IS NULL AND r.due_at < NOW()) OR r.completed_at > r.due_at);

-- Начисления по платным услугам по месяцам
CREATE OR REPLACE VIEW v_monthly_charges AS
SELECT DATE_FORMAT(ch.created_at, '%Y-%m') AS ym,
       ch.service_id, sv.code AS service_code, sv.name AS service_name,
       COUNT(*) AS charges_count,
       SUM(ch.amount) AS total_amount
FROM charges ch
JOIN services sv ON sv.id = ch.service_id
WHERE ch.status = 'accrued'
GROUP BY DATE_FORMAT(ch.created_at, '%Y-%m'), ch.service_id, sv.code, sv.name;
