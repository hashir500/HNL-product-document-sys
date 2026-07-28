<?php
session_start();

require_once __DIR__ . '/../../db.php';

function loadEnv($path) {
    if (!file_exists($path)) return;
    $lines = file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    foreach ($lines as $line) {
        if (strpos(trim($line), '#') === 0) continue;
        list($name, $value) = explode('=', $line, 2);
        $_ENV[trim($name)] = trim($value, " \"'");
    }
}

loadEnv(__DIR__ . '/../../.env');

$tenant_id     = $_ENV['AZURE_TENANT_ID'] ?? '';
$client_id     = $_ENV['AZURE_CLIENT_ID'] ?? '';
$client_secret = $_ENV['AZURE_CLIENT_SECRET'] ?? '';
$redirect_uri  = $_ENV['AZURE_REDIRECT_URI'] ?? '';

if (!isset($_GET['code']) || !isset($_GET['state']) || !isset($_SESSION['oauth2state']) || $_GET['state'] !== $_SESSION['oauth2state']) {
    unset($_SESSION['oauth2state']);
    die("Authentication failed: Invalid request or state mismatch.");
}

unset($_SESSION['oauth2state']);

$token_url = "https://login.microsoftonline.com/" . $tenant_id . "/oauth2/v2.0/token";

$post_data = [
    'client_id'     => $client_id,
    'scope'         => 'openid profile email',
    'code'          => $_GET['code'],
    'redirect_uri'  => $redirect_uri,
    'grant_type'    => 'authorization_code',
    'client_secret' => $client_secret,
];

$ch = curl_init($token_url);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($post_data));
$response = json_decode(curl_exec($ch), true);
curl_close($ch);

if (!isset($response['access_token'])) {
    die("Failed to obtain access token from Microsoft. Error: " . htmlspecialchars($response['error_description'] ?? 'Unknown error', ENT_QUOTES, 'UTF-8'));
}

$graph_url = "https://graph.microsoft.com/v1.0/me";
$ch = curl_init($graph_url);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_HTTPHEADER, [
    "Authorization: Bearer " . $response['access_token'],
    "Content-Type: application/json"
]);
$user_profile = json_decode(curl_exec($ch), true);
curl_close($ch);

$user_email = strtolower(trim($user_profile['mail'] ?? $user_profile['userPrincipalName'] ?? ''));

if (empty($user_email)) {
    die("Unable to retrieve email from Microsoft account.");
}

$stmt = $conn->prepare("SELECT id, username, useremail FROM users WHERE LOWER(useremail) = ?");
$stmt->bind_param("s", $user_email);
$stmt->execute();
$result = $stmt->get_result();

if ($row = $result->fetch_assoc()) {
    $_SESSION['user_id']   = $row['id'];
    $_SESSION['username']  = $row['username'];
    $_SESSION['useremail'] = $row['useremail'];
    $_SESSION['logged_in'] = true;

    header("Location: ../../index.php");
    exit();
} else {
    ?>
    <!DOCTYPE html>
    <html lang="en">
    <head>
      <meta charset="UTF-8">
      <title>Access Denied</title>
      <script src="https://cdn.tailwindcss.com"></script>
    </head>

    <body class="bg-gray-100 min-h-screen flex items-center justify-center font-sans p-6">
      <div class="bg-white max-w-md w-full p-8 rounded-2xl shadow-xl text-center border border-gray-200">
        <div class="inline-flex items-center justify-center w-16 h-16 bg-red-100 text-red-500 rounded-full mb-4">
          <svg class="w-8 h-8" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z"/>
          </svg>
        </div>

        <h2 class="text-2xl font-bold text-gray-800 mb-2">Access Denied</h2>
        <p class="text-gray-600 text-sm mb-4">
          The email <strong class="text-gray-900"><?php echo htmlspecialchars($user_email, ENT_QUOTES, 'UTF-8'); ?></strong> is not authorized to access this portal.
        </p>

        <a href="../../login.php" class="inline-block bg-orange-600 hover:bg-orange-700 text-white font-semibold px-6 py-2.5 rounded-lg transition text-sm">
          Try Another Account
        </a>
      </div>
    </body>
    </html>
    <?php
    exit();
}
?>