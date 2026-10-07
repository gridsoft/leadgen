@echo off
rem One-time setup so the Find emails page can start the AI worker with a button.
rem Creates a scheduled task that runs start_ai_worker.bat as YOU (with your Claude
rem login), on your desktop. The page only triggers it: schtasks /Run. Safe to re-run.
cd /d "%~dp0"
schtasks /Create /TN "Leadgen AI worker" /TR "\"%~dp0start_ai_worker.bat\"" /SC ONCE /ST 00:00 /SD 01/01/2020 /IT /F
if errorlevel 1 (
  echo.
  echo Setup failed - see the message above.
) else (
  echo.
  echo Done. You can now start the AI worker from the Find emails page.
)
pause
