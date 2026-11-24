<#
  FARO – One Brain® videók átalakítása titkosított HLS formátumra
  ----------------------------------------------------------------
  A videókat NEM kódolja újra (a képet változatlanul átmásolja, csak a hangot
  alakítja AAC-ra), ezért gyors, és a minőség sem romlik.

  Használat (PowerShell):
    powershell -ExecutionPolicy Bypass -File .\hls-atalakito.ps1 -Forras "D:\OneBrain videok" -Cel "D:\faro-videok"

  A forrásmappában modulonként egy almappa legyen, a nevük a modul számával kezdődjön:
    D:\OneBrain videok\1\...   (I. modul videói)
    D:\OneBrain videok\2\...   (II. modul videói)
    ...
  Egy almappán belül a fájlok név szerinti sorrendje = a videók sorrendje a weboldalon
  (pl. „01 A kineziológia kialakulása.mp4”, „02 Önvaló-Önkép.mp4” …).

  Eredmény (ezt kell feltölteni a szerverre a /home/whiteman/faro-videok mappába):
    D:\faro-videok\one-brain-1\kulcs.key   ← a sorozat titkos kulcsa (NE oszd meg!)
    D:\faro-videok\one-brain-1\01\index.m3u8 + d0000.ts, d0001.ts …
    D:\faro-videok\lista.txt                ← melyik fájlból melyik videó lett (ellenőrzéshez)

  Ha egy videó már elkészült, újrafuttatáskor kihagyja (megszakítás után folytatható).
  Egy videó újrakészítéséhez töröld a célmappájában a NN almappát, és futtasd újra.
#>
param(
  [string]$Forras,
  [string]$Cel
)

$ErrorActionPreference = 'Stop'
$elvart = @{ 1 = 19; 2 = 20; 3 = 14; 4 = 18; 5 = 11; 6 = 15 }
$kiterjesztesek = @('.mp4', '.m4v', '.mov', '.mkv', '.avi', '.mts', '.ts')

