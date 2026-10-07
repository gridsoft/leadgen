<?php
define('AUTH_PUBLIC_PAGE', true);
require_once __DIR__ . '/config.php';

auth_logout();
header('Location: login.php');
