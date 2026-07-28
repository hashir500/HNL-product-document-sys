<?php
session_start();

function loadEnv($path) {
    if (!file_exists($path)) return;
    $lines = file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    foreach ($lines as $line) {
        if (strpos(trim($line), '#') === 0) continue;
        list($name, $value) = explode('=', $line, 2);
        $_ENV[trim($name)] = trim($value, " \"'");
    }
}

loadEnv(__DIR__ . '/.env');

$tenant_id    = $_ENV['AZURE_TENANT_ID'] ?? '';
$client_id    = $_ENV['AZURE_CLIENT_ID'] ?? '';
$redirect_uri = $_ENV['AZURE_REDIRECT_URI'] ?? '';

$scopes = "openid profile email";

if (empty($_SESSION['oauth2state'])) {
    $_SESSION['oauth2state'] = bin2hex(random_bytes(16));
}

$auth_url = "https://login.microsoftonline.com/" . $tenant_id . "/oauth2/v2.0/authorize?" . http_build_query([
    'client_id'     => $client_id,
    'response_type' => 'code',
    'redirect_uri'  => $redirect_uri,
    'response_mode' => 'query',
    'scope'         => $scopes,
    'state'         => $_SESSION['oauth2state']
]);
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
 
  <meta http-equiv="refresh" content="1;url=<?php echo htmlspecialchars($auth_url, ENT_QUOTES, 'UTF-8'); ?>">

  <title>Redirecting to Microsoft SSO...</title>

  <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
  <script src="https://cdn.tailwindcss.com"></script>

  <style>
    :root {
      --primary-color: #f26322;
    }
    body {
      background:
        linear-gradient(135deg,
          var(--primary-color) 0%,
          color-mix(in srgb, var(--primary-color) 85%, #ffffff 15%) 45%,
          color-mix(in srgb, var(--primary-color) 75%, #000000 25%) 100%
        );
    }
  </style>

  <script>
    setTimeout(function() {
      window.location.href = <?php echo json_encode($auth_url); ?>;
    }, 1000);
  </script>
</head>

<body class="min-h-screen flex flex-col items-center justify-center font-[Inter] text-white">

  <div class="flex flex-col items-center gap-4 text-center px-6 bg-white/10 backdrop-blur-md p-8 rounded-2xl border border-white/20 shadow-2xl max-w-sm w-full">
    
    <svg class="animate-spin h-10 w-10 text-white" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24">
      <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
      <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path>
    </svg>

    <h2 class="text-2xl font-extrabold tracking-tight">Highnoon Portal</h2>
    <p class="text-white/80 text-sm">Redirecting you to Microsoft 365 for authentication...</p>

    <a href="<?php echo htmlspecialchars($auth_url, ENT_QUOTES, 'UTF-8'); ?>" class="mt-2 text-xs underline text-white/70 hover:text-white transition">
      Click here if you are not redirected automatically
    </a>
  </div>



</body>
</html>