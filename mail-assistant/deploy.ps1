<#
  Инсталира или обновява „Фактури от пощата“ на хостинга (cPanel).

  Стартиране: двоен клик върху deploy.cmd
          или: powershell -ExecutionPolicy Bypass -File deploy.ps1

  Сам прави: субдомейн, SSL, PHP версия, качване на файловете, config.php,
  проверка на пощата и на docs.rtdalia.com, cron задача. Пита само за данните
  за вход. Може да се пуска многократно — следващия път само обновява файловете
  (с -Reconfigure пита отново за пощата и PIN-а).

  Работи с Windows PowerShell 5.1 (вграден в Windows 10/11) и PowerShell 7.
#>
param([switch]$Reconfigure)

$ErrorActionPreference = 'Stop'
try { [Console]::OutputEncoding = [Text.Encoding]::UTF8 } catch { }
[Net.ServicePointManager]::SecurityProtocol = [Net.ServicePointManager]::SecurityProtocol -bor [Net.SecurityProtocolType]::Tls12
Add-Type -AssemblyName System.Net.Http

$AppDir = $PSScriptRoot
$StatePath = Join-Path $AppDir 'deploy.local.json'

# ---------------- извеждане и въпроси ----------------

function Step([string]$t) { Write-Host ''; Write-Host "== $t" -ForegroundColor Cyan }
function Ok([string]$t) { Write-Host "   [OK] $t" -ForegroundColor Green }
function Warn([string]$t) { Write-Host "   [!] $t" -ForegroundColor Yellow }
function Info([string]$t) { Write-Host "   $t" -ForegroundColor Gray }
function Stop-With([string]$t) {
    Write-Host ''
    Write-Host "[X] $t" -ForegroundColor Red
    exit 1
}
function Def($v, $d) { if ($v) { return $v } return $d }

function Ask([string]$prompt, [string]$default = '') {
    $label = if ($default) { "   $prompt [$default]" } else { "   $prompt" }
    $v = Read-Host $label
    if ([string]::IsNullOrWhiteSpace($v)) { return $default }
    return $v.Trim()
}

function AskSecret([string]$prompt, [switch]$AllowEmpty) {
    while ($true) {
        $s = Read-Host "   $prompt" -AsSecureString
        $b = [Runtime.InteropServices.Marshal]::SecureStringToBSTR($s)
        try { $v = [Runtime.InteropServices.Marshal]::PtrToStringBSTR($b) }
        finally { [Runtime.InteropServices.Marshal]::ZeroFreeBSTR($b) }
        if ($v -or $AllowEmpty) { return $v }
    }
}

function AskYes([string]$prompt, [bool]$default = $true) {
    $hint = if ($default) { '[Д/н]' } else { '[д/Н]' }
    $v = (Read-Host "   $prompt $hint").Trim().ToLower()
    if ($v -eq '') { return $default }
    return @('д', 'да', 'y', 'yes') -contains $v
}

# ---------------- запомнени (несекретни) отговори ----------------

$State = @{}
if (Test-Path $StatePath) {
    try {
        (Get-Content $StatePath -Raw -Encoding UTF8 | ConvertFrom-Json).PSObject.Properties |
            ForEach-Object { $State[$_.Name] = $_.Value }
    } catch { }
}
function Save-State { $State | ConvertTo-Json | Set-Content -Path $StatePath -Encoding UTF8 }

# ---------------- HTTP ----------------

$Cp = New-Object System.Net.Http.HttpClient
$Cp.Timeout = [TimeSpan]::FromMinutes(5)
$SiteHandler = New-Object System.Net.Http.HttpClientHandler
$SiteHandler.CookieContainer = New-Object System.Net.CookieContainer
$Site = New-Object System.Net.Http.HttpClient($SiteHandler)
$Site.Timeout = [TimeSpan]::FromMinutes(4)

function Send-Http($client, [string]$method, [string]$url, $content, $headers) {
    $req = New-Object System.Net.Http.HttpRequestMessage((New-Object System.Net.Http.HttpMethod($method)), $url)
    if ($headers) { foreach ($k in $headers.Keys) { [void]$req.Headers.TryAddWithoutValidation($k, [string]$headers[$k]) } }
    if ($content) { $req.Content = $content }
    $resp = $client.SendAsync($req).GetAwaiter().GetResult()
    $body = $resp.Content.ReadAsStringAsync().GetAwaiter().GetResult()
    return [pscustomobject]@{ Code = [int]$resp.StatusCode; Body = $body }
}

function Err-Text($e) {
    $x = $e.Exception
    $msg = $x.Message
    while ($x.InnerException) { $x = $x.InnerException; $msg = $x.Message }
    return $msg
}

