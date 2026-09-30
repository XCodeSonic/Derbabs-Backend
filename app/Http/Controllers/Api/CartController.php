<?php

namespace App\Http\Controllers\Api;

use App\Models\Cart;
use App\Models\CartItem;
use App\Models\Customer;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class CartController extends Controller
{
    private function currentCustomer(Request $request): ?Customer
    {
        return $request->user()?->customer;
    }

    private function getOrCreateCart(Customer $customer): Cart
    {
        // Use the query builder here so the cart endpoint never depends on
        // Eloquent soft-delete scopes. The existing carts migration has no
        // deleted_at column, so this avoids SQL errors such as
        // `Unknown column carts.deleted_at`.
        $row = DB::table('carts')
            ->where('customer_id', $customer->customer_id)
            ->first();

        if (!$row) {
            $cartId = DB::table('carts')->insertGetId([
                'customer_id' => $customer->customer_id,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        } else {
            $cartId = $row->cart_id;
        }

        return Cart::withoutGlobalScopes()->findOrFail($cartId);
    }

    private function formatCart(Cart $cart): array
    {
        $cart->load(['items.menuItem.category']);

        $items = $cart->items->map(function (CartItem $item) {
            $menuItem = $item->menuItem;
            $price = (float) ($menuItem?->price ?? 0);
            $quantity = (int) $item->quantity;

            return [
                'cart_item_id' => $item->cart_item_id,
                'id' => $menuItem?->menu_item_id ?? $item->menu_item_id,
                'menu_item_id' => $item->menu_item_id,
                'name' => $menuItem?->name ?? 'Menu Item',
                'description' => $menuItem?->description,
                'price' => $price,
                'quantity' => $quantity,
                'image' => $menuItem?->image_full_url ?? $menuItem?->image_url,
                'image_url' => $menuItem?->image_full_url ?? $menuItem?->image_url,
                'category' => $menuItem?->category?->name,
                'line_total' => $price * $quantity,
                'created_at' => $item->created_at,
                'updated_at' => $item->updated_at,
            ];
        })->values();

        return [
            'cart_id' => $cart->cart_id,
            'customer_id' => $cart->customer_id,
            'items' => $items,
            'count' => $items->sum('quantity'),
            'total' => $items->sum('line_total'),
        ];
    }

    public function show(Request $request)
    {
        $customer = $this->currentCustomer($request);
        if (!$customer) {
            return $this->fail('Customer account not found for this user.', 403);
        }

        return $this->ok($this->formatCart($this->getOrCreateCart($customer)), 'Cart retrieved successfully');
    }

    public function store(Request $request)
    {
        $customer = $this->currentCustomer($request);
        if (!$customer) {
            return $this->fail('Customer account not found for this user.', 403);
        }

        $data = $request->validate([
            'menu_item_id' => 'nullable|exists:menu_items,menu_item_id',
            'id' => 'nullable|exists:menu_items,menu_item_id',
            'quantity' => 'nullable|integer|min:1|max:999',
        ]);

        $menuItemId = $data['menu_item_id'] ?? $data['id'] ?? null;
        if (!$menuItemId) {
            return $this->fail('Menu item is required.', 422);
        }

        try {
            $cart = DB::transaction(function () use ($customer, $menuItemId, $data) {
                $cart = $this->getOrCreateCart($customer);
                $quantity = (int) ($data['quantity'] ?? 1);

                $existing = CartItem::where('cart_id', $cart->cart_id)
                    ->where('menu_item_id', $menuItemId)
                    ->first();

                if ($existing) {
                    $existing->update(['quantity' => ((int) $existing->quantity) + $quantity]);
                } else {
                    CartItem::create([
                        'cart_id' => $cart->cart_id,
                        'menu_item_id' => $menuItemId,
                        'quantity' => $quantity,
                    ]);
                }

                return $cart->fresh();
            });

            return $this->ok($this->formatCart($cart), 'Item added to cart');
        } catch (\Throwable $e) {
            Log::error('Cart add error: ' . $e->getMessage());
            return $this->fail('Failed to add item to cart.', 500);
        }
    }

    public function update(Request $request, $cartItem)
    {
        $customer = $this->currentCustomer($request);
        if (!$customer) {
            return $this->fail('Customer account not found for this user.', 403);
        }

        $data = $request->validate([
            'quantity' => 'required|integer|min:0|max:999',
        ]);

        $cart = $this->getOrCreateCart($customer);
        $item = CartItem::where('cart_id', $cart->cart_id)->where('cart_item_id', $cartItem)->first();

        if (!$item) {
            return $this->fail('Cart item not found.', 404);
        }

        if ((int) $data['quantity'] === 0) {
            $item->delete();
        } else {
            $item->update(['quantity' => (int) $data['quantity']]);
        }

        return $this->ok($this->formatCart($cart->fresh()), 'Cart updated');
    }

    public function destroy(Request $request, $cartItem)
    {
        $customer = $this->currentCustomer($request);
        if (!$customer) {
            return $this->fail('Customer account not found for this user.', 403);
        }

        $cart = $this->getOrCreateCart($customer);
        CartItem::where('cart_id', $cart->cart_id)->where('cart_item_id', $cartItem)->delete();

        return $this->ok($this->formatCart($cart->fresh()), 'Item removed from cart');
    }

    public function clear(Request $request)
    {
        $customer = $this->currentCustomer($request);
        if (!$customer) {
            return $this->fail('Customer account not found for this user.', 403);
        }

        $cart = $this->getOrCreateCart($customer);
        $cart->items()->delete();

        return $this->ok($this->formatCart($cart->fresh()), 'Cart cleared');
    }
}
