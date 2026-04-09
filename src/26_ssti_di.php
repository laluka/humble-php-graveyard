<?php
// Template preview endpoint for a billing platform.
// Inspired by a real-world SSTI leading to data exfiltration via a service container.
// Goal: extract the admin credentials hidden in the service container.
//
// curl -gki "http://localhost:8000/26_ssti_di.php?tpl=Hello+{{name}}"
// curl -gki "http://localhost:8000/26_ssti_di.php?tpl=Welcome+to+{{app.getName()}}"

// ---- Template engine simulation (not part of the challenge) ----

function resolveToken($current, string $part)
{
    if (str_contains($part, '(')) {
        preg_match('/^(\w+)\(([^)]*)\)$/', $part, $m);
        $args = $m[2] !== '' ? array_map(fn ($a) => trim(trim($a), "'\""), explode(',', $m[2])) : [];
        return $current->{$m[1]}(...$args);
    }

    return is_array($current) ? ($current[$part] ?? null) : ($current->$part ?? null);
}

function render(string $tpl, array $context): string
{
    return preg_replace_callback('/\{\{(.+?)\}\}/', function ($m) use ($context) {
        preg_match_all('/\w+(?:\([^)]*\))?/', $m[1], $tokens);
        $parts = $tokens[0];
        $current = $context[array_shift($parts)] ?? null;

        foreach ($parts as $part) {
            if ($current === null) {
                return null;
            }
            $current = resolveToken($current, $part);
        }

        return is_array($current) ? json_encode($current) : $current;
    }, $tpl);
}

// ---- Challenge starts here ----

class ServiceContainer
{
    private array $services = [];

    public function register(string $name, $service): void
    {
        $this->services[$name] = $service;
    }

    public function get(string $name)
    {
        return $this->services[$name] ?? null;
    }

    public function keys(): array
    {
        return array_keys($this->services);
    }
}

class BillingApp
{
    private ServiceContainer $di;
    private array $plans = [
        'starter' => ['price' => 9.99, 'users' => 1, 'storage' => '5GB'],
        'business' => ['price' => 49.99, 'users' => 10, 'storage' => '100GB'],
        'enterprise' => ['price' => 199.99, 'users' => -1, 'storage' => '1TB'],
    ];

    public function __construct(ServiceContainer $di)
    {
        $this->di = $di;
    }

    public function getName(): string
    {
        return 'BillingCorp';
    }

    public function getVersion(): string
    {
        return '2.4.1';
    }

    public function getStatus(): string
    {
        return 'operational';
    }

    public function getPlans(): array
    {
        return $this->plans;
    }

    public function getPlan(string $name): array
    {
        return $this->plans[$name] ?? ['error' => 'Plan not found'];
    }

    public function getUptime(): string
    {
        return '99.97%';
    }

    public function getRegion(): string
    {
        return 'eu-west-1';
    }

    public function getDi(): ServiceContainer
    {
        return $this->di;
    }
}

$di = new ServiceContainer();
$di->register('credentials', [
    'admin_email' => 'admin@billing.local',
    'admin_password' => 'FAKE-sup3r-s3cr3t-adm1n-p4ss',
]);
$di->register('config', [
    'app_secret' => 'FAKE-app-s3cr3t-k3y',
    'stripe_key' => 'sk_live_FAKE_str1p3_k3y',
]);

$context = [
    'name' => 'Guest',
    'year' => date('Y'),
    'app' => new BillingApp($di),
];

$tpl = $_GET['tpl'] ?? 'Hello {{name}}';

header('Content-Type: text/plain');
echo render($tpl, $context);
