<?php

namespace Webkul\Admin\Support\Contact;

use Symfony\Component\Mailer\Exception\TransportException;

/**
 * An email was about to be sent but the active mailer has no login/key
 * (ServiceHealth::hasKey('mail')). A TransportException, so every place that
 * already handles "the email could not be sent" handles this too; its message
 * says which key to add.
 */
final class MailKeyMissingException extends TransportException
{
}
