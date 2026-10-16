<?php

namespace App\Mail;

use Illuminate\Mail\Mailable;

/** A ready made (already rendered) e-mail: the subject and the HTML body come from a company's e-mail template. */
class TemplatedMail extends Mailable
{
    public function __construct(public string $mailSubject, public string $htmlBody)
    {
    }

    public function build(): static
    {
        return $this->subject($this->mailSubject)->html($this->htmlBody);
    }
}
