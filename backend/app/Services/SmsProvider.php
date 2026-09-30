<?php

namespace App\Services;

interface SmsProvider
{
    public function send(string $mobile, string $message): void;
}
