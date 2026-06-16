<?php

declare(strict_types=1);

namespace SendByte;

/**
 * An error returned by the SendByte API, carrying the documented error
 * shape: code, message, and a docs URL when one exists.
 */
class SendByteException extends \Exception
{
    public readonly string $errorCode;
    public readonly int $status;
    public readonly ?string $docsUrl;

    public function __construct(string $errorCode, string $message, int $status, ?string $docsUrl = null)
    {
        parent::__construct($message);
        $this->errorCode = $errorCode;
        $this->status = $status;
        $this->docsUrl = $docsUrl;
    }
}
