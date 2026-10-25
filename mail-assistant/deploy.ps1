<#
  Installs or updates the mail assistant ("Фактури от пощата") on the cPanel hosting.

  Run: double-click deploy.cmd
   or: powershell -ExecutionPolicy Bypass -File deploy.ps1

  Does on its own: subdomain, SSL, PHP version, file upload, config.php, mail
  and docs.rtdalia.com checks, cron job. Only asks for credentials. Safe to
  re-run: next time it just updates the files (-Reconfigure asks for the mail
  and PIN again).

  Messages are in English on purpose: the classic Windows console font often
  cannot show Cyrillic. Works with Windows PowerShell 5.1 and PowerShell 7.
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
function Warn([string]$t) { Write-Host "   [!] $(Latin $t)" -ForegroundColor Yellow }
function Info([string]$t) { Write-Host "   $t" -ForegroundColor Gray }
function Stop-With([string]$t) {
    Write-Host ''
    Write-Host "[X] $(Latin $t)" -ForegroundColor Red
    exit 1
}
function Def($v, $d) { if ($v) { return $v } return $d }

# Server messages (from the app / cPanel) can be in Bulgarian; the console font
# may not show Cyrillic, so they are printed transliterated.
# Latin for U+0430..U+044F (а..я, incl. ы and э).
$TranslitLat = 'a','b','v','g','d','e','zh','z','i','y','k','l','m','n','o','p',
               'r','s','t','u','f','h','ts','ch','sh','sht','a','y','y','e','yu','ya'
$Translit = @{}
for ($i = 0; $i -lt 32; $i++) { $Translit[[char](0x430 + $i)] = $TranslitLat[$i] }
function Latin([string]$s) {
    $sb = New-Object System.Text.StringBuilder
    foreach ($ch in $s.ToCharArray()) {
        $lower = [char]::ToLower($ch)
        if ($Translit.ContainsKey($lower)) {
            $t = $Translit[$lower]
            if ($ch -cne $lower) { $t = $t.Substring(0, 1).ToUpper() + $t.Substring(1) }
            [void]$sb.Append($t)
        } elseif ([int]$ch -gt 127) {
            [void]$sb.Append($(if ($ch -eq [char]0x2014 -or $ch -eq [char]0x2013) { '-' } elseif ($ch -eq [char]0xBB) { '>' } else { '"' }))
        } else {
            [void]$sb.Append($ch)
        }
    }
    return $sb.ToString()
}

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
    $hint = if ($default) { '[Y/n]' } else { '[y/N]' }
    $v = (Read-Host "   $prompt $hint").Trim().ToLower()
    if ($v -eq '') { return $default }
    return @('y', 'yes', 'd', 'da') -contains $v
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
    # Decoded by hand: cPanel sends charset="utf-8" with quotes, which
    # ReadAsStringAsync on .NET Framework (Windows PowerShell 5.1) rejects.
    $bytes = $resp.Content.ReadAsByteArrayAsync().GetAwaiter().GetResult()
    $body = [Text.Encoding]::UTF8.GetString($bytes)
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
        throw 'cPanel refused access - check the username and password/token.'
    }
    try { return $r.Body | ConvertFrom-Json }
    catch { throw "cPanel returned an unexpected response (HTTP $($r.Code)). Check the cPanel address." }
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
    if (-not $r) { throw ($module + '.' + $func + ': unexpected response') }
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
        throw ('upload failed - ' + ($why -join '; '))
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
    Step 'App settings'
    $p = AskSecret 'Password for logging into the app (Enter = generate one)' -AllowEmpty
    if (-not $p) {
        $p = New-Password
        Write-Host ''
        Write-Host "   Generated password: $p" -ForegroundColor White -BackgroundColor DarkBlue
        Write-Host '   Write it down - you log into the app with it.' -ForegroundColor White
    } elseif ($p.Length -lt 6) {
        Warn 'Password too short (min 6) - generating one.'
        $p = New-Password
        Write-Host "   Generated password: $p" -ForegroundColor White -BackgroundColor DarkBlue
    }
    $script:AppPassword = $p

    Info ''
    $mail = Ask 'Email address to watch' (Def $State.imapUser '')
    while (-not $mail) { $mail = Ask 'Email address to watch' }
    $isGmail = $mail -match '@(gmail|googlemail)\.com$'
    if ($isGmail) {
        $imapHost = 'imap.gmail.com'
        Info 'Gmail needs an "App password" (your normal password will not work).'
        Info 'It requires 2-Step Verification to be on in your Google account.'
        if (AskYes 'Open the Google page to create one?' $true) {
            Start-Process 'https://myaccount.google.com/apppasswords'
        }
        $imapPass = (AskSecret 'App password (16 letters)') -replace '\s', ''
    } else {
        $imapHost = Ask 'IMAP server' (Def $State.imapHost "mail.$RootDomain")
        $imapPass = AskSecret "Mailbox password for $mail"
    }

    Info ''
    $docsUrl = (Ask 'Doc transcriber address' (Def $State.docsUrl 'https://docs.rtdalia.com')).TrimEnd('/')
    while ($true) {
        $pin = AskSecret "Access PIN for $docsUrl"
        try {
            $r = Send-Http $Site 'GET' "$docsUrl/api/state-version" $null @{ 'X-Sync-Token' = $pin }
            if ($r.Code -eq 200) { Ok 'PIN is correct'; break }
            if ($r.Code -eq 401) { Warn 'Wrong PIN, try again.'; continue }
            Warn "Unexpected response from docs (HTTP $($r.Code)) - continuing."
            break
        } catch {
            Warn ('Cannot reach docs: ' + (Err-Text $_) + ' - continuing.')
            break
        }
    }

    $config = @(
        '<?php'
        '// Written by deploy.ps1 - can also be edited by hand (see config.sample.php).'
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
    Ok 'config.php uploaded'

    $State.imapUser = $mail
    $State.imapHost = $imapHost
    $State.docsUrl = $docsUrl
    $State.configured = $true
    Save-State
}

