@echo off
REM Lexora Workspace - runs scripts\windows\verify.ps1 (see README, "Windows / Laragon").
REM Bypass applies to this one PowerShell process only; no system policy is changed.
powershell -NoProfile -ExecutionPolicy Bypass -File "%~dp0scripts\windows\verify.ps1" %*
set "LEXORA_EXIT=%ERRORLEVEL%"
echo.
if not defined LEXORA_NO_PAUSE pause
exit /b %LEXORA_EXIT%
