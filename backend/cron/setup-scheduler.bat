@echo off
:: AniStream Auto-Sync Scheduler Setup
:: Run this as Administrator to register the Windows Task Scheduler job.
::
:: Schedule:
::   - Every 1 hour (checks for new airing episodes)
::   - Starts at 2:00 AM on first run

echo ============================================
echo  AniStream Auto-Sync Scheduler Setup
echo ============================================
echo.

:: Remove old task if exists
schtasks /delete /tn "AniStream-Sync" /f 2>nul

:: Create hourly task
schtasks /create ^
  /tn "AniStream-Sync" ^
  /tr "\"C:\xampp\php\php.exe\" \"C:\xampp\htdocs\Aninew\anime-platform\backend\cron\fetch_anime.php\"" ^
  /sc HOURLY ^
  /mo 1 ^
  /st 02:00 ^
  /ru SYSTEM ^
  /f

if %ERRORLEVEL% == 0 (
    echo.
    echo [OK] Task "AniStream-Sync" created.
    echo      Runs every 1 hour starting at 2:00 AM.
    echo.
    echo --- Useful commands ---
    echo Run now:      schtasks /run /tn "AniStream-Sync"
    echo Check status: schtasks /query /tn "AniStream-Sync" /fo LIST
    echo Delete task:  schtasks /delete /tn "AniStream-Sync" /f
    echo.
    echo --- Or trigger via browser (while XAMPP is running) ---
    echo http://localhost/Aninew/anime-platform/backend/cron/fetch_anime.php?secret=anistream_sync
    echo.
) else (
    echo.
    echo [ERROR] Failed. Make sure you right-click and "Run as Administrator".
)

pause
