<?php

function sanitizeInput($input)
{
    if (is_array($input)) {

        return array_map('sanitizeInput', $input);
    } else {

        return htmlspecialchars($input, ENT_QUOTES, 'UTF-8');
    }
}

function calculateExpiryDatetime($expiry_duration)
{
    $expiry = new DateTime('now', new DateTimeZone('Asia/Manila'));

    switch ($expiry_duration) {
        case '30Mins':
            $expiry->add(new DateInterval('PT30M'));
            break;
        case '1Hour':
            $expiry->add(new DateInterval('PT1H'));
            break;
        case '5Hours':
            $expiry->add(new DateInterval('PT5H'));
            break;
        case '12Hours':
            $expiry->add(new DateInterval('PT12H'));
            break;
        case '24Hours':
            $expiry->add(new DateInterval('P1D'));
            break;
        default:
            if (preg_match('/^(\d+)(Mins|Hours?)$/', $expiry_duration, $matches)) {
                $amount = (int) $matches[1];
                $unit = $matches[2];

                if ($unit === 'Mins') {
                    $expiry->add(new DateInterval('PT' . $amount . 'M'));
                } elseif ($unit === 'Hour' || $unit === 'Hours') {
                    $expiry->add(new DateInterval('PT' . $amount . 'H'));
                } else {
                    throw new Exception("Invalid duration unit");
                }
            } else {
                throw new Exception("Invalid duration format");
            }
    }

    return $expiry->format('Y-m-d H:i:s');
}

?>