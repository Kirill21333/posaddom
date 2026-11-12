@echo off
rem Полный прогон тестов ПосадДом. Нужны: запущенная MariaDB и сервер приложения на 127.0.0.1:8300 (start.bat), Python 3.
rem ВНИМАНИЕ: база posaddom пересоздаётся с демо-данными!
chcp 65001 >nul
cd /d "%~dp0.."
set "PHP=C:\xampp\php\php.exe"
if not exist "%PHP%" set "PHP=php"

"%PHP%" -d extension=gd database\install.php --force --demo >nul || goto :err
echo === Модульные тесты (Deadline, Numbering, Assignment)
"%PHP%" tests\unit_test.php || goto :err
echo === Интеграционные тесты API (ТК-01...ТК-18)
python -X utf8 tests\api_test.py || goto :err
if exist tests\api_part2_test.py (
  echo === Тесты справочников, отчётов и администрирования
  python -X utf8 tests\api_part2_test.py || goto :err
)
if exist tests\e2e.py (
  echo === Сквозные UI-тесты
  "%PHP%" -d extension=gd database\install.php --force --demo >nul
  python -X utf8 tests\e2e.py _shots || goto :err
)
echo.
echo Все тесты пройдены
exit /b 0
:err
echo.
echo ТЕСТЫ НЕ ПРОЙДЕНЫ
exit /b 1