function To-Query($params) {
    if (-not $params) { return '' }
    $parts = foreach ($k in $params.Keys) {
        [Uri]::EscapeDataString([string]$k) + '=' + [Uri]::EscapeDataString([string]$params[$k])
    }
    return '?' + ($parts -join '&')
}

# ---------------- cPanel API ----------------

function Cp-Parse($r) {
    if ($r.Code -eq 401 -or $r.Code -eq 403) {
        throw 'cPanel отказа достъп — проверете потребителското име и паролата/токена.'
    }
    try { return $r.Body | ConvertFrom-Json }
    catch { throw "cPanel върна неочакван отговор (HTTP $($r.Code)). Проверете адреса на cPanel." }
}

function Uapi([string]$module, [string]$func, $params = $null, $content = $null) {
    $url = "$CpBase/execute/$module/$func" + (To-Query $params)
    $method = if ($content) { 'POST' } else { 'GET' }
    $j = Cp-Parse (Send-Http $Cp $method $url $content @{ Authorization = $CpAuth })
    if ($j.status -ne 1) {
        $errs = @($j.errors) | Where-Object { $_ }
        throw ($module + '.' + $func + ': ' + ($errs -join '; '))
    }
    return $j.data
}

function Api2([string]$module, [string]$func, $params = $null) {
    $q = [ordered]@{
        cpanel_jsonapi_user = $CpUser; cpanel_jsonapi_apiversion = '2'
        cpanel_jsonapi_module = $module; cpanel_jsonapi_func = $func
    }
    if ($params) { foreach ($k in $params.Keys) { $q[$k] = $params[$k] } }
    $j = Cp-Parse (Send-Http $Cp 'GET' ("$CpBase/json-api/cpanel" + (To-Query $q)) $null @{ Authorization = $CpAuth })
    $r = $j.cpanelresult
    if (-not $r) { throw ($module + '.' + $func + ': неочакван отговор') }
    if ($r.error -or ($r.event -and $r.event.result -eq 0)) { throw ($module + '.' + $func + ': ' + $r.error) }
    return $r.data
}

function Upload-Files([string]$dir, $items) {
    $mp = New-Object System.Net.Http.MultipartFormDataContent
    $mp.Add((New-Object System.Net.Http.StringContent($dir)), 'dir')
    $mp.Add((New-Object System.Net.Http.StringContent('1')), 'overwrite')
    $n = 0
    foreach ($it in $items) {
        $n++
        $c = [System.Net.Http.ByteArrayContent]::new([byte[]]$it.Bytes)
        $c.Headers.ContentType = [System.Net.Http.Headers.MediaTypeHeaderValue]::Parse('application/octet-stream')
        $mp.Add($c, "file-$n", $it.Name)
    }
    $data = Uapi 'Fileman' 'upload_files' $null $mp
    if ([int]$data.failed -gt 0) {
        $why = @($data.uploads) | Where-Object { $_.status -ne 1 } | ForEach-Object { "$($_.file): $($_.reason)" }
        throw ('качването не успя — ' + ($why -join '; '))
    }
}

# ---------------- приложението на сайта ----------------

function Site-Get([string]$action) {
    return Send-Http $Site 'GET' "$SiteBase/api.php?action=$action" $null $null
}

function Site-Post([string]$action, $obj) {
    $json = $obj | ConvertTo-Json -Compress
    $c = New-Object System.Net.Http.StringContent($json, [Text.Encoding]::UTF8, 'application/json')
    return Send-Http $Site 'POST' "$SiteBase/api.php?action=$action" $c @{ 'X-Requested-With' = 'mail-assistant' }
}

function New-Password {
    $chars = 'abcdefghjkmnpqrstuvwxyzABCDEFGHJKLMNPQRSTUVWXYZ23456789'
    $bytes = New-Object byte[] 14
    [Security.Cryptography.RandomNumberGenerator]::Create().GetBytes($bytes)
    return -join ($bytes | ForEach-Object { $chars[$_ % $chars.Length] })
}

function Php-Str([string]$s) { return "'" + ($s -replace '\\', '\\' -replace "'", "\'") + "'" }

