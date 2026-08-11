<?php
// Login as customer and capture the dashboard HTML
$base = 'http://127.0.0.1:8766';
$cookieJar = __DIR__ . '/../storage/app/.curl-cookie.txt';
@unlink($cookieJar);

// 1. GET /login to grab CSRF token
$ch = curl_init("$base/login");
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_FOLLOWLOCATION => true,
    CURLOPT_COOKIEJAR => $cookieJar,
    CURLOPT_COOKIEFILE => $cookieJar,
    CURLOPT_SSL_VERIFYPEER => false,
]);
$html = curl_exec($ch);
curl_close($ch);
if (!preg_match('/name="_token"\s+value="([^"]+)"/', $html, $m)) {
    echo "no csrf token found\n";
    exit(1);
}
$token = $m[1];
echo "csrf=$token\n";

// 2. POST /login
$ch = curl_init("$base/login");
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_FOLLOWLOCATION => true,
    CURLOPT_COOKIEJAR => $cookieJar,
    CURLOPT_COOKIEFILE => $cookieJar,
    CURLOPT_POST => true,
    CURLOPT_POSTFIELDS => http_build_query([
        '_token' => $token,
        'email' => 'customer@example.com',
        'password' => 'Password!123',
    ]),
    CURLOPT_SSL_VERIFYPEER => false,
]);
$html = curl_exec($ch);
$info = curl_getinfo($ch);
curl_close($ch);
echo "login_status=" . $info['http_code'] . " url=" . $info['url'] . "\n";

// 3. GET /dashboard
$ch = curl_init("$base/dashboard");
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_FOLLOWLOCATION => true,
    CURLOPT_COOKIEJAR => $cookieJar,
    CURLOPT_COOKIEFILE => $cookieJar,
    CURLOPT_SSL_VERIFYPEER => false,
]);
$html = curl_exec($ch);
$info = curl_getinfo($ch);
curl_close($ch);
echo "dashboard_status=" . $info['http_code'] . " bytes=" . strlen($html) . "\n";

// Save
file_put_contents(__DIR__ . '/../storage/app/customer-dashboard.html', $html);
echo "saved=" . __DIR__ . "/../storage/app/customer-dashboard.html\n";
