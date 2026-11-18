<?php

declare(strict_types=1);

namespace Tests\Feature\Inventory;

use App\Enums\InventoryDocumentStatus;
use App\Enums\InventoryDocumentType;
use App\Enums\PaymentMethodType;
use App\Models\InventoryDocument;
use App\Models\InventoryDocumentItem;
use App\Models\PaymentMethod;
use App\Models\PosSale;
use App\Models\PosShift;
use App\Models\PosShiftTransaction;
use App\Models\Product;
use App\Models\StockMovement;
use App\Models\User;
use App\Models\Warehouse;
use App\Models\WarehouseStock;
use App\Services\InventoryDocumentWorkflowService;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use PDO;
use RuntimeException;
use Tests\TestCase;
use Throwable;

/**
 * Multi-process OS concurrency tests backed by independent MySQL InnoDB connections.
 *
 * Uses proc_open() child PHP processes synchronized via an explicit filesystem
 * barrier so independent connections execute critical sections simultaneously.
 */
class StockConcurrencyTest extends TestCase
{
    private const CONCURRENCY_DB = 'qady_concurrency_test';

    private static bool $schemaReady = false;

    private static bool $mysqlAvailable = false;

    private static ?string $unavailableReason = null;

    private ?string $previousDefaultConnection = null;

    private string $tempDir = '';

