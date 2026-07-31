<?php
// auth/logout.php
require_once __DIR__ . '/../config/sesion.php';
session_unset();
session_destroy();
header('Location: login');
exit;
