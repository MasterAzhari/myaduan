<?php
namespace MyAduan;

/** A rule violation the user can fix; its message is safe to show. */
class WorkflowException extends \RuntimeException
{
    public function __construct(string $message, private string $error_code = 'invalid_operation', private int $status = 422)
    {
        parent::__construct($message);
    }

    public function error_code(): string
    {
        return $this->error_code;
    }

    public function status(): int
    {
        return $this->status;
    }
}
