<?php

namespace Modules\Account\Exceptions;

use RuntimeException;

/** Base of every user-facing accounting error (the message can be shown as it is). */
class AccountingException extends RuntimeException
{
}