<?php

declare(strict_types=1);

namespace SymPress\Runtime\Download;

use RuntimeException;

/** Contains only diagnostics safe to show without exposing a URL or response body. */
final class DownloadException extends RuntimeException
{
}
