param([string]$InputPdf, [string]$OutputPrefix)

$ErrorActionPreference = 'Stop'
Add-Type -AssemblyName System.Runtime.WindowsRuntime
$asTaskGeneric = [System.WindowsRuntimeSystemExtensions].GetMethods() | Where-Object { $_.Name -eq 'AsTask' -and $_.IsGenericMethod -and $_.GetGenericArguments().Count -eq 1 -and $_.GetParameters().Count -eq 1 } | Select-Object -First 1
$asTaskAction = [System.WindowsRuntimeSystemExtensions].GetMethods() | Where-Object { $_.Name -eq 'AsTask' -and -not $_.IsGenericMethod -and $_.GetParameters().Count -eq 1 } | Select-Object -First 1
function Await-WinRt($operation, [Type]$resultType) {
    $task = $asTaskGeneric.MakeGenericMethod($resultType).Invoke($null, @($operation))
    $task.Wait()
    $task.Result
}
$source = [System.IO.Path]::GetFullPath($InputPdf)
$prefix = [System.IO.Path]::GetFullPath($OutputPrefix)
$file = Await-WinRt ([Windows.Storage.StorageFile,Windows.Storage,ContentType=WindowsRuntime]::GetFileFromPathAsync($source)) ([Windows.Storage.StorageFile,Windows.Storage,ContentType=WindowsRuntime])
$document = Await-WinRt ([Windows.Data.Pdf.PdfDocument,Windows.Data.Pdf,ContentType=WindowsRuntime]::LoadFromFileAsync($file)) ([Windows.Data.Pdf.PdfDocument,Windows.Data.Pdf,ContentType=WindowsRuntime])
for ($i = 0; $i -lt $document.PageCount; $i++) {
    $page = $document.GetPage($i)
    $stream = New-Object Windows.Storage.Streams.InMemoryRandomAccessStream
    $options = New-Object Windows.Data.Pdf.PdfPageRenderOptions
    $options.DestinationWidth = 1400
    $task = $asTaskAction.Invoke($null, @($page.RenderToStreamAsync($stream, $options)))
    $task.Wait()
    $read = [System.IO.WindowsRuntimeStreamExtensions]::AsStreamForRead($stream)
    $out = [System.IO.File]::Create($prefix + '-' + ($i + 1) + '.png')
    $read.CopyTo($out)
    $out.Dispose()
    $read.Dispose()
    $page.Dispose()
}
Write-Output ($document.PageCount.ToString() + ' pages rendered at width 1400 px: ' + $prefix)
