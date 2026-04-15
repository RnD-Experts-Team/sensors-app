<?php

namespace App\Http\Controllers;

use App\Models\YoSmartCredential;
use App\Services\YoSmartService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class YoSmartController extends Controller
{
    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    private function serviceForCredential(YoSmartCredential $credential): YoSmartService|JsonResponse
    {
        if (! $credential->hasCredentials()) {
            return response()->json([
                'success' => false,
                'error'   => 'This credential has incomplete configuration.',
            ], 422);
        }

        return new YoSmartService(
            uaid:         $credential->uaid,
            secret:       $credential->secret,
            credentialId: $credential->id,
        );
    }


    // -------------------------------------------------------------------------
    // Routes
    // -------------------------------------------------------------------------

    /** GET /api/credentials/{credential}/yosmart/devices */
    public function listDevices(YoSmartCredential $credential): JsonResponse
    {
        $service = $this->serviceForCredential($credential);
        if ($service instanceof JsonResponse) { return $service; }

        try {
            $devices = $service->listDevices();
            if ($devices !== null) {
                return response()->json(["success" => true, "devices" => $devices, "count" => count($devices)]);
            }
            return response()->json(["success" => false, "error" => "Failed to fetch device list from YoSmart."], 400);
        } catch (\Exception $e) {
            \Log::error("YoSmart listDevices error", ["credential_id" => $credential->id, "error" => $e->getMessage()]);
            return response()->json(["success" => false, "error" => $e->getMessage()], 500);
        }
    }

    /** GET /api/credentials/{credential}/yosmart/home */
    public function homeInfo(YoSmartCredential $credential): JsonResponse
    {
        $service = $this->serviceForCredential($credential);
        if ($service instanceof JsonResponse) { return $service; }

        try {
            $home = $service->homeInfo();
            if ($home !== null) {
                return response()->json(["success" => true, "home" => $home]);
            }
            return response()->json(["success" => false, "error" => "Failed to fetch home info."], 400);
        } catch (\Exception $e) {
            \Log::error("YoSmart homeInfo error", ["credential_id" => $credential->id, "error" => $e->getMessage()]);
            return response()->json(["success" => false, "error" => $e->getMessage()], 500);
        }
    }

    /** POST /api/credentials/{credential}/yosmart/device/state */
    public function deviceState(Request $request, YoSmartCredential $credential): JsonResponse
    {
        $service = $this->serviceForCredential($credential);
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
            \Log::error("YoSmart deviceState error", ["credential_id" => $credential->id, "error" => $e->getMessage()]);
            return response()->json(["success" => false, "error" => $e->getMessage()], 500);
        }
    }

    /** GET /api/credentials/{credential}/yosmart/device/states */
    public function allDeviceStates(YoSmartCredential $credential): JsonResponse
    {
        $service = $this->serviceForCredential($credential);
        if ($service instanceof JsonResponse) { return $service; }

        try {
            $listResult = $service->callApi("Home.getDeviceList");
            if (!$listResult || ($listResult["code"] ?? null) !== "000000") {
                return response()->json(["success" => false,
                    "error" => $listResult["desc"] ?? "Failed to fetch device list."], 400);
            }

            $devices = $listResult["data"]["devices"] ?? [];
            $states  = $service->batchGetStates($devices);

            return response()->json(["success" => true, "devices" => $states,
                "count" => count($states),
                "successCount" => count(array_filter($states, fn($s) => $s["success"]))]);
        } catch (\Exception $e) {
            \Log::error("YoSmart allDeviceStates error", ["credential_id" => $credential->id, "error" => $e->getMessage()]);
            return response()->json(["success" => false, "error" => $e->getMessage()], 500);
        }
    }

    /** POST /api/credentials/{credential}/yosmart/device/control */
    public function controlDevice(Request $request, YoSmartCredential $credential): JsonResponse
    {
        $service = $this->serviceForCredential($credential);
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
            \Log::error("YoSmart controlDevice error", ["credential_id" => $credential->id, "error" => $e->getMessage()]);
            return response()->json(["success" => false, "error" => $e->getMessage()], 500);
        }
    }

    /** GET /api/credentials/{credential}/yosmart/health */
    public function health(YoSmartCredential $credential): JsonResponse
    {
        if (! $credential->hasCredentials()) {
            return response()->json([
                "status" => "unconfigured", "yosmart_api" => "disconnected", "credentials_configured" => false,
            ]);
        }

        $service = new YoSmartService(uaid: $credential->uaid, secret: $credential->secret, credentialId: $credential->id);

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
