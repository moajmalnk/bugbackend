<?php

require_once __DIR__ . '/environment.php';

class FcmConfig
{
    public static function getTokenEpoch(): string
    {
        Environment::load();
        $epoch = Environment::get('FCM_TOKEN_EPOCH', '1');
        $epoch = trim((string) $epoch);
        return $epoch !== '' ? $epoch : '1';
    }

    public static function appendEpochToPayload(array $payload): array
    {
        $payload['fcm_token_epoch'] = self::getTokenEpoch();
        return $payload;
    }
}
