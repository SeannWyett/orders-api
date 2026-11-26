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

    private function orderFor(string $email): Order
    {
        Queue::fake();
        $product = Product::factory()->create(['stock' => 50]);

        return app(PlaceOrderAction::class)->execute([
            'customer_name' => 'Someone',
            'customer_email' => $email,
            'items' => [['product_id' => $product->id, 'quantity' => 1]],
        ]);
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
        Sanctum::actingAs($this->customer());
        Queue::fake();
        $product = Product::factory()->create(['stock' => 5]);
        $order = app(PlaceOrderAction::class)->execute($this->payload($product->id, 2));

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
        $mine = $this->orderFor('ana@example.com');
        $theirs = $this->orderFor('bob@example.com');
        Sanctum::actingAs($this->customer('ana@example.com'));

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

    public function test_customer_who_registers_later_sees_earlier_guest_orders(): void
    {
        $guestOrder = $this->orderFor('late@example.com');

        $token = $this->postJson('/api/v1/register', [
            'name' => 'Late', 'email' => 'Late@Example.com',      // mixed case on purpose
            'password' => 'secret123', 'password_confirmation' => 'secret123',
        ])->assertCreated()->json('token');

        $this->withToken($token)->getJson('/api/v1/orders')
            ->assertOk()
            ->assertJsonPath('data.0.order_number', $guestOrder->order_number);
    }

    public function test_unauthenticated_list_returns_401(): void
    {
        $this->getJson('/api/v1/orders')
            ->assertStatus(401)
            ->assertJsonStructure(['message', 'errors']);
    }
}
