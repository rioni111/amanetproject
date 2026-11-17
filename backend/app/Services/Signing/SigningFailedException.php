<?php

namespace App\Services\Signing;

use RuntimeException;

/** Generation or verification of the signed PDF failed; nothing was stored. The message is shown to the user. */
class SigningFailedException extends RuntimeException {}
