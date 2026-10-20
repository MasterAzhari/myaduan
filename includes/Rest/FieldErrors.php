<?php
namespace MyAduan\Rest;

/** Validation failure carrying one message per field. */
final class FieldErrors extends \Exception
{
    public function __construct(public array $fields)
    {
        parent::__construct('invalid_fields');
    }
}
