<?php

namespace App\Services;

use App\Models\AuditLog;
use App\Models\Employee;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

class FaceRecognitionService
{
    protected string $serviceUrl;
    protected float $matchThreshold;

    public function __construct()
    {
        $this->serviceUrl = config('services.face_recognition.url', 'http://127.0.0.1:5001');
        $this->matchThreshold = (float) config('services.face_recognition.threshold', 0.6);
    }

    /**
     * Verify a captured selfie against the employee's reference face photo.
     *
     * @return array{verified: bool, confidence: float, message: string, distance: ?float}
     */
    public function verify(Employee $employee, string $capturedImageBase64): array
    {
        $referencePath = $this->getReferenceFacePath($employee);

        if (! $referencePath) {
            return [
                'verified' => false,
                'confidence' => 0.0,
                'message' => 'No reference face photo found for this employee. Please contact admin to upload your face photo.',
                'distance' => null,
            ];
        }

        $cleanBase64 = $this->stripDataUri($capturedImageBase64);

        try {
            $response = Http::timeout(30)
                ->post("{$this->serviceUrl}/verify", [
                    'reference_image' => base64_encode(file_get_contents($referencePath)),
                    'captured_image' => $cleanBase64,
                    'threshold' => $this->matchThreshold,
                ]);

            if (! $response->successful()) {
                Log::error('Face verification service error', [
                    'status' => $response->status(),
                    'body' => $response->body(),
                ]);

                return [
                    'verified' => false,
                    'confidence' => 0.0,
                    'message' => 'Face verification service unavailable. Please try again.',
                    'distance' => null,
                ];
            }

            $data = $response->json();

            return [
                'verified' => (bool) ($data['verified'] ?? false),
                'confidence' => (float) ($data['confidence'] ?? 0.0),
                'message' => $data['message'] ?? ($data['verified'] ? 'Face verified' : 'Face does not match'),
                'distance' => isset($data['distance']) ? (float) $data['distance'] : null,
            ];
        } catch (\Throwable $e) {
            Log::error('Face verification exception', [
                'employee_id' => $employee->employee_id,
                'error' => $e->getMessage(),
            ]);

            return [
                'verified' => false,
                'confidence' => 0.0,
                'message' => 'Face verification failed: ' . $e->getMessage(),
                'distance' => null,
            ];
        }
    }

    protected function getReferenceFacePath(Employee $employee): ?string
    {
        $employee->loadMissing('person');

        $photoPath = $employee->face_photo
            ?? $employee->person?->profile_photo
            ?? null;

        if (! $photoPath) {
            return null;
        }

        if (str_starts_with($photoPath, 'http://') || str_starts_with($photoPath, 'https://')) {
            $temp = tempnam(sys_get_temp_dir(), 'face_ref_');
            file_put_contents($temp, file_get_contents($photoPath));
            return $temp;
        }

        $relative = ltrim(str_replace('/storage/', '', $photoPath), '/');
        $absolute = Storage::disk('public')->path($relative);

        return file_exists($absolute) ? $absolute : null;
    }

    protected function stripDataUri(string $base64): string
    {
        if (str_contains($base64, 'base64,')) {
            return explode('base64,', $base64)[1];
        }
        return $base64;
    }

    /**
     * Check if the employee has a registered face photo.
     */
    public function hasRegisteredFace(Employee $employee): bool
    {
        return $this->getReferenceFacePath($employee) !== null;
    }
}