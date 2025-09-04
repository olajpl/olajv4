<?php
// includes/captcha_math.php — generowanie prostego zadania matematycznego
session_start();

function generateCaptchaMath() {
    $a = rand(0, 15);
    $b = rand(0, 15);
    $_SESSION['captcha_math_answer'] = $a + $b;
    return "$a + $b = ?";
}

function validateCaptchaMath($answer) {
    return isset($_SESSION['captcha_math_answer']) && ((int)$answer === (int)$_SESSION['captcha_math_answer']);
}
