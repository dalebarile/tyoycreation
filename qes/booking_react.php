<?php
// Seamless redirect to original booking page
$query = !empty($_SERVER['QUERY_STRING']) ? '?' . $_SERVER['QUERY_STRING'] : '';
header('Location: booking.php' . $query, true, 302);
exit;