# Пита за данните на config.php, проверява PIN-а за docs и го качва.
function Configure-App {
    Step 'Настройки на приложението'
    $p = AskSecret 'Парола за вход в приложението (Enter = генерирай)' -AllowEmpty
    if (-not $p) {
        $p = New-Password
        Write-Host ''
        Write-Host "   Генерирана парола: $p" -ForegroundColor White -BackgroundColor DarkBlue
        Write-Host '   Запишете я — с нея влизате в приложението.' -ForegroundColor White
    } elseif ($p.Length -lt 6) {
        Warn 'Паролата е твърде къса — генерирам нова.'
        $p = New-Password
        Write-Host "   Генерирана парола: $p" -ForegroundColor White -BackgroundColor DarkBlue
    }
    $script:AppPassword = $p

    Info ''
    $mail = Ask 'Имейл адрес, който да се проверява' (Def $State.imapUser '')
    while (-not $mail) { $mail = Ask 'Имейл адрес, който да се проверява' }
    $isGmail = $mail -match '@(gmail|googlemail)\.com$'
    if ($isGmail) {
        $imapHost = 'imap.gmail.com'
        Info 'За Gmail е нужна „Парола за приложения“ (обикновената парола не работи).'
        Info 'Изисква включено „Потвърждаване в 2 стъпки“ в Google акаунта.'
        if (AskYes 'Да отворя ли страницата на Google, за да я създадете?' $true) {
            Start-Process 'https://myaccount.google.com/apppasswords'
        }
        $imapPass = (AskSecret 'Парола за приложения (16 букви)') -replace '\s', ''
    } else {
        $imapHost = Ask 'IMAP сървър' (Def $State.imapHost "mail.$RootDomain")
        $imapPass = AskSecret "Парола на пощата $mail"
    }

    Info ''
    $docsUrl = (Ask 'Адрес на приложението за документи' (Def $State.docsUrl 'https://docs.rtdalia.com')).TrimEnd('/')
    while ($true) {
        $pin = AskSecret "Access PIN за $docsUrl"
        try {
            $r = Send-Http $Site 'GET' "$docsUrl/api/state-version" $null @{ 'X-Sync-Token' = $pin }
            if ($r.Code -eq 200) { Ok 'PIN-ът е верен'; break }
            if ($r.Code -eq 401) { Warn 'Грешен PIN, опитайте пак.'; continue }
            Warn "Неочакван отговор от docs (HTTP $($r.Code)) — продължавам."
            break
        } catch {
            Warn ('Няма връзка с docs: ' + (Err-Text $_) + ' — продължавам.')
            break
        }
    }

    $config = @(
        '<?php'
        '// Създаден от deploy.ps1 — може да се редактира и ръчно (вижте config.sample.php).'
        ''
        "define('APP_PASSWORD', $(Php-Str $p));"
        ''
        "define('IMAP_HOST', $(Php-Str $imapHost));"
        "define('IMAP_PORT', 993);"
        "define('IMAP_USER', $(Php-Str $mail));"
        "define('IMAP_PASS', $(Php-Str $imapPass));"
        "define('IMAP_FOLDER', 'INBOX');"
        ''
        "define('TRANSCRIBER_URL', $(Php-Str $docsUrl));"
        "define('TRANSCRIBER_PIN', $(Php-Str $pin));"
        ''
    ) -join "`n"
    $bytes = (New-Object System.Text.UTF8Encoding($false)).GetBytes($config)
    Upload-Files $DocRoot @([pscustomobject]@{ Name = 'config.php'; Bytes = $bytes })
    Ok 'config.php е качен'

    $State.imapUser = $mail
    $State.imapHost = $imapHost
    $State.docsUrl = $docsUrl
    $State.configured = $true
    Save-State
}

# ======================================================================

Write-Host ''
Write-Host '  Фактури от пощата — инсталация на хостинга' -ForegroundColor White
Write-Host '  ------------------------------------------'
if (-not (Test-Path (Join-Path $AppDir 'api.php'))) { Stop-With "Пуснете скрипта от папката mail-assistant ($AppDir)." }

# ---- 1. cPanel ----
Step 'Вход в cPanel'
Info 'Данните са тези, с които влизате в cPanel на SuperHosting (не FTP акаунт).'
Info 'Ако cPanel е с двуфакторна защита, ползвайте API токен: cPanel » Security » Manage API Tokens.'
$cpHost = Def $State.cpanelHost 'rtdalia.com:2083'
while ($true) {
    $cpHost = (Ask 'Адрес на cPanel' $cpHost) -replace '^https?://', '' -replace '/.*$', ''
    if ($cpHost -notmatch ':\d+$') { $cpHost += ':2083' }
    $CpBase = "https://$cpHost"
    $CpUser = Ask 'cPanel потребителско име' (Def $State.cpanelUser '')
    $secret = AskSecret 'cPanel парола (или API токен)'
    if ($secret -cmatch '^[A-Z0-9]{32}$') { # API токените са 32 главни букви/цифри
        $CpAuth = "cpanel ${CpUser}:$secret"
    } else {
        $CpAuth = 'Basic ' + [Convert]::ToBase64String([Text.Encoding]::UTF8.GetBytes("${CpUser}:$secret"))
    }
    try {
        $domains = Uapi 'DomainInfo' 'list_domains'
        if (-not $domains.main_domain) { throw 'cPanel не върна списък с домейни.' }
        Ok "Влязохте като $CpUser ($($domains.main_domain))"
        break
    } catch {
        $m = Err-Text $_
        if ($m -match 'SSL|certificate|trust') { $m += ' — опитайте адреса на сървъра от писмото на SuperHosting.' }
        Warn $m
    }
}
$State.cpanelHost = $cpHost
$State.cpanelUser = $CpUser
Save-State

