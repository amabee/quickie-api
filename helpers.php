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
    $interval = 0;

    // Determine the interval based on the selected duration
    if (strpos($expiry_duration, 'Mins') !== false) {
        $interval = (int) filter_var($expiry_duration, FILTER_SANITIZE_NUMBER_INT);
        $expiry = new DateTime();
        $expiry->add(new DateInterval('PT' . $interval . 'M'));
    } elseif (strpos($expiry_duration, 'Hour') !== false) {
        $interval = (int) filter_var($expiry_duration, FILTER_SANITIZE_NUMBER_INT);
        $expiry = new DateTime();
        $expiry->add(new DateInterval('PT' . $interval . 'H'));
    } else {
        // Handle other cases as needed
        return null; // or throw an error
    }

    return $expiry->format('Y-m-d H:i:s');
}

?>