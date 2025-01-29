<?php

$secret = "Pedideli12*";
$payload = '{"id":"123456","status":"autorizado"}'; // JSON exato
$signature = hash_hmac('sha256', $payload, $secret);

echo "X-NFEIO-SIGNATURE: " . $signature;
