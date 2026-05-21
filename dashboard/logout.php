<?php

$_SESSION = [];
session_destroy();
header('Location: /login');
exit;