# ======================================================================

Write-Host ''
Write-Host '  Mail assistant - install on the hosting' -ForegroundColor White
Write-Host '  ------------------------------------------'
if (-not (Test-Path (Join-Path $AppDir 'api.php'))) { Stop-With "Run the script from the mail-assistant folder ($AppDir)." }

# ---- 1. cPanel ----
Step 'cPanel login'
Info 'Use the login you use for cPanel at SuperHosting (not an FTP account).'
Info 'If cPanel has two-factor login, use an API token: cPanel > Security > Manage API Tokens.'
$cpHost = Def $State.cpanelHost 'rtdalia.com:2083'
while ($true) {
    $cpHost = (Ask 'cPanel address' $cpHost) -replace '^https?://', '' -replace '/.*$', ''
    if ($cpHost -notmatch ':\d+$') { $cpHost += ':2083' }
    $CpBase = "https://$cpHost"
    $CpUser = Ask 'cPanel username' (Def $State.cpanelUser '')
    $secret = AskSecret 'cPanel password (or API token)'
    if ($secret -cmatch '^[A-Z0-9]{32}$') { # API токените са 32 главни букви/цифри
        $CpAuth = "cpanel ${CpUser}:$secret"
    } else {
        $CpAuth = 'Basic ' + [Convert]::ToBase64String([Text.Encoding]::UTF8.GetBytes("${CpUser}:$secret"))
    }
    try {
        $domains = Uapi 'DomainInfo' 'list_domains'
        if (-not $domains.main_domain) { throw 'cPanel returned no domain list.' }
        Ok "Logged in as $CpUser ($($domains.main_domain))"
        break
    } catch {
        $m = Err-Text $_
        if ($m -match 'SSL|certificate|trust') { $m += ' - try the server hostname from your SuperHosting welcome email.' }
        Warn $m
    }
}
$State.cpanelHost = $cpHost
$State.cpanelUser = $CpUser
Save-State

# ---- 2. Субдомейн ----
Step 'Subdomain'
$RootDomain = Def $State.rootDomain $domains.main_domain
$sub = Ask 'Subdomain for the app' (Def $State.sub 'inbox')
$Fqdn = "$sub.$RootDomain"
if (@($domains.sub_domains) -contains $Fqdn) {
    Ok "$Fqdn already exists"
} else {
    if (-not (AskYes "Create $Fqdn?" $true)) { Stop-With 'Cancelled.' }
    try {
        [void](Uapi 'SubDomain' 'addsubdomain' ([ordered]@{ domain = $sub; rootdomain = $RootDomain; dir = $Fqdn }))
    } catch {
        # Някои сървъри позволяват document root само в public_html.
        Warn (Err-Text $_)
        [void](Uapi 'SubDomain' 'addsubdomain' ([ordered]@{ domain = $sub; rootdomain = $RootDomain; dir = "public_html/$Fqdn" }))
    }
    Ok "Created $Fqdn"
}
$info = Uapi 'DomainInfo' 'single_domain_data' @{ domain = $Fqdn }
$DocRoot = [string]$info.documentroot
if (-not $DocRoot) { Stop-With "cPanel did not return the folder of $Fqdn." }
Info "Folder: $DocRoot"
$State.rootDomain = $RootDomain
$State.sub = $sub
Save-State
$SiteBase = "https://$Fqdn"