    public static function setUpBeforeClass(): void
    {
        parent::setUpBeforeClass();

        if (self::$schemaReady || self::$unavailableReason !== null) {
            return;
        }

        try {
            $pdo = new PDO('mysql:host=127.0.0.1;port=3306', 'root', '', [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_TIMEOUT => 2,
            ]);

            if (self::CONCURRENCY_DB === '' || self::CONCURRENCY_DB === 'kady') {
                throw new RuntimeException('Invalid concurrency database target.');
            }

            $pdo->exec('CREATE DATABASE IF NOT EXISTS `' . self::CONCURRENCY_DB . '` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
            self::$mysqlAvailable = true;
        } catch (Throwable $e) {
            self::$mysqlAvailable = false;
            self::$unavailableReason = $e->getMessage();
        }
    }

    public static function tearDownAfterClass(): void
    {
        if (self::$mysqlAvailable) {
            try {
                DB::purge('mysql_conc');
                $pdo = new PDO('mysql:host=127.0.0.1;port=3306', 'root', '', [
                    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                ]);
                $pdo->exec('DROP DATABASE IF EXISTS `' . self::CONCURRENCY_DB . '`');
            } catch (Throwable) {
                // Best-effort cleanup
            }
        }

        parent::tearDownAfterClass();
    }

    protected function setUp(): void
    {
        parent::setUp();

        if (! self::$mysqlAvailable) {
            $this->markTestSkipped('MySQL unavailable for multi-process concurrency test: ' . (self::$unavailableReason ?? 'unknown'));
        }

        $this->previousDefaultConnection = DB::getDefaultConnection();

        $mysqlBaseConfig = Config::get('database.connections.mysql', []);
        Config::set('database.connections.mysql_conc', array_merge($mysqlBaseConfig, [
            'driver' => 'mysql',
            'host' => '127.0.0.1',
            'port' => '3306',
            'database' => self::CONCURRENCY_DB,
            'username' => 'root',
            'password' => '',
        ]));

        DB::purge('mysql_conc');
        DB::setDefaultConnection('mysql_conc');

        if (! self::$schemaReady) {
            Artisan::call('migrate:fresh', [
                '--database' => 'mysql_conc',
                '--force' => true,
            ]);
            self::$schemaReady = true;
        } else {
            $this->truncateAllTables();
        }

        $this->tempDir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'qady_conc_' . bin2hex(random_bytes(6));
        mkdir($this->tempDir, 0777, true);
    }

    protected function tearDown(): void
    {
        if ($this->tempDir !== '' && is_dir($this->tempDir)) {
            foreach (glob($this->tempDir . DIRECTORY_SEPARATOR . '*') ?: [] as $file) {
                @unlink($file);
            }
            @rmdir($this->tempDir);
        }

        if ($this->previousDefaultConnection !== null) {
            DB::purge('mysql_conc');
            DB::setDefaultConnection($this->previousDefaultConnection);
        }

        parent::tearDown();
    }

    protected function truncateAllTables(): void
    {
        $conn = DB::connection('mysql_conc');
        $tables = $conn->select('SHOW TABLES');
        $key = 'Tables_in_' . self::CONCURRENCY_DB;

        $conn->statement('SET FOREIGN_KEY_CHECKS = 0');
        try {
            foreach ($tables as $row) {
                $tableName = $row->{$key} ?? reset((array) $row);
                if ($tableName !== 'migrations') {
                    $conn->statement("TRUNCATE TABLE `{$tableName}`");
                }
            }
        } finally {
            $conn->statement('SET FOREIGN_KEY_CHECKS = 1');
        }
    }

    public function test_concurrent_outbound_movements_on_same_stock_prevent_lost_updates_and_negative_stock(): void
    {
        $user = User::factory()->create();
        $wh = Warehouse::query()->create([
            'code' => 'MAIN',
            'name' => 'Main Warehouse',
            'address' => 'Cairo',
            'is_default' => true,
        ]);
        $product = Product::factory()->create(['purchase_price' => 50, 'selling_price' => 100]);

        WarehouseStock::query()->create([
            'warehouse_id' => $wh->id,
            'product_id' => $product->id,
            'quantity' => '10.000',
        ]);

        // Part A: Compatible concurrent deductions (10 - 4 - 3 = 3) must both commit cleanly
        $doc1 = $this->createApprovedDoc('DOC-CONC-OUT-1', InventoryDocumentType::GOODS_DELIVERY, $user->id, $wh->id, null, [
            ['product_id' => $product->id, 'quantity' => '4.000'],
        ]);
        $doc2 = $this->createApprovedDoc('DOC-CONC-OUT-2', InventoryDocumentType::GOODS_DELIVERY, $user->id, $wh->id, null, [
            ['product_id' => $product->id, 'quantity' => '3.000'],
        ]);

        $resultsA = $this->runConcurrentWorkers([
            ['action' => 'post_doc', 'document_id' => $doc1->id, 'user_id' => $user->id],
            ['action' => 'post_doc', 'document_id' => $doc2->id, 'user_id' => $user->id],
        ]);

        $this->assertSame(['ok', 'ok'], array_column($resultsA, 'status'), 'Both compatible concurrent deductions must succeed: ' . json_encode($resultsA));
        $this->assertSame('3.000', (string) WarehouseStock::query()->where('warehouse_id', $wh->id)->where('product_id', $product->id)->value('quantity'));

        // Part B: Competing deductions for remaining 3.000 (2.000 each => 4.000 > 3.000)
        $doc3 = $this->createApprovedDoc('DOC-CONC-OUT-3', InventoryDocumentType::GOODS_DELIVERY, $user->id, $wh->id, null, [
            ['product_id' => $product->id, 'quantity' => '2.000'],
        ]);
        $doc4 = $this->createApprovedDoc('DOC-CONC-OUT-4', InventoryDocumentType::GOODS_DELIVERY, $user->id, $wh->id, null, [
            ['product_id' => $product->id, 'quantity' => '2.000'],
        ]);

        $resultsB = $this->runConcurrentWorkers([
            ['action' => 'post_doc', 'document_id' => $doc3->id, 'user_id' => $user->id],
            ['action' => 'post_doc', 'document_id' => $doc4->id, 'user_id' => $user->id],
        ]);

        $okCount = count(array_filter($resultsB, static fn (array $r): bool => $r['status'] === 'ok'));
        $failCount = count(array_filter($resultsB, static fn (array $r): bool => $r['status'] === 'error'));

        $this->assertSame(1, $okCount, 'Exactly one oversubscribed deduction may succeed: ' . json_encode($resultsB));
        $this->assertSame(1, $failCount, 'Exactly one oversubscribed deduction must fail: ' . json_encode($resultsB));
        $this->assertSame('1.000', (string) WarehouseStock::query()->where('warehouse_id', $wh->id)->where('product_id', $product->id)->value('quantity'));
        $this->assertSame(3, StockMovement::query()->where('product_id', $product->id)->count());
    }

    public function test_concurrent_missing_stock_creation_succeeds_without_deadlock_or_duplicate_rows(): void
    {
        $user = User::factory()->create();
        $wh = Warehouse::query()->create([
            'code' => 'MAIN',
            'name' => 'Main Warehouse',
            'address' => 'Cairo',
            'is_default' => true,
        ]);
        $prodA = Product::factory()->create();
        $prodB = Product::factory()->create();

        $this->assertSame(0, WarehouseStock::query()->count());

        // Doc 1 inserts [prodA, prodB], Doc 2 inserts in reverse order [prodB, prodA] simultaneously
        $doc1 = $this->createApprovedDoc('DOC-CONC-NEW-1', InventoryDocumentType::GOODS_RECEIPT, $user->id, null, $wh->id, [
            ['product_id' => $prodA->id, 'quantity' => '5.000'],
            ['product_id' => $prodB->id, 'quantity' => '2.000'],
        ]);
        $doc2 = $this->createApprovedDoc('DOC-CONC-NEW-2', InventoryDocumentType::GOODS_RECEIPT, $user->id, null, $wh->id, [
            ['product_id' => $prodB->id, 'quantity' => '3.000'],
            ['product_id' => $prodA->id, 'quantity' => '8.000'],
        ]);

        $results = $this->runConcurrentWorkers([
            ['action' => 'post_doc', 'document_id' => $doc1->id, 'user_id' => $user->id],
            ['action' => 'post_doc', 'document_id' => $doc2->id, 'user_id' => $user->id],
        ]);

        $this->assertSame(['ok', 'ok'], array_column($results, 'status'), 'Concurrent missing-stock creation must not deadlock: ' . json_encode($results));
        $this->assertSame(2, WarehouseStock::query()->count());
        $this->assertSame('13.000', (string) WarehouseStock::query()->where('warehouse_id', $wh->id)->where('product_id', $prodA->id)->value('quantity'));
        $this->assertSame('5.000', (string) WarehouseStock::query()->where('warehouse_id', $wh->id)->where('product_id', $prodB->id)->value('quantity'));
        $this->assertSame(InventoryDocumentStatus::POSTED, $doc1->fresh()->status);
        $this->assertSame(InventoryDocumentStatus::POSTED, $doc2->fresh()->status);
    }

    public function test_concurrent_posting_attempts_for_same_document_allow_only_one_post(): void
    {
        $user = User::factory()->create();
        $wh = Warehouse::query()->create([
            'code' => 'MAIN',
            'name' => 'Main Warehouse',
            'address' => 'Cairo',
            'is_default' => true,
        ]);
        $product = Product::factory()->create();

        $doc = $this->createApprovedDoc('DOC-CONC-SAME-1', InventoryDocumentType::GOODS_RECEIPT, $user->id, null, $wh->id, [
            ['product_id' => $product->id, 'quantity' => '15.000'],
        ]);

        $results = $this->runConcurrentWorkers([
            ['action' => 'post_doc', 'document_id' => $doc->id, 'user_id' => $user->id],
            ['action' => 'post_doc', 'document_id' => $doc->id, 'user_id' => $user->id],
        ]);

        $okCount = count(array_filter($results, static fn (array $r): bool => $r['status'] === 'ok'));
        $failCount = count(array_filter($results, static fn (array $r): bool => $r['status'] === 'error'));

        $this->assertSame(1, $okCount, json_encode($results));
        $this->assertSame(1, $failCount, json_encode($results));
        $this->assertSame(1, StockMovement::query()->where('inventory_document_id', $doc->id)->count());
        $this->assertSame('15.000', (string) WarehouseStock::query()->where('warehouse_id', $wh->id)->where('product_id', $product->id)->value('quantity'));
        $this->assertSame(InventoryDocumentStatus::POSTED, $doc->fresh()->status);
    }

    public function test_concurrent_reversal_attempts_for_same_posted_document_allow_only_one_reversal(): void
    {
        $user = User::factory()->create();
        $wh = Warehouse::query()->create([
            'code' => 'MAIN',
            'name' => 'Main Warehouse',
            'address' => 'Cairo',
            'is_default' => true,
        ]);
        $product = Product::factory()->create();

        $doc = $this->createApprovedDoc('DOC-CONC-REV-1', InventoryDocumentType::GOODS_RECEIPT, $user->id, null, $wh->id, [
            ['product_id' => $product->id, 'quantity' => '15.000'],
        ]);

        app(InventoryDocumentWorkflowService::class)->post($doc, $user);

        $results = $this->runConcurrentWorkers([
            ['action' => 'reverse_doc', 'document_id' => $doc->id, 'user_id' => $user->id],
            ['action' => 'reverse_doc', 'document_id' => $doc->id, 'user_id' => $user->id],
        ]);

        $okCount = count(array_filter($results, static fn (array $r): bool => $r['status'] === 'ok'));
        $failCount = count(array_filter($results, static fn (array $r): bool => $r['status'] === 'error'));

        $this->assertSame(1, $okCount, json_encode($results));
        $this->assertSame(1, $failCount, json_encode($results));
        $this->assertSame(1, StockMovement::query()->where('inventory_document_id', $doc->id)->whereNotNull('reversal_of_id')->count());
        $this->assertSame('0.000', (string) WarehouseStock::query()->where('warehouse_id', $wh->id)->where('product_id', $product->id)->value('quantity'));
        $this->assertSame(InventoryDocumentStatus::REVERSED, $doc->fresh()->status);
    }

    public function test_concurrent_pos_sales_competing_for_last_stock_unit_prevent_overselling_and_roll_back_cleanly(): void
    {
        $user = User::factory()->create();
        $wh = Warehouse::query()->create([
            'code' => 'MAIN',
            'name' => 'Main Warehouse',
            'address' => 'Cairo',
            'is_default' => true,
        ]);
        $product = Product::factory()->create(['purchase_price' => 30, 'selling_price' => 100]);
        WarehouseStock::query()->create([
            'warehouse_id' => $wh->id,
            'product_id' => $product->id,
            'quantity' => '1.000',
        ]);

        $paymentMethod = PaymentMethod::query()->create([
            'name' => 'Cash',
            'type' => PaymentMethodType::CASH,
        ]);
        $shift = PosShift::query()->create([
            'shift_number' => 'SHF-CONC-001',
            'opened_by' => $user->id,
            'opening_balance' => '100.00',
            'opened_at' => now(),
        ]);

        $results = $this->runConcurrentWorkers([
            ['action' => 'pos_sale', 'user_id' => $user->id, 'shift_id' => $shift->id, 'payment_method_id' => $paymentMethod->id, 'product_id' => $product->id, 'quantity' => '1.000'],
            ['action' => 'pos_sale', 'user_id' => $user->id, 'shift_id' => $shift->id, 'payment_method_id' => $paymentMethod->id, 'product_id' => $product->id, 'quantity' => '1.000'],
        ]);

        $okCount = count(array_filter($results, static fn (array $r): bool => $r['status'] === 'ok'));
        $failCount = count(array_filter($results, static fn (array $r): bool => $r['status'] === 'error'));

        $this->assertSame(1, $okCount, json_encode($results));
        $this->assertSame(1, $failCount, json_encode($results));
        $this->assertSame(1, PosSale::query()->count());
        $this->assertSame(1, PosShiftTransaction::query()->where('shift_id', $shift->id)->count());
        $this->assertSame(1, InventoryDocument::query()->count());
        $this->assertSame('0.000', (string) WarehouseStock::query()->where('warehouse_id', $wh->id)->where('product_id', $product->id)->value('quantity'));
    }

    /**
     * @param array<int, array<string, mixed>> $items
     */
    protected function createApprovedDoc(
        string $number,
        InventoryDocumentType $type,
        int $userId,
        ?int $sourceWhId,
        ?int $destWhId,
        array $items
    ): InventoryDocument {
        $doc = InventoryDocument::query()->create([
            'document_number' => $number,
            'document_type' => $type,
            'status' => InventoryDocumentStatus::APPROVED,
            'source_warehouse_id' => $sourceWhId,
            'destination_warehouse_id' => $destWhId,
            'document_date' => '2026-11-18',
            'created_by' => $userId,
            'approved_by' => $userId,
            'approved_at' => now(),
        ]);

        foreach ($items as $item) {
            InventoryDocumentItem::query()->create([
                'inventory_document_id' => $doc->id,
                'product_id' => $item['product_id'],
                'quantity' => $item['quantity'],
            ]);
        }

        return $doc;
    }

    /**
     * Spawn independent OS PHP processes and synchronize execution at a filesystem barrier.
     *
     * @param array<int, array<string, mixed>> $jobs
     * @return array<int, array<string, mixed>>
     */
    protected function runConcurrentWorkers(array $jobs): array
    {
        $workerScript = $this->tempDir . DIRECTORY_SEPARATOR . 'worker.php';
        $goFile = $this->tempDir . DIRECTORY_SEPARATOR . 'go.flag';

        file_put_contents($workerScript, $this->buildWorkerScript());

        $baseEnv = getenv();
        $env = array_merge(is_array($baseEnv) ? $baseEnv : [], [
            'APP_ENV' => 'testing',
            'DB_CONNECTION' => 'mysql',
            'DB_HOST' => '127.0.0.1',
            'DB_PORT' => '3306',
            'DB_DATABASE' => self::CONCURRENCY_DB,
            'DB_USERNAME' => 'root',
            'DB_PASSWORD' => '',
        ]);

        $processes = [];
        $pipes = [];
        $readyFiles = [];

        foreach ($jobs as $idx => $job) {
            $readyFile = $this->tempDir . DIRECTORY_SEPARATOR . "ready_{$idx}.flag";
            $readyFiles[$idx] = $readyFile;

            $payloadJson = json_encode(array_merge($job, [
                'base_path' => base_path(),
                'target_db' => self::CONCURRENCY_DB,
                'ready_file' => $readyFile,
                'go_file' => $goFile,
            ]), JSON_THROW_ON_ERROR);

            $payloadFile = $this->tempDir . DIRECTORY_SEPARATOR . "job_{$idx}.json";
            file_put_contents($payloadFile, $payloadJson);

            $cmd = [PHP_BINARY, $workerScript, $payloadFile];
            $proc = proc_open($cmd, [
                0 => ['pipe', 'r'],
                1 => ['pipe', 'w'],
                2 => ['pipe', 'w'],
            ], $procPipes, base_path(), $env);

            if (! is_resource($proc)) {
                throw new RuntimeException("Failed to spawn concurrency worker #{$idx}.");
            }

            fclose($procPipes[0]);
            $processes[$idx] = $proc;
            $pipes[$idx] = $procPipes;
        }

        $deadline = microtime(true) + 15.0;
        foreach ($readyFiles as $readyFile) {
            while (! is_file($readyFile)) {
                if (microtime(true) > $deadline) {
                    foreach ($processes as $proc) {
                        @proc_terminate($proc);
                    }
                    $this->fail('Timed out waiting for concurrent workers to reach barrier.');
                }
                usleep(2000);
            }
        }

        file_put_contents($goFile, '1');

        $outputs = [];
        foreach ($processes as $idx => $proc) {
            $stdout = stream_get_contents($pipes[$idx][1]);
            $stderr = stream_get_contents($pipes[$idx][2]);
            fclose($pipes[$idx][1]);
            fclose($pipes[$idx][2]);
            $exitCode = proc_close($proc);

            $decoded = json_decode(trim((string) $stdout), true);
            if (! is_array($decoded)) {
                $this->fail("Worker #{$idx} failed (exit={$exitCode}) stdout=[{$stdout}] stderr=[{$stderr}]");
            }
            $outputs[$idx] = $decoded;
        }

        return $outputs;
    }

    protected function buildWorkerScript(): string
    {
        return <<<'PHP'
<?php

declare(strict_types=1);

$payload = json_decode((string) file_get_contents($argv[1]), true, 512, JSON_THROW_ON_ERROR);
$basePath = $payload['base_path'];

require $basePath . '/vendor/autoload.php';
$app = require $basePath . '/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

if (Illuminate\Support\Facades\DB::connection()->getDatabaseName() !== $payload['target_db']) {
    echo json_encode(['status' => 'fatal', 'message' => 'Unsafe DB target: ' . Illuminate\Support\Facades\DB::connection()->getDatabaseName()]);
    exit(1);
}

Illuminate\Support\Facades\DB::connection()->getPdo();

file_put_contents($payload['ready_file'], '1');

while (! is_file($payload['go_file'])) {
    usleep(500);
}

try {
    $user = App\Models\User::query()->findOrFail((int) $payload['user_id']);
    $workflow = $app->make(App\Services\InventoryDocumentWorkflowService::class);

    switch ($payload['action']) {
        case 'post_doc':
            $doc = App\Models\InventoryDocument::query()->findOrFail((int) $payload['document_id']);
            $posted = $workflow->post($doc, $user, false);
            echo json_encode(['status' => 'ok', 'doc_status' => $posted->status->value]);
            break;

        case 'reverse_doc':
            $doc = App\Models\InventoryDocument::query()->findOrFail((int) $payload['document_id']);
            $reversed = $workflow->reverse($doc, $user, 'Concurrent reversal test');
            echo json_encode(['status' => 'ok', 'doc_status' => $reversed->status->value]);
            break;

        case 'pos_sale':
            $saleService = $app->make(App\Services\Pos\PosSaleService::class);
            $sale = $saleService->createSale([
                'shift_id' => (int) $payload['shift_id'],
                'payment_method_id' => (int) $payload['payment_method_id'],
                'amount_tendered' => 100.0,
                'items' => [
                    ['product_id' => (int) $payload['product_id'], 'quantity' => $payload['quantity']],
                ],
            ], $user);
            echo json_encode(['status' => 'ok', 'sale_id' => $sale->id]);
            break;

        default:
            throw new RuntimeException('Unknown worker action: ' . $payload['action']);
    }
} catch (Illuminate\Validation\ValidationException $e) {
    echo json_encode(['status' => 'error', 'exception' => get_class($e), 'errors' => $e->errors(), 'message' => $e->getMessage()]);
} catch (Throwable $e) {
    echo json_encode(['status' => 'error', 'exception' => get_class($e), 'message' => $e->getMessage()]);
}
PHP;
    }
}
