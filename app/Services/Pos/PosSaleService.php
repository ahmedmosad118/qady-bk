<?php

declare(strict_types=1);

namespace App\Services\Pos;

use App\Enums\InventoryDocumentStatus;
use App\Enums\InventoryDocumentType;
use App\Models\Contact;
use App\Models\InventoryDocument;
use App\Models\InventoryDocumentItem;
use App\Models\PaymentMethod;
use App\Models\PosSale;
use App\Models\PosSaleItem;
use App\Models\PosShift;
use App\Models\PosShiftTransaction;
use App\Models\Product;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\InventoryDocumentNumberService;
use App\Services\InventoryDocumentWorkflowService;
use App\Support\Decimal;
use Carbon\Carbon;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

/**
 * Unified POS Sales domain service.
 *
 * Orchestrates internal sub-services:
 *   - PosSaleCalculator: pure BCMath invoice calculations & discount allocations
 *   - PosSaleQueryService: sales pagination, invoice lookup & detail loading
 *   - PosProductCatalogService: POS product catalog & barcode lookup
 *   - PosDefaultWarehouseResolver: default warehouse resolution
 */
class PosSaleService
{
    /**
     * Relations returned with a sale (store response, idempotent replays, sync).
     *
     * @var array<int, string>
     */
    public const SALE_RELATIONS = [
        'items.product',
        'paymentMethod',
        'customer',
        'shift',
        'creator',
        'inventoryDocuments',
    ];

    protected const CLIENT_UUID_LOCK_SECONDS = 15;

    public function __construct(
        protected PosSaleCalculator $calculator,
        protected PosSaleQueryService $queryService,
        protected PosProductCatalogService $catalogService,
        protected PosDefaultWarehouseResolver $warehouseResolver,
        protected PosNumberService $numberService,
        protected InventoryDocumentNumberService $invDocNumberService,
        protected InventoryDocumentWorkflowService $invWorkflowService,
        protected PosShiftService $shiftService,
    ) {}

    /**
     * Create a sale. Idempotent on client_uuid: retries / concurrent duplicates return the original sale.
     *
     * @param  array<string, mixed>  $data  validated payload (StorePosSaleRequest / offline sync)
     */
    public function createSale(array $data, User $user): PosSale
    {
        $clientUuid = trim((string) ($data['client_uuid'] ?? '')) ?: null;

        if ($clientUuid === null) {
            return $this->executeSaleCreation($data, $user, null);
        }

        // Fast path without locking for already-synced retries.
        if ($existing = $this->findSaleByClientUuid($clientUuid)) {
            return $existing;
        }

        return Cache::lock('pos_sale_uuid_'.$clientUuid, self::CLIENT_UUID_LOCK_SECONDS)
            ->block(self::CLIENT_UUID_LOCK_SECONDS, fn (): PosSale => $this->findSaleByClientUuid($clientUuid)
                ?? $this->executeSaleCreation($data, $user, $clientUuid));
    }

    /**
     * Bulk load products in one query, keyed by id.
     *
     * @param  array<int, int>  $productIds
     * @return Collection<int, Product>
     */
    public function loadProducts(array $productIds): Collection
    {
        return Product::query()
            ->whereIn('id', array_values(array_unique($productIds)))
            ->get()
            ->keyBy('id');
    }

