<?php

namespace App\Services\Signing;

use RuntimeException;

/** The PDF cannot be signed safely by this engine; nothing was changed. The message is shown to the user. */
class UnsupportedPdfException extends RuntimeException {}
