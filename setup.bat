@echo off
setlocal
rem One-time setup for Roumdoul Order on Windows. Double-click to run.
rem Everything is also written to setup-log.txt so problems can be checked later.

set ROOT=%~dp0
set LOG=%ROOT%setup-log.txt
echo Roumdoul Order setup %DATE% %TIME% > "%LOG%"

echo [1/6] Creating database roumdoul_order...
set MYSQL=mysql
where mysql >nul 2>nul || set MYSQL=C:\xampp\mysql\bin\mysql.exe
"%MYSQL%" -u root -e "CREATE DATABASE IF NOT EXISTS roumdoul_order CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;" >> "%LOG%" 2>&1
if errorlevel 1 echo     Could not create it automatically. Create it in phpMyAdmin, then run this again.

cd /d "%ROOT%backend"
if not exist .env copy .env.example .env >nul

echo [2/6] Installing PHP packages (takes a few minutes)...
call composer install --no-interaction >> "%LOG%" 2>&1 || goto :failed

echo [3/6] Generating app key...
php artisan key:generate --force >> "%LOG%" 2>&1 || goto :failed

echo [4/6] Creating tables and demo data...
php artisan migrate --seed --force >> "%LOG%" 2>&1 || goto :failed
php artisan storage:link >> "%LOG%" 2>&1

echo [5/6] Running tests...
php artisan test >> "%LOG%" 2>&1
if errorlevel 1 (echo     Some tests failed. Details are in setup-log.txt) else (echo     All tests passed.)

cd /d "%ROOT%frontend"
if not exist .env.local copy .env.example .env.local >nul
echo [6/6] Installing customer site packages...
call npm install >> "%LOG%" 2>&1 || goto :failed

echo.
echo Setup finished. Double-click start.bat to run the system.
pause
exit /b 0

:failed
echo.
echo Setup stopped with an error. Details are in setup-log.txt
pause
exit /b 1
