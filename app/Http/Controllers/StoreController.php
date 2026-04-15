<?php

namespace App\Http\Controllers;

use App\Models\Store;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class StoreController extends Controller
{
    /**
     * List all stores.
     */
    public function index(): JsonResponse
    {
        $stores = Store::withCount(['devices', 'hubs', 'sensors'])->get();

        return response()->json([
            'success' => true,
            'stores' => $stores,
        ]);
    }

    /**
     * Create a new store.
     */
    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'store_number' => ['required', 'string', 'regex:/^\d{5}-\d{5}$/', 'unique:stores,store_number'],
            'store_name'   => 'required|string|max:255',
        ], [
            'store_number.regex' => 'Store number must be in XXXXX-XXXXX format (e.g. 03795-00038).',
        ]);

        $store = Store::create($validated);

        return response()->json([
            'success' => true,
            'store' => $store,
        ], 201);
    }

    /**
     * Get a single store with its devices.
     */
    public function show(Store $store): JsonResponse
    {
        $store->load(['devices' => function ($q) {
            $q->with('credential:id,uaid');
        }]);

        return response()->json([
            'success' => true,
            'store' => $store,
        ]);
    }

    /**
     * Update a store.
     */
    public function update(Request $request, Store $store): JsonResponse
    {
        $validated = $request->validate([
            'store_number' => ['sometimes', 'required', 'string', 'regex:/^\d{5}-\d{5}$/', 'unique:stores,store_number,' . $store->id],
            'store_name'   => 'sometimes|required|string|max:255',
            'is_active'    => 'sometimes|boolean',
        ], [
            'store_number.regex' => 'Store number must be in XXXXX-XXXXX format (e.g. 03795-00038).',
        ]);

        $store->update($validated);

        return response()->json([
            'success' => true,
            'store' => $store,
        ]);
    }

    /**
     * Delete a store (devices remain but lose their store link).
     */
    public function destroy(Store $store): JsonResponse
    {
        // Unlink devices from this store (don't delete them — they belong to credentials)
        $store->devices()->update(['store_id' => null]);
        $store->delete();

        return response()->json([
            'success' => true,
            'message' => 'Store deleted.',
        ]);
    }
}