# ---- 3. Качване на файловете ----
Step 'Uploading files'
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
Ok "Uploaded $(@($files).Count) files"

# ---- 4. SSL и PHP ----
Step 'SSL certificate and PHP'
try { [void](Uapi 'SSL' 'start_autossl_check'); Info 'Requested a free SSL certificate (AutoSSL).' } catch { }
$deadline = (Get-Date).AddMinutes(20)
$phpFixed = $false
$lastErr = ''
$waited = $false
while ($true) {
    try {
        $r = Site-Get 'list'
        if ($r.Body -match 'PHP 8\.0') {
            if ($phpFixed) { Stop-With 'The site is still on old PHP. Set PHP 8.1+ in cPanel > MultiPHP Manager and run the script again.' }
            Warn 'The site runs an old PHP version - switching it.'
            try {
                $inst = Uapi 'LangPHP' 'php_get_installed_versions'
                $best = @($inst.versions) | Where-Object { $_ -match '\d{2}$' } |
                    Sort-Object { [int]($_ -replace '\D', '') } | Select-Object -Last 1
                [void](Uapi 'LangPHP' 'php_set_vhost_versions' ([ordered]@{ version = $best; vhost = $Fqdn }))
                Ok "PHP switched to $best"
            } catch {
                Stop-With ('Could not switch PHP (' + (Err-Text $_) + '). Set PHP 8.1+ in cPanel > MultiPHP Manager and run the script again.')
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
        Stop-With "$SiteBase is not responding yet ($lastErr). SSL and DNS for a new subdomain can take up to an hour - run the script again later."
    }
    if (-not $waited) { Info 'Waiting for the site to work over HTTPS (usually a few minutes)'; $waited = $true }
    Write-Host '.' -NoNewline
    Start-Sleep -Seconds 15
}
if ($waited) { Write-Host '' }
Ok "$SiteBase is up"

# ---- 5. config.php ----
$AppPassword = $null
$keep = $false
if ($State.configured -and $r.Code -eq 401 -and -not $Reconfigure) {
    $keep = AskYes 'Settings (mail, PIN) are already uploaded. Keep them?' $true
}
if (-not $keep) { Configure-App }

# ---- 6. Проверка ----
Step 'Checking connections'
$settings = $null
while ($true) {
    if (-not $AppPassword) { $AppPassword = AskSecret 'App login password (for the check)' }
    $r = Site-Post 'login' @{ password = $AppPassword }
    if ($r.Code -ne 200) {
        Warn 'The app did not accept the password.'
        $AppPassword = $null
        continue
    }
    $t = (Site-Get 'test').Body | ConvertFrom-Json
    if ($t.imap.ok) { Ok ('Mailbox: ' + $State.imapUser) } else { Warn ('Mailbox: ' + $t.imap.error) }
    if ($t.docs.ok) { Ok 'docs.rtdalia.com: PIN accepted' } else { Warn ('docs: ' + $t.docs.error) }
    if ($t.imap.ok -and $t.docs.ok) {
        $settings = ((Site-Get 'settings').Body | ConvertFrom-Json).settings
        break
    }
    if (-not (AskYes 'Enter the details again?' $true)) { break }
    Configure-App
}

# ---- 7. Cron ----
Step 'Automatic mail check (cron)'
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
        Ok 'The cron job already exists'
    } elseif (AskYes 'Add a mail check every 15 minutes?' $true) {
        [void](Api2 'Cron' 'add_line' ([ordered]@{
            command = $cronCmd; minute = '*/15'; hour = '*'; day = '*'; month = '*'; weekday = '*'
        }))
        Ok 'Added: every 15 minutes'
        Info $cronCmd
    }
} catch {
    Warn ('Could not add the cron job (' + (Err-Text $_) + '). Add it by hand in cPanel > Cron Jobs:')
    Info $cronCmd
}

Write-Host ''
Write-Host "  Done: $SiteBase" -ForegroundColor Green
Info 'Senders for automatic sending are set in the app > Settings (gear icon).'
if (AskYes 'Open the app in the browser?' $true) { Start-Process "$SiteBase/" }
