<?php

namespace App\Http\Controllers;

use App\Models\Store;
use App\Services\YoSmartService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class YoSmartController extends Controller
{
    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    /**
     * Build a YoSmartService for the given store, or return a 422 response
     * if the store has no YoSmart credentials configured.
     *
     * @return YoSmartService|JsonResponse
     */
    private function serviceForStore(Store $store): YoSmartService|JsonResponse
    {
        if (empty($store->yosmart_uaid) || empty($store->yosmart_secret)) {
            return response()->json([
                'success' => false,
                'error'   => 'This store has no YoSmart credentials configured. '
                           . 'Please add a UAID and Secret Key to the store first.',
            ], 422);
        }

        return new YoSmartService(
            uaid:    $store->yosmart_uaid,
            secret:  $store->yosmart_secret,
            storeId: $store->id,
        );
    }


    // -------------------------------------------------------------------------
    // Routes
    // -------------------------------------------------------------------------

    /** GET /api/stores/{store}/yosmart/devices */
    public function listDevices(Store $store): JsonResponse
    {
        $service = $this->serviceForStore($store);
        if ($service instanceof JsonResponse) { return $service; }

        try {
            $devices = $service->listDevices();
            if ($devices !== null) {
                return response()->json(["success" => true, "devices" => $devices, "count" => count($devices)]);
            }
            return response()->json(["success" => false, "error" => "Failed to fetch device list from YoSmart."], 400);
        } catch (\Exception $e) {
            \Log::error("YoSmart listDevices error", ["store_id" => $store->id, "error" => $e->getMessage()]);
            return response()->json(["success" => false, "error" => $e->getMessage()], 500);
        }
    }

    /** GET /api/stores/{store}/yosmart/home */
    public function homeInfo(Store $store): JsonResponse
    {
        $service = $this->serviceForStore($store);
        if ($service instanceof JsonResponse) { return $service; }

        try {
            $home = $service->homeInfo();
            if ($home !== null) {
                return response()->json(["success" => true, "home" => $home]);
            }
            return response()->json(["success" => false, "error" => "Failed to fetch home info."], 400);
        } catch (\Exception $e) {
            \Log::error("YoSmart homeInfo error", ["store_id" => $store->id, "error" => $e->getMessage()]);
            return response()->json(["success" => false, "error" => $e->getMessage()], 500);
        }
    }

    /** POST /api/stores/{store}/yosmart/device/state */
    public function deviceState(Request $request, Store $store): JsonResponse
    {
        $service = $this->serviceForStore($store);
        if ($service instanceof JsonResponse) { return $service; }

        $validated = $request->validate([
            "deviceId"    => "required|string|max:100",
            "deviceToken" => "required|string|max:200",
            "deviceType"  => "required|string|max:50",
        ]);

        $method = $service->resolveGetStateMethod($validated["deviceType"]);

        try {
            $result = $service->callApi($method, [
                "targetDevice" => $validated["deviceId"],
                "token"        => $validated["deviceToken"],
            ]);

            if ($result && ($result["code"] ?? null) === "000000") {
                return response()->json(["success" => true, "state" => $result["data"] ?? null,
                    "deviceType" => $validated["deviceType"], "method" => $method]);
            }

            $errorCode = $result["code"] ?? "unknown";
            $errorDesc = $result["desc"] ?? "Unknown error";
            return response()->json(["success" => false, "error" => $errorDesc,
                "code" => $errorCode, "hint" => $service->getErrorHint($errorCode)], 400);
        } catch (\Exception $e) {
            \Log::error("YoSmart deviceState error", ["store_id" => $store->id, "error" => $e->getMessage()]);
            return response()->json(["success" => false, "error" => $e->getMessage()], 500);
        }
    }

    /** GET /api/stores/{store}/yosmart/device/states */
    public function allDeviceStates(Store $store): JsonResponse
    {
        $service = $this->serviceForStore($store);
        if ($service instanceof JsonResponse) { return $service; }

        try {
            $listResult = $service->callApi("Home.getDeviceList");
            if (!$listResult || ($listResult["code"] ?? null) !== "000000") {
                return response()->json(["success" => false,
                    "error" => $listResult["desc"] ?? "Failed to fetch device list."], 400);
            }

            $devices = $listResult["data"]["devices"] ?? [];
            $states  = [];

            foreach ($devices as $device) {
                $deviceId   = $device["deviceId"];
                $deviceType = $device["type"] ?? "unknown";
                $token      = $device["token"] ?? null;

                if (!$token) {
                    $states[] = ["deviceId" => $deviceId, "deviceType" => $deviceType,
                        "name" => $device["name"] ?? "", "success" => false, "error" => "No token available"];
                    continue;
                }

                $method = $service->resolveGetStateMethod($deviceType);
                $result = $service->callApi($method, ["targetDevice" => $deviceId, "token" => $token]);

                if ($result && ($result["code"] ?? null) === "000000") {
                    $states[] = ["deviceId" => $deviceId, "deviceType" => $deviceType,
                        "name" => $device["name"] ?? "", "modelName" => $device["modelName"] ?? "",
                        "success" => true, "state" => $result["data"] ?? null, "method" => $method];
                } else {
                    $states[] = ["deviceId" => $deviceId, "deviceType" => $deviceType,
                        "name" => $device["name"] ?? "", "modelName" => $device["modelName"] ?? "",
                        "success" => false, "error" => $result["desc"] ?? "Unknown error",
                        "code" => $result["code"] ?? "unknown", "method" => $method];
                }
            }

            return response()->json(["success" => true, "devices" => $states,
                "count" => count($states),
                "successCount" => count(array_filter($states, fn($s) => $s["success"]))]);
        } catch (\Exception $e) {
            \Log::error("YoSmart allDeviceStates error", ["store_id" => $store->id, "error" => $e->getMessage()]);
            return response()->json(["success" => false, "error" => $e->getMessage()], 500);
        }
    }

    /** POST /api/stores/{store}/yosmart/device/control */
    public function controlDevice(Request $request, Store $store): JsonResponse
    {
        $service = $this->serviceForStore($store);
        if ($service instanceof JsonResponse) { return $service; }

        $validated = $request->validate([
            "deviceId"    => "required|string|max:100",
            "deviceToken" => "required|string|max:200",
            "method"      => "required|string|max:100",
            "params"      => "nullable|array",
        ]);

        try {
            $payload = ["targetDevice" => $validated["deviceId"], "token" => $validated["deviceToken"]];
            if (!empty($validated["params"])) { $payload["params"] = $validated["params"]; }

            $result = $service->callApi($validated["method"], $payload);
            if ($result && ($result["code"] ?? null) === "000000") {
                return response()->json(["success" => true, "data" => $result["data"] ?? null]);
            }
            return response()->json(["success" => false, "error" => $result["desc"] ?? "Unknown error",
                "code" => $result["code"] ?? "unknown"], 400);
        } catch (\Exception $e) {
            \Log::error("YoSmart controlDevice error", ["store_id" => $store->id, "error" => $e->getMessage()]);
            return response()->json(["success" => false, "error" => $e->getMessage()], 500);
        }
    }

    /** GET /api/stores/{store}/yosmart/health */
    public function health(Store $store): JsonResponse
    {
        if (empty($store->yosmart_uaid) || empty($store->yosmart_secret)) {
            return response()->json([
                "status" => "unconfigured", "yosmart_api" => "disconnected", "credentials_configured" => false,
            ]);
        }

        $service = new YoSmartService(uaid: $store->yosmart_uaid, secret: $store->yosmart_secret, storeId: $store->id);

        try {
            $result  = $service->callApi("Home.getGeneralInfo");
            $healthy = $result && ($result["code"] ?? null) === "000000";
            return response()->json(["status" => $healthy ? "ok" : "error",
                "yosmart_api" => $healthy ? "connected" : "disconnected", "credentials_configured" => true]);
        } catch (\Exception $e) {
            return response()->json(["status" => "error", "yosmart_api" => "disconnected",
                "credentials_configured" => true, "error" => $e->getMessage()], 500);
        }
    }
}
