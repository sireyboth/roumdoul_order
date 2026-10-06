@echo off
rem Starts the backend (port 8000), the customer site (port 3000) and the scheduler
rem (Telegram day-end sales summary) in three windows.
set ROOT=%~dp0
start "Roumdoul Order - backend" cmd /k "cd /d "%ROOT%backend" && php artisan serve"
start "Roumdoul Order - customer site" cmd /k "cd /d "%ROOT%frontend" && npm run dev"
start "Roumdoul Order - scheduler" cmd /k "cd /d "%ROOT%backend" && php artisan schedule:work"
timeout /t 6 >nul
start http://localhost:8000/app
