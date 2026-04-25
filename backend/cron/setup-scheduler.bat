@echo off
:: AniStream Auto-Sync Scheduler Setup
:: Run this as Administrator to create a Windows Task Scheduler job
:: that syncs anime data every 6 hours automatically.

echo Setting up AniStream Auto-Sync Task...

:: Delete existing task if it exists
schtasks /delete /tn "AniStream-Sync" /f 2>nul

:: Create task: runs every 6 hours, starts at 3:00 AM
schtasks /create ^
  /tn "AniStream-Sync" ^
  /tr "\"C:\xampp\php\php.exe\" \"C:\xampp\htdocs\Aninew\anime-platform\backend\cron\fetch_anime.php\"" ^
  /sc HOURLY ^
  /mo 6 ^
  /st 03:00 ^
  /ru SYSTEM ^
  /f

if %ERRORLEVEL% == 0 (
    echo.
    echo [OK] Task "AniStream-Sync" created successfully.
    echo      Runs every 6 hours starting at 3:00 AM.
    echo.
    echo To run manually now:
    echo   schtasks /run /tn "AniStream-Sync"
    echo.
    echo To check status:
    echo   schtasks /query /tn "AniStream-Sync"
) else (
    echo.
    echo [ERROR] Failed to create task. Make sure you run this as Administrator.
)

pause
