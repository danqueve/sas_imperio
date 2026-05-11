@echo off
:: Lanzador para scripts\backup.ps1
:: Usar este .bat si Task Scheduler esta configurado para .bat.
:: Recomendado: usar backup.ps1 directamente con powershell.exe.
powershell.exe -ExecutionPolicy Bypass -NonInteractive -File "%~dp0backup.ps1"
exit /b %ERRORLEVEL%
