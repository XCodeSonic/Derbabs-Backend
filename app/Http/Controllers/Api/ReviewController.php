<?php

namespace App\Http\Controllers\Api;

use App\Models\Review;
use App\Services\NotificationService;
use Illuminate\Http\Request;

class ReviewController extends Controller
{
    protected $notificationService;

    public function __construct(NotificationService $notificationService)
    {
        $this->notificationService = $notificationService;
    }

    public function index(Request $request)
    {
        $query = Review::with('booking.serviceEvent.customer.person')
            ->latest('review_id');

        if ($request->filled('customer_id')) {
            $query->whereHas('booking.serviceEvent.customer', function ($q) use ($request) {
                $q->where('customer_id', $request->customer_id);
            });
        }

        if ($request->has('is_approved') && $request->input('is_approved') !== '') {
            $query->where('is_approved', $request->boolean('is_approved'));
        }

        return $this->ok($query->paginate($request->integer('per_page', 20)), 'Reviews retrieved successfully');
    }

    public function store(Request $request)
    {
        if ($request->filled('rating') && !$request->filled('overall_rating')) {
            $request->merge([
                'food_rating' => $request->input('food_rating', $request->input('rating')),
                'service_rating' => $request->input('service_rating', $request->input('rating')),
                'value_rating' => $request->input('value_rating', $request->input('rating')),
                'overall_rating' => $request->input('rating'),
            ]);
        }

        $validated = $request->validate([
            'booking_id' => 'required|exists:bookings,booking_id|unique:reviews,booking_id',
            'food_rating' => 'required|integer|min:1|max:5',
            'service_rating' => 'required|integer|min:1|max:5',
            'value_rating' => 'required|integer|min:1|max:5',
            'overall_rating' => 'required|integer|min:1|max:5',
            'comment' => 'nullable|string'
        ]);
        
        $review = Review::create($validated);
        
        $this->notificationService->newCustomerReview($review);
        
        if ($review->overall_rating <= 2) {
            $this->notificationService->lowRatingAlert($review);
        }
        
        return $this->ok($review->load('booking.serviceEvent.customer.person'), 'Review created');
    }

    public function show(Review $review)
    {
        return $this->ok($review->load('booking.serviceEvent.customer.person'));
    }

    public function update(Request $request, Review $review)
    {
        $review->update($request->only(['is_approved', 'is_featured', 'admin_response', 'approved_by', 'approved_at']));
        return $this->ok($review->fresh()->load('booking.serviceEvent.customer.person'), 'Review updated');
    }

    public function respond(Request $request, Review $review)
    {
        $data = $request->validate([
            'response' => 'required|string',
        ]);

        $review->update([
            'admin_response' => $data['response'],
        ]);

        return $this->ok($review->fresh()->load('booking.serviceEvent.customer.person'), 'Response saved');
    }

    public function approve(Review $review)
    {
        $review->update([
            'is_approved' => true,
            'approved_by' => auth()->id(),
            'approved_at' => now(),
        ]);

        return $this->ok($review->fresh()->load('booking.serviceEvent.customer.person'), 'Review approved');
    }

    public function toggleFeature(Request $request, Review $review)
    {
        $review->update([
            'is_featured' => $request->has('featured') ? $request->boolean('featured') : !$review->is_featured,
        ]);

        return $this->ok($review->fresh()->load('booking.serviceEvent.customer.person'), 'Featured status updated');
    }

    public function hide(Review $review)
    {
        $review->update(['is_approved' => false]);
        return $this->ok($review->fresh()->load('booking.serviceEvent.customer.person'), 'Review hidden');
    }

    public function destroy(Review $review)
    {
        $review->update(['is_approved' => false]);
        return $this->ok($review->fresh()->load('booking.serviceEvent.customer.person'), 'Review hidden');
    }
}
