<?php

namespace App\Services;

class FakeSmsProvider implements SmsProvider
{
    public function send(string $mobile, string $message): void
    {
    }
}
