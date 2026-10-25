@echo off
rem Double-click to install/update the mail assistant on the hosting (runs deploy.ps1).
chcp 65001 >nul
powershell -NoProfile -ExecutionPolicy Bypass -File "%~dp0deploy.ps1" %*
echo.
pause
