<?php
require_once __DIR__ . '/includes/functions.php';

if (!empty($_SESSION['userId'])) {
    redirect(match ($_SESSION['role']) {
        'patient'    => '/thalassemia/patient/dashboard.php',
        'donor'      => '/thalassemia/donor/dashboard.php',
        'doctor_lab' => '/thalassemia/doctor_lab/dashboard.php',
        'admin'      => '/thalassemia/admpanel/dashboard.php',
        default      => '/thalassemia/auth/login.php',
    });
}

redirect('/thalassemia/auth/login.php');
