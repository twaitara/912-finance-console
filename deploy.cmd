@echo off
REM Double-click to deploy: pushes to GitHub + uploads to cPanel via FTP.
REM Requires Git for Windows (Git Bash). Uses .deploy.env for your FTP details.
setlocal
where bash >nul 2>nul
if errorlevel 1 (
  echo Git Bash was not found on PATH.
  echo Open "Git Bash" in this folder and run:  bash deploy.sh
) else (
  bash "%~dp0deploy.sh"
)
echo.
pause
