@echo off
rem Starts the backend (port 8000) and customer site (port 3000) in two windows.
set ROOT=%~dp0
start "Roumdoul Order - backend" cmd /k "cd /d "%ROOT%backend" && php artisan serve"
start "Roumdoul Order - customer site" cmd /k "cd /d "%ROOT%frontend" && npm run dev"
timeout /t 6 >nul
start http://localhost:8000/app
