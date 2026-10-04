# Starts everything the MotoGuard+ dashboard and device need, then opens the dashboard.
# Run it from the "Start MotoGuard" shortcut on the desktop. Safe to run again: it restarts cleanly.

$php = 'C:\laragon\bin\php\php-8.3.30-Win32-vs16-x64\php.exe'
$web = Join-Path $PSScriptRoot 'motoguard-web'

Write-Host 'Stopping any old MotoGuard servers...'
Get-CimInstance Win32_Process -Filter "Name='php.exe'" |
    Where-Object { $_.CommandLine -match 'artisan|server\.php' } |
    ForEach-Object { Stop-Process -Id $_.ProcessId -Force -ErrorAction SilentlyContinue }

# A leftover "hot" file makes the pages look for a Vite dev server that is not running (blank page).
# The built files in public\build are used instead.
Remove-Item (Join-Path $web 'public\hot') -Force -ErrorAction SilentlyContinue

$jobs = [ordered]@{
    'MotoGuard website (8000)'       = 'artisan serve --host=0.0.0.0 --port=8000'
    'MotoGuard device server (8001)' = 'artisan serve --host=0.0.0.0 --port=8001'
    'MotoGuard live updates'         = 'artisan reverb:start'
    'MotoGuard scheduler'            = 'artisan schedule:work'
    'MotoGuard announcer'            = 'artisan motoguard:announce --port=8001'
}

foreach ($title in $jobs.Keys) {
    Start-Process powershell.exe -WorkingDirectory $web -WindowStyle Minimized -ArgumentList @(
        '-NoExit', '-Command', "`$host.UI.RawUI.WindowTitle='$title'; & '$php' $($jobs[$title])"
    )
}

Write-Host 'Waiting for the website...'
$ready = $false
for ($i = 0; $i -lt 40 -and -not $ready; $i++) {
    Start-Sleep -Milliseconds 500
    $ready = [bool](Get-NetTCPConnection -LocalPort 8000 -State Listen -ErrorAction SilentlyContinue)
}

if ($ready) {
    Start-Process 'http://127.0.0.1:8000/dashboard'
    Write-Host 'MotoGuard+ is running. Keep the five minimized windows open.' -ForegroundColor Green

    # The laptop's WiFi address changes between networks, so show today's address for the phone.
    $ip = (Get-NetIPAddress -AddressFamily IPv4 -ErrorAction SilentlyContinue |
        Where-Object { $_.IPAddress -notlike '127.*' -and $_.IPAddress -notlike '169.254.*' -and $_.PrefixOrigin -ne 'WellKnown' } |
        Sort-Object InterfaceMetric | Select-Object -First 1).IPAddress
    if ($ip) {
        Write-Host ''
        Write-Host 'On your phone (same WiFi), open:' -ForegroundColor Cyan
        Write-Host "    http://${ip}:8000" -ForegroundColor Yellow
        Write-Host ''
        Write-Host 'This window closes in 20 seconds.'
        Start-Sleep -Seconds 16
    }
} else {
    Write-Host 'The website did not start. Tell Claude: "the launcher failed".' -ForegroundColor Red
}
Start-Sleep -Seconds 4
