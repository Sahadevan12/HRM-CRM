<?php

namespace Workdo\Hrm\Exceptions;

use RuntimeException;

/** A business rule of the HR module was broken. The message is meant for the user and is shown as it is. */
class HrmException extends RuntimeException
{
}