# ---- 2. Субдомейн ----
Step 'Субдомейн'
$RootDomain = Def $State.rootDomain $domains.main_domain
$sub = Ask 'Субдомейн за приложението' (Def $State.sub 'inbox')
$Fqdn = "$sub.$RootDomain"
if (@($domains.sub_domains) -contains $Fqdn) {
    Ok "$Fqdn вече съществува"
} else {
    if (-not (AskYes "Да създам ли $Fqdn?" $true)) { Stop-With 'Прекъснато.' }
    try {
        [void](Uapi 'SubDomain' 'addsubdomain' ([ordered]@{ domain = $sub; rootdomain = $RootDomain; dir = $Fqdn }))
    } catch {
        # Някои сървъри позволяват document root само в public_html.
        Warn (Err-Text $_)
        [void](Uapi 'SubDomain' 'addsubdomain' ([ordered]@{ domain = $sub; rootdomain = $RootDomain; dir = "public_html/$Fqdn" }))
    }
    Ok "Създаден $Fqdn"
}
$info = Uapi 'DomainInfo' 'single_domain_data' @{ domain = $Fqdn }
$DocRoot = [string]$info.documentroot
if (-not $DocRoot) { Stop-With "cPanel не върна папката на $Fqdn." }
Info "Папка: $DocRoot"
$State.rootDomain = $RootDomain
$State.sub = $sub
Save-State
$SiteBase = "https://$Fqdn"

