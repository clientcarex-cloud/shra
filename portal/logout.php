<?php
require_once __DIR__ . '/../inc/bootstrap.php';
unset($_SESSION['cid']);
redirect('login.php');
