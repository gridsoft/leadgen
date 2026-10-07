@echo off
title Leadgen AI worker
cd /d "%~dp0"
"C:\wamp64\bin\php\php8.3.28\php.exe" ai_worker.php %*
rem Keep the window open only if something went wrong, so the error can be read.
if errorlevel 1 pause
