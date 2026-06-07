<?php
/**
 * php-vpa-app — Aplicação de referência para estudo de VPA + k6
 *
 * Endpoints:
 *   GET /health/live   → liveness probe  (OCP/Kubernetes)
 *   GET /health/ready  → readiness probe (OCP/Kubernetes)
 *   GET /load          → endpoint de carga para testes k6
 *   GET /info          → diagnóstico: mostra resources atuais do pod
 */

declare(strict_types=1);

// ── Roteamento simples sem framework ─────────────────────────────
$uri    = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

// Sempre responde JSON
header('Content-Type: application/json; charset=utf-8');

match (true) {
    $uri === '/health/live'  => handleLiveness(),
    $uri === '/health/ready' => handleReadiness(),
    $uri === '/load'         => handleLoad(),
    $uri === '/info'         => handleInfo(),
    default                  => handleNotFound($uri),
};

// ─────────────────────────────────────────────────────────────────
// LIVENESS PROBE
// Responde 200 enquanto o processo PHP está vivo.
// O OCP mata e recria o pod se isso falhar.
// ─────────────────────────────────────────────────────────────────
function handleLiveness(): void
{
    http_response_code(200);
    echo json_encode([
        'status'    => 'UP',
        'probe'     => 'liveness',
        'timestamp' => date('c'),
        'pid'       => getmypid(),
    ]);
}

// ─────────────────────────────────────────────────────────────────
// READINESS PROBE
// Indica se o pod está pronto para receber tráfego.
// Simula uma checagem leve de dependência (ex: arquivo de lock,
// conexão com banco — aqui simplificado para o lab).
// ─────────────────────────────────────────────────────────────────
function handleReadiness(): void
{
    // Simula checagem de dependência: verifica se o diretório /tmp
    // está acessível (substitua por ping ao banco em produção)
    $ready = is_writable('/tmp');

    if (!$ready) {
        http_response_code(503);
        echo json_encode([
            'status'  => 'DOWN',
            'probe'   => 'readiness',
            'reason'  => '/tmp not writable',
            'timestamp' => date('c'),
        ]);
        return;
    }

    http_response_code(200);
    echo json_encode([
        'status'    => 'UP',
        'probe'     => 'readiness',
        'timestamp' => date('c'),
    ]);
}

// ─────────────────────────────────────────────────────────────────
// ENDPOINT DE CARGA — alvo do k6
//
// Simula trabalho real com três perfis controlados via query param:
//   ?profile=light   → operação leve   (default)
//   ?profile=medium  → operação média  (CPU + memória moderados)
//   ?profile=heavy   → operação pesada (CPU intensivo)
//
// Exemplos:
//   GET /load
//   GET /load?profile=medium
//   GET /load?profile=heavy&iterations=500
// ─────────────────────────────────────────────────────────────────
function handleLoad(): void
{
    $start   = hrtime(true);
    $profile = $_GET['profile'] ?? 'light';

    $result = match ($profile) {
        'heavy'  => runHeavyWork((int)($_GET['iterations'] ?? 1000)),
        'medium' => runMediumWork((int)($_GET['iterations'] ?? 200)),
        default  => runLightWork(),
    };

    $durationMs = round((hrtime(true) - $start) / 1_000_000, 2);

    http_response_code(200);
    echo json_encode([
        'status'      => 'ok',
        'profile'     => $profile,
        'duration_ms' => $durationMs,
        'result'      => $result,
        'memory_peak' => formatBytes(memory_get_peak_usage(true)),
        'timestamp'   => date('c'),
    ]);
}

/**
 * Trabalho LEVE: retorna dados simples.
 * Simula um endpoint de consulta de cache / resposta rápida.
 */
function runLightWork(): array
{
    return [
        'operation' => 'cache_lookup',
        'items'     => range(1, 10),
        'sum'       => array_sum(range(1, 10)),
    ];
}

/**
 * Trabalho MÉDIO: alocação de array + operações de string.
 * Simula processamento de uma lista de registros (ex: query result).
 */
