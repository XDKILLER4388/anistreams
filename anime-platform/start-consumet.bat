@echo off
echo ============================================
echo   AniStream - Starting Consumet API Server
echo ============================================
echo.
echo Server will run at: http://localhost:3000
echo Keep this window open while watching anime.
echo.
cd /d "%~dp0consumet-server"
"C:\Program Files\nodejs\node.exe" index.js
pause
