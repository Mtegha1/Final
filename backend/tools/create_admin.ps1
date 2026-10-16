$ErrorActionPreference = 'Stop'
$name = Read-Host 'Administrator name'
$email = Read-Host 'Administrator email'
$password = Read-Host 'Administrator password (12+ characters)' -AsSecureString
$confirmation = Read-Host 'Confirm administrator password' -AsSecureString

$passwordPointer = [IntPtr]::Zero
$confirmationPointer = [IntPtr]::Zero
try {
    $passwordPointer = [Runtime.InteropServices.Marshal]::SecureStringToBSTR($password)
    $confirmationPointer = [Runtime.InteropServices.Marshal]::SecureStringToBSTR($confirmation)
    $plainPassword = [Runtime.InteropServices.Marshal]::PtrToStringBSTR($passwordPointer)
    $plainConfirmation = [Runtime.InteropServices.Marshal]::PtrToStringBSTR($confirmationPointer)

    if ($plainPassword -cne $plainConfirmation) {
        throw 'The administrator passwords do not match.'
    }

    $env:IREA_ADMIN_NAME = $name
    $env:IREA_ADMIN_EMAIL = $email
    $env:IREA_ADMIN_PASSWORD = $plainPassword
    $phpCommand = Get-Command php -ErrorAction SilentlyContinue
    if ($phpCommand) {
        & $phpCommand.Source (Join-Path $PSScriptRoot 'create_admin.php')
    }
    else {
        $phpPath = Join-Path $PSScriptRoot '..\..\..\..\php\php.exe'
        if (-not (Test-Path $phpPath)) {
            throw 'PHP was not found. Add PHP to PATH or update the XAMPP path in this script.'
        }
        & $phpPath (Join-Path $PSScriptRoot 'create_admin.php')
    }
    if ($LASTEXITCODE -ne 0) {
        exit $LASTEXITCODE
    }
}
finally {
    Remove-Item Env:\IREA_ADMIN_NAME -ErrorAction SilentlyContinue
    Remove-Item Env:\IREA_ADMIN_EMAIL -ErrorAction SilentlyContinue
    Remove-Item Env:\IREA_ADMIN_PASSWORD -ErrorAction SilentlyContinue
    if ($passwordPointer -ne [IntPtr]::Zero) {
        [Runtime.InteropServices.Marshal]::ZeroFreeBSTR($passwordPointer)
    }
    if ($confirmationPointer -ne [IntPtr]::Zero) {
        [Runtime.InteropServices.Marshal]::ZeroFreeBSTR($confirmationPointer)
    }
    $plainPassword = $null
    $plainConfirmation = $null
    $password.Dispose()
    $confirmation.Dispose()
}
