$files = Get-ChildItem -Path "backend\app" -Recurse -Filter "*.php"

foreach ($f in $files) {
    $content = Get-Content $f.FullName -Raw
    if ($content -notmatch "LaraException::make") {
        continue
    }

    $newContent = $content

    # AuthException
    $newContent = $newContent -replace "LaraException::make\(\s*'AuthForbidden'\s*,", "AuthException::forbidden("
    $newContent = $newContent -replace "LaraException::make\(\s*'AuthUnauthorized'\s*,", "AuthException::unauthorized("
    $newContent = $newContent -replace "LaraException::make\(\s*'AuthSessionNotFound'\s*,", "AuthException::sessionNotFound("
    $newContent = $newContent -replace "LaraException::make\(\s*'AuthInvalidCredentials'\s*,", "AuthException::invalidCredentials("

    # ValidationException
    $newContent = $newContent -replace "LaraException::make\(\s*'ValidationFailed'\s*,", "ValidationException::validationFailed("

    # RateLimitException
    $newContent = $newContent -replace "LaraException::make\(\s*'RateLimited'\s*,", "RateLimitException::rateLimited("

    # NotFoundException
    $newContent = $newContent -replace "LaraException::make\(\s*'([A-Za-z0-9]+NotFound)'\s*,", "NotFoundException::notFound('`$1',"

    # DomainConflictException
    $newContent = $newContent -replace "LaraException::make\(\s*'(LicenseConflict|SerialRevoked|QuotaExhausted|PrefixInUse|PrefixForbidden)'\s*,", "DomainConflictException::conflict('`$1',"

    # InternalException
    $newContent = $newContent -replace "LaraException::make\(\s*'(ServerError|UnknownServerError|BackupStorageFailure|BackupWorkerFailure|BackupWorkerTransitionFailed)'\s*,", "InternalException::serverError('`$1',"

    if ($newContent -cne $content) {
        # Add use statements if needed
        $useStatements = "use App\Exceptions\AuthException;`r`nuse App\Exceptions\ValidationException;`r`nuse App\Exceptions\RateLimitException;`r`nuse App\Exceptions\NotFoundException;`r`nuse App\Exceptions\DomainConflictException;`r`nuse App\Exceptions\InternalException;"
        if ($newContent -notmatch "use App\\Exceptions\\AuthException;") {
            $newContent = $newContent -replace "use App\\Exceptions\\LaraException;", "use App\Exceptions\LaraException;`r`n$useStatements"
        }

        Set-Content -Path $f.FullName -Value $newContent -NoNewline
    }
}
