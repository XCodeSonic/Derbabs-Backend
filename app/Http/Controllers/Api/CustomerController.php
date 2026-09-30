<?php

namespace App\Http\Controllers\Api;

use App\Models\{Customer, Person, User, Role, Review, ChatThread, ChatMessage};
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class CustomerController extends Controller
{
    /**
     * Generate sequential customer code (CUST-0013 format)
     */
    private function generateCustomerCode(): string
    {
        return $this->generateSequentialNumber('CUST-', Customer::class, 'customer_code');
    }

    /**
     * Generic sequential number generator
     */
    private function generateSequentialNumber(string $prefix, string $modelClass, string $column, int $padding = 4): string
    {
        try {
            if (!class_exists($modelClass)) {
                throw new \Exception("Model class {$modelClass} not found");
            }

            $instance = new $modelClass();
            $keyName = $instance->getKeyName();

            $lastRecord = $modelClass::withTrashed()
                ->where($column, 'LIKE', $prefix . '%')
                ->orderBy($keyName, 'desc')
                ->first();

            if ($lastRecord && isset($lastRecord->$column)) {
                $lastNumber = intval(substr($lastRecord->$column, strlen($prefix)));
                $newNumber = str_pad($lastNumber + 1, $padding, '0', STR_PAD_LEFT);
            } else {
                $newNumber = str_repeat('0', $padding - 1) . '1';
            }

            return $prefix . $newNumber;
        } catch (\Exception $e) {
            Log::warning("Failed to generate sequential number for {$prefix}: " . $e->getMessage());
            return $prefix . str_pad((string) (time() % 10000), $padding, '0', STR_PAD_LEFT);
        }
    }

    /**
     * Display a listing of customers.
     */
    public function index(Request $request)
    {
        try {
            $query = Customer::with([
                'person',
                'user.roles',
                'bookings.serviceEvent',
                'bookings.payments',
                'bookings.review',
                'chatThreads.messages',
            ])
                ->withCount([
                    'bookings as total_bookings_count',
                    'bookings as completed_bookings_count' => function ($q) {
                        $q->whereIn('booking_status', ['completed', 'done']);
                    },
                ])
                ->whereNotNull('user_id')
                ->whereHas('user.roles', function ($q) {
                    $q->where('slug', 'customer');
                });

            if ($request->filled('search')) {
                $search = $request->input('search');
                $query->whereHas('person', function ($q) use ($search) {
                    $q->where('first_name', 'like', '%' . $search . '%')
                        ->orWhere('last_name', 'like', '%' . $search . '%')
                        ->orWhere('email', 'like', '%' . $search . '%')
                        ->orWhere('phone', 'like', '%' . $search . '%');
                });
            }

            if ($request->has('is_active') && $request->input('is_active') !== null && $request->input('is_active') !== '') {
                $query->where('is_active', $request->boolean('is_active'));
            }

            if ($request->filled('tier')) {
                $query->where('tier', $request->input('tier'));
            }

            $customers = $query->latest('customer_id')->paginate($request->integer('per_page', 20));
            $customers->getCollection()->transform(fn($customer) => $this->appendCustomerMetrics($customer));

            return $this->ok($customers, 'Customers retrieved successfully');
        } catch (\Exception $e) {
            Log::error('Customer index error: ' . $e->getMessage());
            return $this->fail('Failed to fetch customers: ' . $e->getMessage(), 500);
        }
    }

    public function stats()
    {
        try {
            $customers = Customer::whereNotNull('user_id')
                ->whereHas('user.roles', fn($q) => $q->where('slug', 'customer'));

            $reviews = Review::query();

            return $this->ok([
                'total_customers' => (clone $customers)->count(),
                'active_customers' => (clone $customers)->where('is_active', true)->count(),
                'new_this_month' => (clone $customers)->whereMonth('created_at', now()->month)->whereYear('created_at', now()->year)->count(),
                'avg_rating' => round((float) (clone $reviews)->avg('overall_rating'), 1),
                'total_reviews' => (clone $reviews)->count(),
                'loyal_customers' => (clone $customers)->whereIn('tier', ['gold', 'platinum'])->count(),
            ], 'Customer statistics retrieved successfully');
        } catch (\Exception $e) {
            Log::error('Customer stats error: ' . $e->getMessage());
            return $this->fail('Failed to fetch customer statistics: ' . $e->getMessage(), 500);
        }
    }

    private function appendCustomerMetrics(Customer $customer): Customer
    {
        $bookingIds = $customer->bookings->pluck('booking_id')->filter()->values();
        $reviews = $bookingIds->isNotEmpty()
            ? Review::whereIn('booking_id', $bookingIds)->latest('review_id')->get()
            : collect();

        $customer->setAttribute('reviews_count', $reviews->count());
        $customer->setAttribute('average_rating', round((float) $reviews->avg('overall_rating'), 1));
        $customer->setAttribute('latest_review', $reviews->first());
        $customer->setAttribute('total_spent', (float) $customer->bookings->sum(function ($booking) {
            return $booking->payments->where('status', 'completed')->sum('amount');
        }));
        $customer->setAttribute('has_chat_thread', $customer->chatThreads->isNotEmpty());

        return $customer;
    }

    /**
     * Display the specified customer.
     */
    public function show(Customer $customer)
    {
        try {
            return $this->ok(
                $customer->load([
                    'person',
                    'bookings.serviceEvent',
                    'bookings.payments',
                    'bookings.invoice',
                    'bookings.review',
                    'chatThreads.messages.sender.person'
                ])
            );
        } catch (\Exception $e) {
            Log::error('Customer show error: ' . $e->getMessage());
            return $this->fail('Failed to fetch customer: ' . $e->getMessage(), 500);
        }
    }

    /**
     * Store a new customer (admin)
     */
    public function store(Request $request)
    {
        try {
            $data = $request->validate([
                'first_name' => 'required|string|max:80',
                'last_name' => 'required|string|max:80',
                'email' => 'required|email|max:120|unique:persons,email',
                'phone' => 'nullable|string|max:30',
                'address' => 'nullable|string',
                'password' => 'nullable|string|min:8',
            ]);

            Log::info('Admin customer creation', ['email' => $data['email']]);

            return DB::transaction(function () use ($data) {
                $person = Person::create([
                    'first_name' => $data['first_name'],
                    'last_name' => $data['last_name'],
                    'email' => $data['email'],
                    'phone' => $data['phone'] ?? null,
                    'address_line_1' => $data['address'] ?? null,
                    'country' => 'Philippines',
                ]);

                $user = User::create([
                    'person_id' => $person->person_id,
                    'username' => $data['email'],
                    'password' => Hash::make($data['password'] ?? 'password123'),
                    'is_active' => true,
                ]);

                $role = Role::where('slug', 'customer')->first();
                if ($role) {
                    $user->roles()->sync([$role->role_id]);
                }

                $customer = Customer::create([
                    'person_id' => $person->person_id,
                    'user_id' => $user->user_id,
                    'customer_code' => $this->generateCustomerCode(),
                    'tier' => 'bronze',
                    'is_active' => true,
                ]);

                return $this->ok(
                    $customer->load('person'),
                    'Customer created successfully'
                );
            });
        } catch (\Illuminate\Validation\ValidationException $e) {
            return response()->json([
                'success' => false,
                'message' => 'Validation failed',
                'errors' => $e->errors()
            ], 422);
        } catch (\Exception $e) {
            Log::error('Customer store error: ' . $e->getMessage());

            return response()->json([
                'success' => false,
                'message' => 'Failed to create customer: ' . $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Update the specified customer.
     */
    public function update(Request $request, Customer $customer)
    {
        try {
            $validated = $request->validate([
                'first_name' => 'sometimes|string|max:80',
                'last_name' => 'sometimes|string|max:80',
                'email' => 'sometimes|email|max:120|unique:persons,email,' . $customer->person_id . ',person_id',
                'phone' => 'nullable|string|max:30',
                'address_line_1' => 'nullable|string',
                'city' => 'nullable|string|max:80',
                'province' => 'nullable|string|max:80',
                'tier' => 'sometimes|in:bronze,silver,gold,platinum',
                'dietary_restrictions' => 'nullable|string',
                'notes' => 'nullable|string',
                'is_active' => 'sometimes|boolean',
            ]);

            $customer->update(
                $request->only(['tier', 'dietary_restrictions', 'notes', 'is_active'])
            );

            $personData = $request->only(['first_name', 'last_name', 'email', 'phone', 'address_line_1', 'city', 'province']);
            if (!empty($personData)) {
                $customer->person->update($personData);
            }

            Log::info('Customer updated', ['customer_id' => $customer->customer_id]);

            return $this->show($customer);
        } catch (\Illuminate\Validation\ValidationException $e) {
            return response()->json([
                'success' => false,
                'message' => 'Validation failed',
                'errors' => $e->errors()
            ], 422);
        } catch (\Exception $e) {
            Log::error('Customer update error: ' . $e->getMessage());

            return response()->json([
                'success' => false,
                'message' => 'Failed to update customer: ' . $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Remove the specified customer (soft delete / deactivate).
     */
    public function destroy(Customer $customer)
    {
        try {
            $customer->update(['is_active' => false]);

            Log::info('Customer deactivated', ['customer_id' => $customer->customer_id]);

            return $this->ok(null, 'Customer deactivated successfully');
        } catch (\Exception $e) {
            Log::error('Customer destroy error: ' . $e->getMessage());

            return response()->json([
                'success' => false,
                'message' => 'Failed to deactivate customer: ' . $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Get customer feedback/reviews.
     */
    public function feedback(Request $request)
    {
        try {
            $query = Review::with(['booking.serviceEvent.customer.person']);

            if ($request->customer_id) {
                $query->whereHas('booking.serviceEvent.customer', function ($q) use ($request) {
                    $q->where('customer_id', $request->customer_id);
                });
            }

            $reviews = $query->latest()->paginate($request->integer('per_page', 20));
            $reviews->getCollection()->transform(function ($review) {
                $customer = $review->booking?->serviceEvent?->customer;
                $person = $customer?->person;
                $review->customer_id = $customer?->customer_id;
                $review->customer_name = trim(($person?->first_name ?? '') . ' ' . ($person?->last_name ?? '')) ?: 'Customer';
                $review->sentiment = $review->overall_rating >= 4 ? 'positive' : ($review->overall_rating <= 2 ? 'negative' : 'neutral');
                return $review;
            });

            return $this->ok($reviews);
        } catch (\Exception $e) {
            Log::error('Customer feedback error: ' . $e->getMessage());

            return response()->json([
                'success' => false,
                'message' => 'Failed to fetch feedback: ' . $e->getMessage(),
            ], 500);
        }
    }

    private function aiWelcomeMessage(): string
    {
        return "Hi! I'm your PMH Catering AI assistant. I received your chat and our admin team can see it on the web dashboard. Please send your event date, package or menu interest, guest count, and any questions so we can assist you faster.";
    }

    private function ensureAiWelcomeMessage(ChatThread $thread): void
    {
        if ($thread->messages()->exists()) {
            return;
        }

        ChatMessage::create([
            'thread_id' => $thread->thread_id,
            'sender_user_id' => null,
            'message' => $this->aiWelcomeMessage(),
            'read_at' => null,
        ]);

        $thread->touch();
    }

    private function ensureCustomerThread(int $customerId): ChatThread
    {
        $thread = ChatThread::firstOrCreate(
            ['customer_id' => $customerId],
            ['assigned_user_id' => null, 'status' => 'open']
        );

        $this->ensureAiWelcomeMessage($thread);

        return $thread;
    }

    /**
     * Get customer messages/chat threads.
     */
    public function messages(Request $request)
    {
        try {
            if ($request->filled('customer_id') && ($request->boolean('ensure_thread') || $request->boolean('ensure'))) {
                $this->ensureCustomerThread((int) $request->customer_id);
            }

            $query = ChatThread::with(['customer.person', 'messages.sender.person']);

            if ($request->customer_id) {
                $query->where('customer_id', $request->customer_id);
            }

            return $this->ok(
                $query->latest('updated_at')->paginate($request->integer('per_page', 20))
            );
        } catch (\Exception $e) {
            Log::error('Customer messages error: ' . $e->getMessage());

            return response()->json([
                'success' => false,
                'message' => 'Failed to fetch messages: ' . $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Send a message to customer.
     * ⭐ When the sender is admin/staff (not the customer), the customer gets notified.
     */
    public function sendMessage(Request $request)
    {
        try {
            $data = $request->validate([
                'customer_id' => 'required|exists:customers,customer_id',
                'message' => 'required|string',
                'subject' => 'nullable|string|max:255',
            ]);

            $currentUser = $request->user();
            $isCustomerSender = $currentUser?->customer
                && (int) $currentUser->customer->customer_id === (int) $data['customer_id'];

            $chatThread = ChatThread::firstOrCreate(
                ['customer_id' => $data['customer_id']],
                ['assigned_user_id' => $isCustomerSender ? null : auth()->id(), 'status' => 'open']
            );

            $this->ensureAiWelcomeMessage($chatThread);

            if (!$isCustomerSender && !$chatThread->assigned_user_id && auth()->id()) {
                $chatThread->update(['assigned_user_id' => auth()->id()]);
            }

            $message = ChatMessage::create([
                'thread_id' => $chatThread->thread_id,
                'sender_user_id' => auth()->id(),
                'message' => $data['message'],
                'read_at' => null,
            ]);

            $chatThread->touch();

            Log::info('Message sent to customer', [
                'customer_id' => $data['customer_id'],
                'thread_id' => $chatThread->thread_id,
                'from_admin' => !$isCustomerSender,
            ]);

            // ============================================================
            // ⭐ Notify the customer when the sender is admin/staff
            // ============================================================
            if (!$isCustomerSender) {
                try {
                    $customer = Customer::with('user')->find($data['customer_id']);

                    if ($customer && $customer->user_id) {
                        $senderName = $currentUser?->person?->full_name
                            ?? $currentUser?->username
                            ?? "Dear Bab's Team";

                        $preview = Str::limit(strip_tags((string) $data['message']), 120);

                        app(\App\Services\NotificationService::class)->notifyUser(
                            $customer->user_id,
                            'chat_message_from_admin',
                            "💬 New Message from Dear Bab's",
                            "{$senderName}: {$preview}",
                            \App\Models\Notification::PRIORITY_HIGH,
                            [
                                'thread_id'   => $chatThread->thread_id,
                                'message_id'  => $message->message_id,
                                'customer_id' => $customer->customer_id,
                                'type'        => 'chat_message_from_admin',
                            ],
                            "/customer/chat/{$chatThread->thread_id}"
                        );

                        Log::info('Admin reply notification sent to customer', [
                            'customer_id' => $customer->customer_id,
                            'user_id'     => $customer->user_id,
                            'message_id'  => $message->message_id,
                        ]);
                    }
                } catch (\Throwable $notifyErr) {
                    Log::warning('Admin reply notification failed: ' . $notifyErr->getMessage());
                }
            }

            return $this->ok($message->load('sender.person'), 'Message sent successfully');
        } catch (\Illuminate\Validation\ValidationException $e) {
            return response()->json([
                'success' => false,
                'message' => 'Validation failed',
                'errors' => $e->errors()
            ], 422);
        } catch (\Exception $e) {
            Log::error('Send message error: ' . $e->getMessage());

            return response()->json([
                'success' => false,
                'message' => 'Failed to send message: ' . $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Get customer bookings.
     */
    public function bookings(Customer $customer)
    {
        try {
            $bookings = $customer->bookings()
                ->with(['serviceEvent', 'payments', 'items.menuItem', 'mealServices', 'review'])
                ->latest('booking_id')
                ->get();

            return $this->ok($bookings, 'Customer bookings retrieved successfully');
        } catch (\Exception $e) {
            Log::error('Customer bookings error: ' . $e->getMessage());

            return response()->json([
                'success' => false,
                'message' => 'Failed to fetch bookings: ' . $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Get customer payments.
     */
    public function payments(Customer $customer)
    {
        try {
            return $this->ok(
                $customer->load(['bookings.payments'])
            );
        } catch (\Exception $e) {
            Log::error('Customer payments error: ' . $e->getMessage());

            return response()->json([
                'success' => false,
                'message' => 'Failed to fetch payments: ' . $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Get customer reviews.
     */
    public function reviews(Customer $customer)
    {
        try {
            return $this->ok(
                Review::whereHas('booking.serviceEvent', function ($q) use ($customer) {
                    $q->where('customer_id', $customer->customer_id);
                })->with(['booking.serviceEvent'])->latest()->get()
            );
        } catch (\Exception $e) {
            Log::error('Customer reviews error: ' . $e->getMessage());

            return response()->json([
                'success' => false,
                'message' => 'Failed to fetch reviews: ' . $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Toggle customer status.
     */
    public function toggleStatus(Customer $customer)
    {
        try {
            $customer->is_active = !$customer->is_active;
            $customer->save();

            Log::info('Customer status toggled', [
                'customer_id' => $customer->customer_id,
                'is_active' => $customer->is_active
            ]);

            return $this->ok(
                $customer,
                'Customer status updated successfully'
            );
        } catch (\Exception $e) {
            Log::error('Customer toggle status error: ' . $e->getMessage());

            return response()->json([
                'success' => false,
                'message' => 'Failed to toggle status: ' . $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Restore a previously archived customer.
     */
    public function restore(int $id)
    {
        $customer = Customer::onlyTrashed()->findOrFail($id);
        $customer->restore();
        $customer->update(['is_active' => true]);

        return $this->ok($customer->load(['person', 'user.roles']), 'Customer restored successfully');
    }

    /**
     * Mark a customer chat message as read.
     */
    public function markMessageRead(ChatMessage $message)
    {
        if (! $message->read_at) {
            $message->update(['read_at' => now()]);
        }

        return $this->ok($message->fresh(), 'Message marked as read');
    }

    /**
     * Return the customer's current loyalty balance and tier progress.
     */
    public function loyalty(Customer $customer)
    {
        return $this->ok($this->loyaltySummary($customer));
    }

    /**
     * Add loyalty points using a row lock to prevent lost updates.
     */
    public function addLoyaltyPoints(Request $request, Customer $customer)
    {
        $data = $request->validate([
            'points' => ['required', 'integer', 'min:1', 'max:1000000'],
            'reason' => ['nullable', 'string', 'max:500'],
        ]);

        $updated = DB::transaction(function () use ($customer, $data) {
            $locked = Customer::query()->lockForUpdate()->findOrFail($customer->customer_id);
            $points = (int) $locked->loyalty_points + (int) $data['points'];
            $locked->update([
                'loyalty_points' => $points,
                'tier' => $this->tierForPoints($points),
            ]);

            Log::info('Customer loyalty points added', [
                'customer_id' => $locked->customer_id,
                'points' => $data['points'],
                'reason' => $data['reason'] ?? null,
                'performed_by' => auth()->id(),
            ]);

            return $locked->fresh();
        });

        return $this->ok($this->loyaltySummary($updated), 'Loyalty points added successfully');
    }

    /**
     * Redeem loyalty points while preventing negative balances.
     */
    public function redeemLoyaltyPoints(Request $request, Customer $customer)
    {
        $data = $request->validate([
            'points' => ['required', 'integer', 'min:1', 'max:1000000'],
            'booking_id' => [
                'nullable',
                'integer',
                function (string $attribute, mixed $value, \Closure $fail) use ($customer): void {
                    if ($value && ! $customer->bookings()->where('bookings.booking_id', $value)->exists()) {
                        $fail('The selected booking does not belong to this customer.');
                    }
                },
            ],
        ]);

        $updated = DB::transaction(function () use ($customer, $data) {
            $locked = Customer::query()->lockForUpdate()->findOrFail($customer->customer_id);
            if ((int) $locked->loyalty_points < (int) $data['points']) {
                throw \Illuminate\Validation\ValidationException::withMessages([
                    'points' => 'The customer does not have enough loyalty points.',
                ]);
            }

            $points = (int) $locked->loyalty_points - (int) $data['points'];
            $locked->update([
                'loyalty_points' => $points,
                'tier' => $this->tierForPoints($points),
            ]);

            Log::info('Customer loyalty points redeemed', [
                'customer_id' => $locked->customer_id,
                'points' => $data['points'],
                'booking_id' => $data['booking_id'] ?? null,
                'performed_by' => auth()->id(),
            ]);

            return $locked->fresh();
        });

        return $this->ok($this->loyaltySummary($updated), 'Loyalty points redeemed successfully');
    }

    private function loyaltySummary(Customer $customer): array
    {
        $points = max(0, (int) $customer->loyalty_points);
        $thresholds = ['bronze' => 0, 'silver' => 500, 'gold' => 1500, 'platinum' => 3000];
        $tier = $this->tierForPoints($points);
        $tiers = array_keys($thresholds);
        $index = array_search($tier, $tiers, true);
        $nextTier = $index !== false && $index < count($tiers) - 1 ? $tiers[$index + 1] : null;

        return [
            'customer_id' => $customer->customer_id,
            'points' => $points,
            'tier' => $tier,
            'next_tier' => $nextTier,
            'points_to_next' => $nextTier ? max(0, $thresholds[$nextTier] - $points) : 0,
        ];
    }

    private function tierForPoints(int $points): string
    {
        return match (true) {
            $points >= 3000 => 'platinum',
            $points >= 1500 => 'gold',
            $points >= 500 => 'silver',
            default => 'bronze',
        };
    }

    /**
     * Send email to customer.
     */
    public function sendEmail(Request $request, Customer $customer)
    {
        try {
            $data = $request->validate([
                'subject' => 'required|string|max:255',
                'message' => 'required|string',
            ]);

            Log::info('Email sent to customer', [
                'customer_id' => $customer->customer_id,
                'subject' => $data['subject']
            ]);

            return $this->ok(null, 'Email sent successfully');
        } catch (\Illuminate\Validation\ValidationException $e) {
            return response()->json([
                'success' => false,
                'message' => 'Validation failed',
                'errors' => $e->errors()
            ], 422);
        } catch (\Exception $e) {
            Log::error('Send email error: ' . $e->getMessage());

            return response()->json([
                'success' => false,
                'message' => 'Failed to send email: ' . $e->getMessage(),
            ], 500);
        }
    }
}
