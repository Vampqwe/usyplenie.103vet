@echo off
chcp 65001 >nul
setlocal enabledelayedexpansion

echo === Git Init Script ===
echo.

:: 1. Directory path
if "%~1"=="" (
    set /p "TARGET_DIR=Введите путь к папке (оставьте пустым для текущей): "
) else (
    set "TARGET_DIR=%~1"
    echo Используем путь из параметра: !TARGET_DIR!
)

if not "!TARGET_DIR!"=="" (
    if exist "!TARGET_DIR!" (
        cd /d "!TARGET_DIR!"
    ) else (
        echo Ошибка: Папка "!TARGET_DIR!" не найдена.
        pause
        exit /b 1
    )
)
echo Работа в папке: %CD%
echo.

:: 2. Commit message
if "%~2"=="" (
    set /p "COMMIT_MSG=Введите сообщение для коммита: "
) else (
    set "COMMIT_MSG=%~2"
    echo Используем сообщение из параметра: !COMMIT_MSG!
)

if "!COMMIT_MSG!"=="" (
    echo Ошибка: Сообщение коммита не может быть пустым.
    pause
    exit /b 1
)

:: 3. Branch name
if "%~3"=="" (
    set /p "BRANCH_NAME=Введите имя ветки (например, main): "
) else (
    set "BRANCH_NAME=%~3"
    echo Используем имя ветки из параметра: !BRANCH_NAME!
)

if "!BRANCH_NAME!"=="" (
    echo Ошибка: Имя ветки не может быть пустым.
    pause
    exit /b 1
)

:: 4. Remote URL
if "%~4"=="" (
    set /p "REMOTE_URL=Введите URL удаленного репозитория: "
) else (
    set "REMOTE_URL=%~4"
    echo Используем URL из параметра: !REMOTE_URL!
)

if "!REMOTE_URL!"=="" (
    echo Ошибка: URL репозитория не может быть пустым.
    pause
    exit /b 1
)

:: 5. Push branch (обычно совпадает с именем ветки)
if "%~5"=="" (
    set "PUSH_BRANCH=!BRANCH_NAME!"
) else (
    set "PUSH_BRANCH=%~5"
)

echo.
echo --- Запуск процессов Git ---

git init
if errorlevel 1 goto :git_error

git add .
if errorlevel 1 goto :git_error

git commit -m "!COMMIT_MSG!"
if errorlevel 1 goto :git_error

git branch -M "!BRANCH_NAME!"
if errorlevel 1 goto :git_error

git remote add origin "!REMOTE_URL!"
if errorlevel 1 goto :git_error

git push -u origin "!PUSH_BRANCH!"
if errorlevel 1 goto :git_error

echo.
echo === Успешно завершено! ===
pause
exit /b 0

:git_error
echo.
echo === Произошла ошибка при выполнении команды Git ===
pause
exit /b 1