<?php
// Extension loader for a billing platform with a cache layer.
// Inspired by a real-world cache poisoning leading to RCE via a service container.
// Goal: achieve remote code execution by poisoning the cache.
//
// curl -gki "http://localhost:8000/27_cache_poison.php?tpl=Hello+{{name}}"
// curl -gki "http://localhost:8000/27_cache_poison.php?action=load_extension"

// ---- Template engine simulation (not part of the challenge) ----

function resolveToken($current, string $part)
{
    if (str_contains($part, '(')) {
        preg_match('/^(\w+)\(([^)]*)\)$/', $part, $m);
        $args = $m[2] !== '' ? array_map(fn ($a) => trim(trim($a), "'\""), explode(',', $m[2])) : [];
        return $current->{$m[1]}(...$args);
    }

    if (is_array($current)) {
        return $current[$part] ?? null;
    }

    return $current->$part ?? null;
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

class CacheStore
{
    private string $file;

    public function __construct(string $file)
    {
        $this->file = $file;
        if (!file_exists($file)) {
            file_put_contents($file, '{}');
        }
    }

    private function load(): array
    {
        return json_decode(file_get_contents($this->file), true) ?: [];
    }

    private function save(array $data): void
    {
        file_put_contents($this->file, json_encode($data));
    }

    public function set(string $key, string $value): string
    {
        $data = $this->load();
        $data[$key] = $value;
        $this->save($data);
        return 'OK';
    }

    public function get(string $key): ?string
    {
        return $this->load()[$key] ?? null;
    }

    public function keys(): array
    {
        return array_keys($this->load());
    }
}

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

    public function __construct(ServiceContainer $di)
    {
        $this->di = $di;
    }

    public function getName(): string
    {
        return 'BillingCorp';
    }

    public function getDi(): ServiceContainer
    {
        return $this->di;
    }
}

$cache = new CacheStore(sys_get_temp_dir() . '/billing_cache.json');
if ($cache->get('extension_url') === null) {
    $cache->set('extension_url', 'https://billing.local/extensions/invoice.php');
}

$di = new ServiceContainer();
$di->register('cache', $cache);
$di->register('config', [
    'app_secret' => 'FAKE-app-s3cr3t-k3y',
    'stripe_key' => 'sk_live_FAKE_str1p3_k3y',
]);

$context = [
    'name' => 'Guest',
    'year' => date('Y'),
    'app' => new BillingApp($di),
];

$action = $_GET['action'] ?? 'preview';
$tpl = $_GET['tpl'] ?? 'Hello {{name}}';

header('Content-Type: text/plain');

match ($action) {
    'preview' => print(render($tpl, $context)),
    'load_extension' => (function () use ($cache) {
        $url = $cache->get('extension_url');
        $code = @file_get_contents($url);
        if ($code === false) {
            echo "Failed to load extension from: $url\n";
            return;
        }
        eval('?>' . $code);
    })(),
    default => print("Unknown action.\n"),
};
