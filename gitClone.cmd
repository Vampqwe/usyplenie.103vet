@echo off
chcp 65001 >nul
echo ==========================================
echo Скрипт клонирования проекта NewVetTerapiya
echo ==========================================

:: Проверка наличия Git
git --version >nul 2>&1
if %errorlevel% neq 0 (
    echo ОШИБКА: Git не установлен или не добавлен в PATH!
    echo Пожалуйста, установите Git: https://git-scm.com/
    pause
    exit /b 1
)

:: Клонирование репозитория
echo Начинаю клонирование...
git clone https://github.com/Vampqwe/usyplenie.103vet.git

if %errorlevel% equ 0 (
    echo.
    echo УСПЕШНО: Проект успешно клонирован!
    echo Папка NewVetTerapiya создана.
) else (
    echo.
    echo ОШИБКА: Не удалось клонировать репозиторий.
    echo Проверьте подключение к интернету или права доступа.
)

pause