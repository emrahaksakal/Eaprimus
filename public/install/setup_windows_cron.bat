@echo off
chcp 65001 >nul
echo ========================================================
echo         EAPRIMUS WINDOWS ARKA PLAN GOREVI KURULUMU
echo ========================================================
echo.
echo Bu islem, Eaprimus OTRS/Bilet sisteminin arka planda
echo calisabilmesi (mail okuma, bildirim gonderme vb.) icin
echo Windows Gorev Zamanlayiciya (Task Scheduler) her 1 
echo dakikada bir calisacak gizli bir gorev ekler.
echo.
echo Yollar (Paths):
echo PHP Yolu: C:\Ampps\php\php-win.exe
echo Dosya Yolu: C:\Ampps\www\app\cron\worker.php
echo.

net session >nul 2>&1
if %errorLevel% == 0 (
    echo [OK] Yonetici haklarina sahipsiniz. Kurulum basliyor...
) else (
    echo [HATA] Lutfen bu dosyaya sag tiklayip "Yonetici Olarak Calistir" secenegi ile acin!
    echo.
    pause
    exit /b 1
)

echo.
echo Gorev ekleniyor...
schtasks /create /tn "Eaprimus_Cron" /tr "C:\Ampps\php\php-win.exe C:\Ampps\www\app\cron\worker.php" /sc minute /mo 1 /ru System /f

if %errorLevel% == 0 (
    echo.
    echo [BASARILI] Eaprimus arka plan gorevi ^(Cron^) basariyla kuruldu!
    echo Artik e-postalar arka planda panele girmeden de okunacak.
) else (
    echo.
    echo [HATA] Gorev olusturulurken bir sorun olustu.
)

echo.
pause
exit
