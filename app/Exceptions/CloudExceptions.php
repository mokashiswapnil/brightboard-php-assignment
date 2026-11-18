<?php

namespace App\Exceptions;

use Exception;

/**
 * Thrown when a cloud resource is permanently not found (e.g. HTTP 404).
 * Must not be retried by queue workers.
 */
class CloudFileNotFoundException extends Exception
{
}

/**
 * Thrown when cloud storage encounters a non-retryable error (e.g. HTTP 400 Bad Request, 401 Unauthorized).
 */
class CloudPermanentException extends Exception
{
}