# ---- 3. Качване на файловете ----
Step 'Качване на файловете'
$files = Get-ChildItem -Path $AppDir -Recurse -File -Force | ForEach-Object {
    $rel = $_.FullName.Substring($AppDir.Length).TrimStart('\', '/') -replace '\\', '/'
    [pscustomobject]@{ Rel = $rel; Path = $_.FullName }
} | Where-Object {
    $_.Rel -notmatch '^(config\.php|config\.sample\.php|deploy\.ps1|deploy\.cmd|deploy\.local\.json|README\.md)$' -and
    $_.Rel -notmatch '^tests/' -and
    ($_.Rel -notmatch '^data/' -or $_.Rel -eq 'data/.htaccess')
}
$groups = $files | Group-Object { $i = $_.Rel.LastIndexOf('/'); if ($i -lt 0) { '' } else { $_.Rel.Substring(0, $i) } }
foreach ($g in $groups) {
    if ($g.Name) {
        # Създава вложените папки една по една (грешка „вече съществува“ се пропуска).
        $parent = $DocRoot
        foreach ($seg in $g.Name.Split('/')) {
            try { [void](Api2 'Fileman' 'mkdir' ([ordered]@{ path = $parent; name = $seg; permissions = '0755' })) } catch { }
            $parent = "$parent/$seg"
        }
    }
    $target = if ($g.Name) { "$DocRoot/$($g.Name)" } else { $DocRoot }
    $items = $g.Group | ForEach-Object {
        [pscustomobject]@{ Name = Split-Path $_.Path -Leaf; Bytes = [IO.File]::ReadAllBytes($_.Path) }
    }
    try { Upload-Files $target @($items) } catch { Stop-With ("$($g.Name)/: " + (Err-Text $_)) }
}
Ok "Качени $(@($files).Count) файла"

# ---- 4. SSL и PHP ----
Step 'SSL сертификат и PHP'
try { [void](Uapi 'SSL' 'start_autossl_check'); Info 'Пусната е проверка за безплатен SSL (AutoSSL).' } catch { }
$deadline = (Get-Date).AddMinutes(20)
$phpFixed = $false
$lastErr = ''
$waited = $false
while ($true) {
    try {
        $r = Site-Get 'list'
        if ($r.Body -match 'PHP 8\.0') {
            if ($phpFixed) { Stop-With 'Сайтът още е на стар PHP. Задайте PHP 8.1+ в cPanel » MultiPHP Manager и пуснете скрипта пак.' }
            Warn 'Сайтът работи със стара версия на PHP — сменям я.'
            try {
                $inst = Uapi 'LangPHP' 'php_get_installed_versions'
                $best = @($inst.versions) | Where-Object { $_ -match '\d{2}$' } |
                    Sort-Object { [int]($_ -replace '\D', '') } | Select-Object -Last 1
                [void](Uapi 'LangPHP' 'php_set_vhost_versions' ([ordered]@{ version = $best; vhost = $Fqdn }))
                Ok "PHP е сменен на $best"
            } catch {
                Stop-With ('Не успях да сменя PHP (' + (Err-Text $_) + '). Задайте PHP 8.1+ в cPanel » MultiPHP Manager и пуснете скрипта пак.')
            }
            $phpFixed = $true
            Start-Sleep -Seconds 5
            continue
        }
        if ($r.Code -eq 401 -or $r.Body -match 'config\.php') { break }
        $lastErr = "HTTP $($r.Code)"
    } catch {
        $lastErr = Err-Text $_
    }
    if ((Get-Date) -gt $deadline) {
        Write-Host ''
        Stop-With "$SiteBase още не отговаря ($lastErr). SSL сертификатът и DNS понякога отнемат до час — пуснете скрипта пак по-късно."
    }
    if (-not $waited) { Info 'Изчаквам сайтът да заработи по HTTPS (обикновено няколко минути)'; $waited = $true }
    Write-Host '.' -NoNewline
    Start-Sleep -Seconds 15
}
if ($waited) { Write-Host '' }
Ok "$SiteBase работи"

# ---- 5. config.php ----
$AppPassword = $null
$keep = $false
if ($State.configured -and $r.Code -eq 401 -and -not $Reconfigure) {
    $keep = AskYes 'Настройките (поща, PIN) вече са качени. Да ги запазя ли?' $true
}
if (-not $keep) { Configure-App }

# ---- 6. Проверка ----
Step 'Проверка на връзките'
$settings = $null
while ($true) {
    if (-not $AppPassword) { $AppPassword = AskSecret 'Парола за вход в приложението (за проверката)' }
    $r = Site-Post 'login' @{ password = $AppPassword }
    if ($r.Code -ne 200) {
        Warn 'Приложението не прие паролата.'
        $AppPassword = $null
        continue
    }
    $t = (Site-Get 'test').Body | ConvertFrom-Json
    if ($t.imap.ok) { Ok ('Поща: ' + $State.imapUser) } else { Warn ('Поща: ' + $t.imap.error) }
    if ($t.docs.ok) { Ok 'docs.rtdalia.com: PIN-ът е приет' } else { Warn ('docs: ' + $t.docs.error) }
    if ($t.imap.ok -and $t.docs.ok) {
        $settings = ((Site-Get 'settings').Body | ConvertFrom-Json).settings
        break
    }
    if (-not (AskYes 'Да въведа ли данните отново?' $true)) { break }
    Configure-App
}

# ---- 7. Cron ----
Step 'Автоматична проверка на пощата (cron)'
$cronCmd = if ($settings -and $settings.cronCommand) { [string]$settings.cronCommand } else {
    "/usr/local/bin/php $DocRoot/cron.php >> $DocRoot/data/cron.log 2>&1"
}
$cronPaths = @("$DocRoot/cron.php", ($cronCmd -split ' ')[1]) # сървърът може да даде път през символна връзка
try {
    $existing = @(Api2 'Cron' 'listcron') | Where-Object {
        $c = [string]$_.command
        $c -and (@($cronPaths | Where-Object { $_ -and $c.Contains($_) }).Count -gt 0)
    }
    if ($existing) {
        Ok 'Cron задачата вече съществува'
    } elseif (AskYes 'Да добавя ли проверка на пощата на всеки 15 минути?' $true) {
        [void](Api2 'Cron' 'add_line' ([ordered]@{
            command = $cronCmd; minute = '*/15'; hour = '*'; day = '*'; month = '*'; weekday = '*'
        }))
        Ok 'Добавена: на всеки 15 минути'
        Info $cronCmd
    }
} catch {
    Warn ('Cron не можа да се добави (' + (Err-Text $_) + '). Добавете го ръчно в cPanel » Cron Jobs:')
    Info $cronCmd
}

Write-Host ''
Write-Host "  Готово: $SiteBase" -ForegroundColor Green
Info 'Подателите за автоматично изпращане се задават в приложението » Настройки.'
if (AskYes 'Да отворя ли приложението в браузъра?' $true) { Start-Process "$SiteBase/" }