    /**
     * Enforce POS selling, discount, and price-override permissions against server-side product data.
     * Uses the already bulk-loaded $products map so zero additional DB queries are executed.
     *
     * @param  array<string, mixed>  $data
     * @param  Collection<int, Product>  $products
     *
     * @throws AuthorizationException
     */
    public function authorizeSalePayload(array $data, Collection $products, User $user): void
    {
        $gate = Gate::forUser($user);

        if (! $gate->allows('pos.sell')) {
            throw new AuthorizationException(__('This action is unauthorized.'));
        }

        $invDiscVal = Decimal::parse($data['invoice_discount_value'] ?? null);
        if ($invDiscVal !== null && Decimal::isPositive($invDiscVal)) {
            if (! $gate->allows('pos.apply_invoice_discount')) {
                throw new AuthorizationException(__('This action is unauthorized.'));
            }
        }

        $items = is_array($data['items'] ?? null) ? $data['items'] : [];
        foreach ($items as $item) {
            if (! is_array($item)) {
                continue;
            }

            $itemDiscVal = Decimal::parse($item['discount_value'] ?? null);
            if ($itemDiscVal !== null && Decimal::isPositive($itemDiscVal)) {
                if (! $gate->allows('pos.apply_item_discount')) {
                    throw new AuthorizationException(__('This action is unauthorized.'));
                }
            }

            if (isset($item['unit_price']) && Decimal::parse($item['unit_price']) !== null && ! empty($item['product_id'])) {
                /** @var Product|null $product */
                $product = $products->get((int) $item['product_id']);
                if ($product !== null) {
                    $requestedPrice = Decimal::fromNumeric($item['unit_price'], Decimal::MONEY_SCALE);
                    $catalogPrice = Decimal::fromNumeric($product->selling_price, Decimal::MONEY_SCALE);

                    if (Decimal::comp($requestedPrice, $catalogPrice, Decimal::MONEY_SCALE) !== 0) {
                        if (! $gate->allows('pos.override_price')) {
                            throw new AuthorizationException(__('This action is unauthorized.'));
                        }
                    }
                }
            }
        }
    }

    /**
     * Paginate POS sales invoices with filters via PosSaleQueryService.
     *
     * @param  array<string, mixed>  $filters
     */
    public function paginateSales(array $filters, int $perPage = 15): LengthAwarePaginator
    {
        return $this->queryService->paginateSales($filters, $perPage);
    }

    /**
     * Get sale by invoice number via PosSaleQueryService.
     */
    public function getSaleByInvoiceNumber(string $invoiceNumber): PosSale
    {
        return $this->queryService->getSaleByInvoiceNumber($invoiceNumber);
    }

    /**
     * Get sale details by model via PosSaleQueryService.
     */
    public function getSaleDetails(PosSale $sale): PosSale
    {
        return $this->queryService->getSaleDetails($sale);
    }

    /**
     * Paginate POS catalog products via PosProductCatalogService.
     */
    public function paginateProducts(
        string $query = '',
        ?int $warehouseId = null,
        ?int $categoryId = null,
        int $perPage = PosProductCatalogService::DEFAULT_PER_PAGE
    ): LengthAwarePaginator {
        return $this->catalogService->paginateProducts($query, $categoryId, $perPage);
    }

