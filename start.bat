@echo off
rem Starts the backend (port 8000), the customer site (port 3000), live updates (port 8080) and the scheduler
rem (Telegram day-end sales summary) in four windows.
set ROOT=%~dp0
start "Roumdoul Order - backend" cmd /k "cd /d "%ROOT%backend" && php artisan serve"
start "Roumdoul Order - customer site" cmd /k "cd /d "%ROOT%frontend" && npm run dev"
start "Roumdoul Order - scheduler" cmd /k "cd /d "%ROOT%backend" && php artisan schedule:work"
start "Roumdoul Order - live updates" cmd /k "cd /d "%ROOT%backend" && php artisan reverb:start"
timeout /t 6 >nul
start http://localhost:8000/app
