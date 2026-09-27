<?php
require_once __DIR__ . '/includes/functions.php';

if (!empty($_SESSION['userId'])) {
    redirect(match ($_SESSION['role']) {
        'patient'    => BASE_URL . '/patient/dashboard.php',
        'donor'      => BASE_URL . '/donor/dashboard.php',
        'doctor_lab' => BASE_URL . '/doctor_lab/dashboard.php',
        'admin'      => BASE_URL . '/admpanel/dashboard.php',
        default      => BASE_URL . '/auth/login.php',
    });
}

redirect(BASE_URL . '/auth/login.php');