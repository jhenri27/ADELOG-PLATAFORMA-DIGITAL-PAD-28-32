@echo off
setlocal
set SCRIPT_PS1=C:\wamp64\www\PLATAFORMA DIGITAL-PAD-28-32\backup-adelog.ps1
where pwsh >nul 2>nul
if %ERRORLEVEL% EQU 0 (
    pwsh -NoProfile -ExecutionPolicy Bypass -File "%SCRIPT_PS1%" %*
) else (
    powershell -NoProfile -ExecutionPolicy Bypass -File "%SCRIPT_PS1%" %*
)
endlocal