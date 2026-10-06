<?php

namespace Workdo\Account\Exceptions;

/** A journal entry must have equal debits and credits, at least two lines, and valid accounts. */
class UnbalancedJournalException extends AccountingException
{
}