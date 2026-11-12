@echo off
rem ПосадДом: запуск для разработки и демонстрации (Windows + XAMPP)
chcp 65001 >nul
setlocal
set "ROOT=%~dp0"
set "PHP=C:\xampp\php\php.exe"
if not exist "%PHP%" set "PHP=php"

rem 1. СУБД: MariaDB из XAMPP (если ещё не запущена)
tasklist /FI "IMAGENAME eq mysqld.exe" 2>nul | find /I "mysqld.exe" >nul
if errorlevel 1 (
  if exist "C:\xampp\mysql\bin\mysqld.exe" (
    echo Запуск MariaDB...
    start "" /B "C:\xampp\mysql\bin\mysqld.exe" --defaults-file="C:\xampp\mysql\bin\my.ini" --standalone
    timeout /t 6 >nul
  ) else (
    echo MySQL/MariaDB не найдена. Запустите сервер БД и повторите.
  )
)

cd /d "%ROOT%"

rem 2. Установка БД posaddom с демо-данными (если базы ещё нет; существующая не затрагивается).
rem    Расширение gd нужно только для генерации фото-заглушек в демо.
"%PHP%" -d extension=gd database\install.php --demo 2>nul

rem 3. Веб-сервер (порт 8300)
echo.
echo Приложение: http://127.0.0.1:8300
echo Демо-вход: morozova.ev / Demo12345 (диспетчер); другие роли - в README.md
echo Остановка: Ctrl+C
"%PHP%" -S 127.0.0.1:8300 -t public public\index.php
