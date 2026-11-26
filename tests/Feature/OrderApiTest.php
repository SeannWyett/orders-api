<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Foundation\Testing\WithFaker;
use Tests\TestCase;
use App\Models\Product;
use App\Actions\PlaceOrderAction;
use App\Jobs\ProcessOrder;
use App\Mail\SendOrderConfirmation;
use App\Enums\OrderStatus;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Exception;
use Laravel\Sanctum\Sanctum;
use App\Models\User;
use App\Enums\Role;
use App\Models\Order;


class OrderApiTest extends TestCase
{
    use RefreshDatabase;

    private function payload(int $productId, int $qty = 1): array
    {
        return [
            'customer_name' => 'Ana',
            'customer_email' => 'ana@example.com',
            'items' => [['product_id' => $productId, 'quantity' => $qty, 'price' => 0.01]], // ignored by server
        ];
    }

    private function customer(string $email = 'ana@example.com'): User
    {
        return User::factory()->create(['email' => $email]);
    }

    private function staff(): User
    {
        return User::factory()->create(['role' => Role::STAFF]);
    }

    private function orderFor(string $email, ?User $owner = null): Order
    {
        Queue::fake();
        $product = Product::factory()->create(['stock' => 50]);

        return app(PlaceOrderAction::class)->execute([
            'customer_name' => 'Someone',
            'customer_email' => $email,
            'items' => [['product_id' => $product->id, 'quantity' => 1]],
        ], $owner?->id);
    }

    public function test_places_order_decrements_stock_and_dispatches_job(): void
    {
        Queue::fake();
        $product = Product::factory()->create(['price' => '10.00', 'stock' => 5]);

        $this->postJson('/api/v1/orders', $this->payload($product->id, 2))
            ->assertCreated()
            ->assertJsonPath('data.status', 'pending')
            ->assertJsonPath('data.total', '20.00');   // client price ignored

        $this->assertSame(3, $product->fresh()->stock);
        Queue::assertPushed(ProcessOrder::class);
    }

    public function test_insufficient_stock_returns_422_and_leaves_stock_unchanged(): void
    {
        Queue::fake();
        $product = Product::factory()->create(['name' => 'Mango', 'stock' => 1]);

        $this->postJson('/api/v1/orders', $this->payload($product->id, 2))
            ->assertStatus(422)
            ->assertJsonFragment(['message' => 'Insufficient stock for product: Mango.']);

        $this->assertSame(1, $product->fresh()->stock);
        Queue::assertNothingPushed();
    }

