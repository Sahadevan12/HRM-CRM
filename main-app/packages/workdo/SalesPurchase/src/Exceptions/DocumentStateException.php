<?php

namespace Workdo\SalesPurchase\Exceptions;

use RuntimeException;

/** The requested action is not allowed in the document's current state (e.g. posting a posted invoice). */
class DocumentStateException extends RuntimeException
{
}
