<?php
// Redirect legacy login.php requests to loginadmin.php
$query = !empty($_SERVER['QUERY_STRING']) ? '?' . $_SERVER['QUERY_STRING'] : '';
header("Location: loginadmin.php" . $query);
exit;