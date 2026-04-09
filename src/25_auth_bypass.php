<?php
// Billing API - only authenticated owners can access the system configuration.
// Inspired by a real-world auth bypass in a billing platform.
// Goal: read the secrets from the config endpoint without valid credentials.
//
// curl -gki "http://localhost:8000/25_auth_bypass.php?action=home"
// curl -gki "http://localhost:8000/25_auth_bypass.php?role=owner&action=config"

$secrets = [
    'stripe_secret' => 'sk_live_FAKE_r4nd0m_k3y_f0r_d3m0',
    'paypal_secret' => 'FAKE-paypal-s3cr3t-n0t-r3al',
    'smtp_password' => 'FAKE-smtp-p4ssw0rd-d3m0-0nly',
    'app_secret' => 'FAKE-app-s3cr3t-k3y-f0r-t3st1ng',
];

$owner_hash = '$2y$12$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi';

function isRoleAllowed(string $role): bool
{
    $allowed = ['viewer', 'editor', 'owner'];
    if (!in_array($role, $allowed)) {
        new \Exception('Unknown API role: ' . $role);
    }
    return true;
}

function isAuthenticated(string $role, string $password, string $hash): bool
{
    // Viewers are public, no auth needed
    if ($role === 'viewer') {
        return true;
    }
    // All other known roles require authentication
    if (in_array($role, ['editor', 'owner'])) {
        return password_verify($password, $hash);
    }
    // Unknown roles are already blocked by isRoleAllowed()
    return true;
}

$role = $_GET['role'] ?? 'viewer';
$action = $_GET['action'] ?? 'home';
$password = $_GET['password'] ?? '';
header('Content-Type: application/json');

if (!isRoleAllowed($role)) {
    echo json_encode(['error' => 'Access denied']);
    exit;
}

if (!isAuthenticated($role, $password, $owner_hash)) {
    echo json_encode(['error' => 'Authentication failed.']);
    exit;
}

$response = match ($action) {
    'home' => ['message' => "Welcome, $role user.", 'endpoints' => ['home', 'config']],
    'config' => in_array($role, ['viewer', 'editor'])
        ? ['error' => 'Requires elevated privileges.']
        : ['config' => $secrets],
    default => ['error' => "Unknown action '$action'."],
};

echo json_encode($response);