function runMediumWork(int $iterations): array
{
    $data = [];
    for ($i = 0; $i < $iterations; $i++) {
        $data[] = [
            'id'   => $i,
            'hash' => sha1("record-{$i}-" . uniqid('', true)),
            'val'  => random_int(1, 10000),
        ];
    }

    $sum = array_sum(array_column($data, 'val'));

    return [
        'operation'  => 'record_processing',
        'iterations' => $iterations,
        'total'      => $sum,
        'average'    => round($sum / max(1, $iterations), 2),
    ];
}

/**
 * Trabalho PESADO: cálculo de hashes encadeados + alocação de memória.
 * Simula processamento criptográfico ou transformação de dados em lote.
 * Útil para forçar consumo de CPU que o VPA vai aprender.
 */
function runHeavyWork(int $iterations): array
{
    $hash    = str_repeat('seed', 64); // ~256 bytes de seed
    $payload = [];

    for ($i = 0; $i < $iterations; $i++) {
        // Encadeia hashes para pressionar CPU
        $hash      = hash('sha256', $hash . $i);
        $payload[] = $hash;

        // A cada 100 iterações descarta para simular GC parcial
        if ($i % 100 === 0) {
            $payload = array_slice($payload, -50);
        }
    }

    return [
        'operation'  => 'hash_chain',
        'iterations' => $iterations,
        'final_hash' => substr(end($payload) ?: '', 0, 16) . '...',
        'rounds'     => ceil($iterations / 100),
    ];
}

// ─────────────────────────────────────────────────────────────────
// INFO — diagnóstico do pod (útil para observar o que o VPA aplicou)
// ─────────────────────────────────────────────────────────────────
function handleInfo(): void
{
    // Lê os cgroups para exibir os limits reais do container
    // (o que o VPA efetivamente injetou nos requests/limits do pod)
    $cpuLimit    = readCgroupValue('/sys/fs/cgroup/cpu/cpu.cfs_quota_us');
    $cpuPeriod   = readCgroupValue('/sys/fs/cgroup/cpu/cpu.cfs_period_us');
    $memoryLimit = readCgroupValue('/sys/fs/cgroup/memory/memory.limit_in_bytes');

    $cpuCores = ($cpuLimit > 0 && $cpuPeriod > 0)
        ? round($cpuLimit / $cpuPeriod, 3)
        : 'unlimited';

    http_response_code(200);
    echo json_encode([
        'php_version'     => PHP_VERSION,
        'hostname'        => gethostname(),
        'pid'             => getmypid(),
        'memory_limit_php'=> ini_get('memory_limit'),
        'memory_current'  => formatBytes(memory_get_usage(true)),
        'container_limits' => [
            'cpu_cores'  => $cpuCores,
            'memory_bytes' => $memoryLimit > 0
                ? formatBytes((int)$memoryLimit)
                : 'unlimited',
        ],
        // Variáveis de ambiente injetadas pelo OpenShift/VPA
        'env' => [
            'POD_NAME'      => getenv('POD_NAME')      ?: 'n/a',
            'POD_NAMESPACE' => getenv('POD_NAMESPACE') ?: 'n/a',
            'NODE_NAME'     => getenv('NODE_NAME')      ?: 'n/a',
        ],
        'timestamp' => date('c'),
    ]);
}

function handleNotFound(string $uri): void
{
    http_response_code(404);
    echo json_encode([
        'error'     => 'not found',
        'path'      => $uri,
        'available' => ['/health/live', '/health/ready', '/load', '/info'],
    ]);
}

// ── Helpers ──────────────────────────────────────────────────────

function formatBytes(int $bytes): string
{
    if ($bytes <= 0) return '0 B';
    $units = ['B', 'KB', 'MB', 'GB'];
    $i = (int) floor(log($bytes, 1024));
    return round($bytes / pow(1024, $i), 2) . ' ' . $units[min($i, 3)];
}

function readCgroupValue(string $path): int
{
    if (!file_exists($path)) return -1;
    $val = trim((string) file_get_contents($path));
    return is_numeric($val) ? (int) $val : -1;
}