if (-not (Get-Command ffmpeg -ErrorAction SilentlyContinue)) {
  Write-Host 'Nem találom az ffmpeg programot.' -ForegroundColor Red
  Write-Host 'Telepítsd egy PowerShell ablakban ezzel:  winget install Gyan.FFmpeg'
  Write-Host 'majd nyiss új PowerShell ablakot, és futtasd újra ezt a szkriptet.'
  exit 1
}
if (-not $Forras) { $Forras = Read-Host 'A videók mappája (benne az 1, 2, 3 … almappák)' }
if (-not $Cel)    { $Cel    = Read-Host 'Hova készüljön az eredmény (pl. D:\faro-videok)' }
$Forras = $Forras.Trim('"').TrimEnd('\')
$Cel = $Cel.Trim('"').TrimEnd('\')
if (-not (Test-Path -LiteralPath $Forras -PathType Container)) { Write-Host "Nincs ilyen mappa: $Forras" -ForegroundColor Red; exit 1 }
New-Item -ItemType Directory -Force -Path $Cel | Out-Null

function Termeszetes([string]$nev) { return [regex]::Replace($nev, '\d+', { param($m) $m.Value.PadLeft(8, '0') }) }

$lista = New-Object System.Collections.Generic.List[string]
$lista.Add("FARO – videók átalakítása, " + (Get-Date -Format 'yyyy-MM-dd HH:mm'))
$hibak = 0

$almappak = Get-ChildItem -LiteralPath $Forras -Directory | Where-Object { $_.Name -match '^\s*([1-6])(\D|$)' } | Sort-Object { Termeszetes $_.Name }
if (-not $almappak) { Write-Host 'Nem találtam 1–6 számmal kezdődő almappát a forrásmappában.' -ForegroundColor Red; exit 1 }

foreach ($almappa in $almappak) {
  $null = $almappa.Name -match '^\s*([1-6])'
  $modul = [int]$Matches[1]
  $sorozat = "one-brain-$modul"
  $sorozatMappa = Join-Path $Cel $sorozat
  New-Item -ItemType Directory -Force -Path $sorozatMappa | Out-Null

  # kulcs és IV: sorozatonként egyszer készül, újrafuttatáskor megmarad
  $kulcsFajl = Join-Path $sorozatMappa 'kulcs.key'
  $ivFajl = Join-Path $sorozatMappa 'iv.txt'
  if (-not (Test-Path -LiteralPath $kulcsFajl)) {
    $rng = [System.Security.Cryptography.RandomNumberGenerator]::Create()
    $kulcs = New-Object byte[] 16; $rng.GetBytes($kulcs)
    $iv = New-Object byte[] 16; $rng.GetBytes($iv)
    [System.IO.File]::WriteAllBytes($kulcsFajl, $kulcs)
    [System.IO.File]::WriteAllText($ivFajl, (($iv | ForEach-Object { $_.ToString('x2') }) -join ''))
  }
  $ivHex = ([System.IO.File]::ReadAllText($ivFajl)).Trim()
  $kulcsInfo = Join-Path $env:TEMP "faro-kulcsinfo-$modul.txt"
  [System.IO.File]::WriteAllText($kulcsInfo, "kulcs`n$kulcsFajl`n$ivHex`n")

  $fajlok = Get-ChildItem -LiteralPath $almappa.FullName -File | Where-Object { $kiterjesztesek -contains $_.Extension.ToLower() } | Sort-Object { Termeszetes $_.Name }
  $lista.Add('')
  $lista.Add("== $sorozat  (forrás: $($almappa.Name), $($fajlok.Count) videó; a weboldalon $($elvart[$modul]) cím szerepel)")
  if ($fajlok.Count -ne $elvart[$modul]) {
    Write-Host "FIGYELEM: a(z) $modul. modulban $($fajlok.Count) videót találtam, a weboldalon $($elvart[$modul]) cím van." -ForegroundColor Yellow
  }

  $n = 0
  foreach ($f in $fajlok) {
    $n++
    $nn = '{0:D2}' -f $n
    $kimenet = Join-Path $sorozatMappa $nn
    $lista.Add("  $nn  <-  $($f.Name)")
    if (Test-Path -LiteralPath (Join-Path $kimenet 'index.m3u8')) {
      Write-Host "[$sorozat/$nn] már kész, kihagyom – $($f.Name)" -ForegroundColor DarkGray
      continue
    }
    New-Item -ItemType Directory -Force -Path $kimenet | Out-Null
    Write-Host "[$sorozat/$nn] $($f.Name)" -ForegroundColor Cyan
    $argumentumok = @(
      '-hide_banner', '-loglevel', 'error', '-stats', '-y',
      '-i', $f.FullName,
      '-map', '0:v:0', '-map', '0:a:0?',
      '-c:v', 'copy', '-c:a', 'aac', '-b:a', '160k', '-ac', '2',
      '-f', 'hls', '-hls_time', '6', '-hls_playlist_type', 'vod',
      '-hls_key_info_file', $kulcsInfo,
      '-hls_segment_filename', (Join-Path $kimenet 'd%04d.ts'),
      (Join-Path $kimenet 'index.m3u8')
    )
    & ffmpeg @argumentumok
    if ($LASTEXITCODE -ne 0 -or -not (Test-Path -LiteralPath (Join-Path $kimenet 'index.m3u8'))) {
      Write-Host "HIBA: nem sikerült átalakítani: $($f.FullName)" -ForegroundColor Red
      $lista.Add("      ^ HIBA az átalakításkor")
      Remove-Item -LiteralPath $kimenet -Recurse -Force -ErrorAction SilentlyContinue
      $hibak++
    }
  }
  Remove-Item -LiteralPath $kulcsInfo -ErrorAction SilentlyContinue
}

$listaFajl = Join-Path $Cel 'lista.txt'
[System.IO.File]::WriteAllLines($listaFajl, $lista, (New-Object System.Text.UTF8Encoding($true)))
Write-Host ''
if ($hibak) { Write-Host "Kész, de $hibak videónál hiba volt – lásd fent." -ForegroundColor Yellow }
else { Write-Host 'Kész! Minden videó elkészült.' -ForegroundColor Green }
Write-Host "Ellenőrizd a sorrendet: $listaFajl"
Write-Host "Ezután töltsd fel a(z) $Cel mappa TARTALMÁT a szerverre: /home/whiteman/faro-videok"