    /**
     * Lookup product by exact barcode via PosProductCatalogService.
     */
    public function lookupBarcode(string $barcode, ?int $warehouseId = null): ?Product
    {
        return $this->catalogService->lookupBarcode($barcode);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    protected function executeSaleCreation(array $data, User $user, ?string $clientUuid): PosSale
    {
        try {
            return DB::transaction(function () use ($data, $user, $clientUuid): PosSale {
                // Lock the shift row for the whole transaction to serialize expected_cash updates per shift.
                $shift = $this->lockOpenShift((int) ($data['shift_id'] ?? 0), $user);

                // Resolve the payment method, customer, and default warehouse for stock movements.
                $paymentMethod = $this->resolvePaymentMethod((int) ($data['payment_method_id'] ?? 0));
                $customer = $this->resolveCustomer($data['customer_id'] ?? null);

                // Resolve warehouse could be used in multi-warehouse scenarios.
                $warehouse = $this->warehouseResolver->resolve();

                // Normalize and consolidate items.
                $lines = $this->calculator->consolidateItems($data['items'] ?? []);

                // Load the products for the sale in one bulk query.
                $products = $this->loadProducts(array_keys($lines));

                // Verify permissions and price overrides against server-side product prices.
                $this->authorizeSalePayload($data, $products, $user);

                // Calculate invoice totals and discount allocations.
                $invoice = $this->calculator->calculate(
                    $lines,
                    $products,
                    $data['invoice_discount_type'] ?? null,
                    $data['invoice_discount_value'] ?? null
                );

                // Insert the sale, items, cash transaction (if applicable), and post the delivery document.
                $sale = $this->insertSale($shift, $paymentMethod, $customer, $invoice, $data['notes'] ?? null, $user, $clientUuid);
                $this->insertSaleItems($sale, $invoice['items']);

                if ($paymentMethod->isCash()) {
                    $this->recordCashSale($shift, $sale, $invoice['grand_total'], $user);
                }

                $document = $this->postDeliveryDocument($sale, $warehouse, $invoice['items'], $user);

                return $this->hydrateSale($sale, $shift, $paymentMethod, $customer, $user, $products, $document);
            });
        } catch (QueryException $e) {
            // Lost a race on the client_uuid unique index: return the winner's sale.
            if ($clientUuid !== null && str_contains($e->getMessage(), 'client_uuid')) {
                return $this->findSaleByClientUuid($clientUuid) ?? throw $e;
            }

            throw $e;
        }
    }

    protected function findSaleByClientUuid(string $clientUuid): ?PosSale
    {
        return PosSale::query()
            ->where('client_uuid', $clientUuid)
            ->with(self::SALE_RELATIONS)
            ->first();
    }

    /**
     * Row-lock the shift for the whole transaction (serializes expected_cash updates per shift).
     */
    protected function lockOpenShift(int $shiftId, User $user): PosShift
    {
        /** @var PosShift|null $shift */
        $shift = PosShift::query()->lockForUpdate()->find($shiftId);

        if ($shift === null) {
            throw ValidationException::withMessages(['shift_id' => [__('pos.errors.shift_not_found')]]);
        }

        if ((int) $shift->opened_by !== (int) $user->id) {
            throw ValidationException::withMessages(['shift_id' => [__('pos.errors.shift_belongs_to_another_user')]]);
        }

        if ($shift->isClosed()) {
            throw ValidationException::withMessages(['shift_id' => [__('pos.errors.shift_closed')]]);
        }

        return $shift;
    }

    protected function resolvePaymentMethod(int $paymentMethodId): PaymentMethod
    {
        return PaymentMethod::query()->find($paymentMethodId)
            ?? throw ValidationException::withMessages([
                'payment_method_id' => [__('pos.errors.payment_method_not_found')],
            ]);
    }

    /**
     * Fetch (not just check) the customer so the model can be reused as the response relation.
     */
    protected function resolveCustomer(mixed $customerId): ?Contact
    {
        if ($customerId === null || $customerId === '') {
            return null;
        }

        if (! is_numeric($customerId) || (int) $customerId <= 0) {
            throw ValidationException::withMessages([
                'customer_id' => [__('pos.errors.customer_not_found')],
            ]);
        }

        return Contact::query()->find((int) $customerId)
            ?? throw ValidationException::withMessages([
                'customer_id' => [__('pos.errors.customer_not_found')],
            ]);
    }

    /**
     * @param  array<string, mixed>  $invoice  output of PosSaleCalculator::calculate()
     */
    protected function insertSale(
        PosShift $shift,
        PaymentMethod $paymentMethod,
        ?Contact $customer,
        array $invoice,
        ?string $notes,
        User $user,
        ?string $clientUuid
    ): PosSale {
        return PosSale::query()->create([
            'shift_id' => $shift->id,
            'customer_id' => $customer?->id,
            'payment_method_id' => $paymentMethod->id,
            'subtotal' => $invoice['subtotal'],
            'items_discount_total' => $invoice['items_discount_total'],
            'invoice_discount_type' => $invoice['invoice_discount_type'],
            'invoice_discount_value' => $invoice['invoice_discount_value'],
            'invoice_discount_amount' => $invoice['invoice_discount_amount'],
            'grand_total' => $invoice['grand_total'],
            'notes' => $notes,
            'created_by' => $user->id,
            'sold_at' => Carbon::now(),
            'client_uuid' => $clientUuid,
        ]);
    }

    /**
     * One INSERT for all lines regardless of item count.
     *
     * @param  array<int, array<string, mixed>>  $items
     */
    protected function insertSaleItems(PosSale $sale, array $items): void
    {
        $now = Carbon::now();

        PosSaleItem::query()->insert(array_map(static fn (array $item): array => [
            'sale_id' => $sale->id,
            'product_id' => $item['product_id'],
            'product_snapshot' => json_encode($item['product_snapshot'], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
            'quantity' => $item['quantity'],
            'original_unit_price' => $item['original_unit_price'],
            'unit_price' => $item['unit_price'],
            'discount_type' => $item['discount_type'],
            'discount_value' => $item['discount_value'],
            'discount_amount' => $item['discount_amount'],
            'subtotal' => $item['subtotal'],
            'total' => $item['total'],
            'allocated_invoice_discount' => $item['allocated_invoice_discount'],
            'created_at' => $now,
            'updated_at' => $now,
        ], $items));
    }

    protected function recordCashSale(PosShift $shift, PosSale $sale, string $amount, User $user): void
    {
        // Attributes are assigned explicitly because PosShiftTransaction is immutable (no mass update path).
        $transaction = new PosShiftTransaction;
        $transaction->shift_id = $shift->id;
        $transaction->type = PosShiftTransaction::TYPE_CASH_SALE;
        $transaction->amount = $amount;
        $transaction->pos_sale_id = $sale->id;
        $transaction->pos_return_id = null;
        $transaction->name = __('pos.messages.cash_sale_transaction', ['invoice_number' => $sale->invoice_number]);
        $transaction->description = null;
        $transaction->created_by = $user->id;
        $transaction->created_at = Carbon::now();
        $transaction->save();

        $shift->update(['expected_cash' => $this->shiftService->calculateExpectedCash($shift)]);
    }

    /**
     * Create an approved goods-delivery document and post it (stock locks, movements, balances).
     *
     * @param  array<int, array<string, mixed>>  $items
     */
    protected function postDeliveryDocument(PosSale $sale, Warehouse $warehouse, array $items, User $user): InventoryDocument
    {
        $now = Carbon::now();

        $document = InventoryDocument::query()->create([
            'document_type' => InventoryDocumentType::GOODS_DELIVERY,
            'referenceable_type' => 'pos_sale',
            'referenceable_id' => $sale->id,
            'source_warehouse_id' => $warehouse->id,
            'destination_warehouse_id' => null,
            'adjustment_direction' => null,
            'return_direction' => null,
            'status' => InventoryDocumentStatus::APPROVED,
            'document_date' => $now->toDateString(),
            'notes' => __('pos.messages.auto_goods_delivery_notes', ['invoice_number' => $sale->invoice_number]),
            'created_by' => $user->id,
            'approved_by' => $user->id,
            'approved_at' => $now,
        ]);

        InventoryDocumentItem::query()->insert(array_map(static fn (array $item): array => [
            'inventory_document_id' => $document->id,
            'product_id' => $item['product_id'],
            'quantity' => $item['quantity'],
            'notes' => null,
            'created_at' => $now,
            'updated_at' => $now,
        ], $items));

        return $this->invWorkflowService->post($document, $user, false);
    }

    /**
     * Attach response relations from models already in memory instead of re-querying them.
     * Only sale items need a query (their ids come from the bulk insert).
     *
     * @param  Collection<int, Product>  $products
     */
    protected function hydrateSale(
        PosSale $sale,
        PosShift $shift,
        PaymentMethod $paymentMethod,
        ?Contact $customer,
        User $user,
        Collection $products,
        InventoryDocument $document
    ): PosSale {
        $sale->setRelation('shift', $shift)
            ->setRelation('paymentMethod', $paymentMethod)
            ->setRelation('customer', $customer)
            ->setRelation('creator', $user->withoutRelations())
            ->setRelation('inventoryDocuments', new Collection([$document->withoutRelations()]))
            ->load('items');

        foreach ($sale->items as $item) {
            $item->setRelation('product', $products->get($item->product_id));
        }

        return $sale;
    }
}