    public function test_validation_errors(): void
    {
        $this->postJson('/api/v1/orders', ['customer_email' => 'nope', 'items' => []])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['customer_name', 'customer_email', 'items']);
    }

    public function test_job_completes_order_and_is_safe_to_run_twice(): void
    {
        Mail::fake();
        Storage::fake();
        Queue::fake();
        $product = Product::factory()->create(['stock' => 5]);
        $order = app(PlaceOrderAction::class)->execute($this->payload($product->id));

        (new ProcessOrder($order->id))->handle();
        (new ProcessOrder($order->id))->handle();   // second run does nothing

        $order->refresh();
        $this->assertSame(OrderStatus::COMPLETED, $order->status);
        $this->assertNotNull($order->invoice_path);
        Mail::assertQueued(SendOrderConfirmation::class, 1);
    }

    public function test_failed_method_records_reason(): void
    {
        Queue::fake();
        $product = Product::factory()->create(['stock' => 5]);
        $order = app(PlaceOrderAction::class)->execute($this->payload($product->id));

        (new ProcessOrder($order->id))->failed(new Exception('boom'));

        $order->refresh();
        $this->assertSame(OrderStatus::FAILED, $order->status);
        $this->assertSame('boom', $order->failure_reason);
    }

    public function test_cancel_restores_stock_and_rejects_non_pending(): void
    {   
        $customer = $this->customer();
        Sanctum::actingAs($customer);
        Queue::fake();
        $product = Product::factory()->create(['stock' => 5]);
        $order = app(PlaceOrderAction::class)->execute($this->payload($product->id, 2), $customer->id);

        $this->postJson("/api/v1/orders/{$order->order_number}/cancel")->assertOk();
        $this->assertSame(5, $product->fresh()->stock);

        $this->postJson("/api/v1/orders/{$order->order_number}/cancel")->assertStatus(409);
    }

    public function test_error_responses_share_the_same_shape(): void
    {
        Sanctum::actingAs($this->customer());

        // 404
        $this->getJson('/api/v1/orders/NOPE')
            ->assertNotFound()
            ->assertJsonStructure(['message', 'errors']);

        // 422 (validation)
        $this->postJson('/api/v1/orders', [])
            ->assertStatus(422)
            ->assertJsonStructure(['message', 'errors']);
    }

    public function test_guest_can_order_but_must_supply_name_and_email(): void
    {
        Queue::fake();
        $product = Product::factory()->create(['stock' => 5]);

        $this->postJson('/api/v1/orders', ['items' => [['product_id' => $product->id, 'quantity' => 1]]])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['customer_name', 'customer_email']);

        $this->postJson('/api/v1/orders', $this->payload($product->id))->assertCreated();
    }

    public function test_logged_in_customer_order_uses_account_email(): void
    {
        Queue::fake();
        $user = $this->customer('real@example.com');
        $product = Product::factory()->create(['stock' => 5]);
        Sanctum::actingAs($user);

        $this->postJson('/api/v1/orders', $this->payload($product->id))   // payload says ana@example.com
            ->assertCreated()
            ->assertJsonPath('data.customer_email', 'real@example.com');
    }

    public function test_customer_sees_only_own_orders_and_gets_404_on_others(): void
    {
        $ana = $this->customer('ana@example.com');
        $bob = $this->customer('bob@example.com');
        $mine = $this->orderFor('ana@example.com', $ana);
        $theirs = $this->orderFor('bob@example.com', $bob);
        Sanctum::actingAs($ana);

        $this->getJson('/api/v1/orders')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.order_number', $mine->order_number);

        $this->getJson("/api/v1/orders/{$theirs->order_number}")->assertNotFound();
        $this->postJson("/api/v1/orders/{$theirs->order_number}/cancel")->assertNotFound();
    }

    public function test_staff_see_all_orders(): void
    {
        $this->orderFor('ana@example.com');
        $this->orderFor('bob@example.com');
        Sanctum::actingAs($this->staff());

        $this->getJson('/api/v1/orders')->assertOk()->assertJsonCount(2, 'data');
    }

    public function test_registration_claims_unowned_orders_with_matching_email(): void
    {
        Queue::fake();
        $guest = $this->orderFor('late@example.com');          // unowned
        $other = $this->orderFor('someone@example.com');       // different email

        $token = $this->postJson('/api/v1/register', [
            'name' => 'Late', 'email' => 'Late@Example.com',
            'password' => 'secret123', 'password_confirmation' => 'secret123',
        ])->assertCreated()->json('token');

        $this->withToken($token)->getJson('/api/v1/orders')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.order_number', $guest->order_number);
    }

    public function test_unauthenticated_list_returns_401(): void
    {
        $this->getJson('/api/v1/orders')
            ->assertStatus(401)
            ->assertJsonStructure(['message', 'errors']);
    }

    public function test_logged_in_checkout_sets_user_id(): void
    {
        Queue::fake();
        $user = $this->customer('real@example.com');
        $product = Product::factory()->create(['stock' => 5]);
        Sanctum::actingAs($user);

        $this->postJson('/api/v1/orders', $this->payload($product->id))->assertCreated();

        $this->assertDatabaseHas('orders', ['user_id' => $user->id]);
    }

    public function test_registration_does_not_claim_orders_owned_by_someone_else(): void
    {
        Queue::fake();
        $owner = $this->customer('owner@example.com');
        $order = $this->orderFor('shared@example.com', $owner);   // owned, email differs from the owner's

        $this->postJson('/api/v1/register', [
            'name' => 'New', 'email' => 'shared@example.com',
            'password' => 'secret123', 'password_confirmation' => 'secret123',
        ])->assertCreated();

        $this->assertSame($owner->id, $order->fresh()->user_id);
    }

    public function test_stale_pending_orders_are_cancelled_and_stock_restored(): void
    {
        Queue::fake();
        $product = Product::factory()->create(['stock' => 10]);

        $stale   = app(PlaceOrderAction::class)->execute($this->payload($product->id, 2));
        $fresh   = app(PlaceOrderAction::class)->execute($this->payload($product->id, 3));
        $doneOld = app(PlaceOrderAction::class)->execute($this->payload($product->id, 1));

        // stock is now 10 - 2 - 3 - 1 = 4
        Order::whereKey($stale->id)->update(['created_at' => now()->subMinutes(31)]);
        Order::whereKey($doneOld->id)->update([
            'created_at' => now()->subMinutes(31),
            'status' => OrderStatus::COMPLETED->value,
        ]);

        $this->artisan('orders:cancel-stale')->assertSuccessful();

        $this->assertSame(OrderStatus::CANCELLED, $stale->fresh()->status);     // old + pending: cancelled
        $this->assertSame(OrderStatus::PENDING, $fresh->fresh()->status);       // recent: untouched
        $this->assertSame(OrderStatus::COMPLETED, $doneOld->fresh()->status);   // old but not pending: untouched
        $this->assertSame(6, $product->fresh()->stock);                         // only the stale order's 2 returned
    }
}
