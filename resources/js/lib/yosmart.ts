import { list as yosmartDevicesList } from '@/routes/yosmart/devices';
import { info as yosmartHomeInfo } from '@/routes/yosmart/home';
import { state as yosmartDeviceState, control as yosmartDeviceControl } from '@/routes/yosmart/device';
import { all as yosmartAllStates } from '@/routes/yosmart/device/states';

export interface Device {
  id: string;
  name: string;
  type: string;
  model: string;
  parentId?: string;
  parentName?: string;
  serviceZone?: string;
  status?: string;
  lastOnline?: string;
}

export interface ApiResponse<T = any> {
  success: boolean;
  data?: T;
  error?: string;
  message?: string;
}

/**
 * Get CSRF token from meta tag or cookie
 */
function getCsrfToken(): string {
  const metaTag = document.querySelector('meta[name="csrf-token"]') as HTMLMetaElement;
  if (metaTag) {
    return metaTag.content;
  }

  const name = 'XSRF-TOKEN';
  const value = `; ${document.cookie}`;
  const parts = value.split(`; ${name}=`);
  if (parts.length === 2) {
    return parts.pop()?.split(';').shift() || '';
  }

  return '';
}

/**
 * Fetch all YoSmart devices for a specific store
 */
export async function fetchYoSmartDevices(storeId: number): Promise<ApiResponse<{ devices: Device[] }>> {
  try {
    const response = await fetch(yosmartDevicesList.url(storeId), {
      method: 'GET',
      headers: {
        'Content-Type': 'application/json',
        'Accept': 'application/json',
      },
      credentials: 'include',
    });

    if (!response.ok) {
      throw new Error(`HTTP ${response.status}`);
    }

    return await response.json();
  } catch (error) {
    console.error('Failed to fetch YoSmart devices:', error);
    return {
      success: false,
      error: error instanceof Error ? error.message : 'Failed to fetch devices',
    };
  }
}

/**
 * Fetch YoSmart home information for a specific store
 */
export async function fetchYoSmartHome(storeId: number): Promise<ApiResponse<{ homeId: string }>> {
  try {
    const response = await fetch(yosmartHomeInfo.url(storeId), {
      method: 'GET',
      headers: {
        'Content-Type': 'application/json',
        'Accept': 'application/json',
      },
      credentials: 'include',
    });

    if (!response.ok) {
      throw new Error(`HTTP ${response.status}`);
    }

    return await response.json();
  } catch (error) {
    console.error('Failed to fetch YoSmart home info:', error);
    return {
      success: false,
      error: error instanceof Error ? error.message : 'Failed to fetch home info',
    };
  }
}

/**
 * Get device state/status using the correct device-type-specific API method.
 *
 * The YoSmart API requires each device type to call its own method:
 *   Hub.getState, THSensor.getState, DoorSensor.getState, etc.
 *
 * The `deviceType` parameter (from Home.getDeviceList) is REQUIRED so the
 * backend can resolve the correct method. Omitting it was the cause of
 * error 000103 ("Token is invalid") — Hub.getState was being sent for all
 * device types and the Hub rejected non-Hub tokens.
 */
export async function getDeviceState(
  storeId: number,
  deviceId: string,
  token: string,
  deviceType: string
): Promise<ApiResponse<any>> {
  try {
    const payload = {
      deviceId: deviceId,
      deviceToken: token,
      ...(deviceType && { deviceType }),
    };
    const response = await fetch(yosmartDeviceState.url(storeId), {
      method: 'POST',
      headers: {
        'Content-Type': 'application/json',
        'Accept': 'application/json',
        'X-Requested-With': 'XMLHttpRequest',
        'X-CSRF-Token': getCsrfToken(),
      },
      credentials: 'include',
      body: JSON.stringify(payload),
    });

    const data = await response.json();

    // Return parsed JSON even for error responses (400) so we get hint/code
    if (!response.ok && !data.success) {
      return {
        success: false,
        error: data.hint || data.error || `HTTP ${response.status}`,
        code: data.code,
      };
    }

    return data;
  } catch (error) {
    console.error(`Failed to get state for device ${deviceId}:`, error);
    return {
      success: false,
      error: error instanceof Error ? error.message : 'Failed to get device state',
    };
  }
}

/**
 * Control a device
 */
export async function controlDevice(
  storeId: number,
  deviceId: string,
  method: string,
  params?: Record<string, any>,
  token?: string
): Promise<ApiResponse<any>> {
  try {
    const response = await fetch(yosmartDeviceControl.url(storeId), {
      method: 'POST',
      headers: {
        'Content-Type': 'application/json',
        'Accept': 'application/json',
        'X-Requested-With': 'XMLHttpRequest',
        'X-CSRF-Token': getCsrfToken(),
      },
      credentials: 'include',
      body: JSON.stringify({
        deviceId,
        method,
        params: params || {},
        token,
      }),
    });

    if (!response.ok) {
      throw new Error(`HTTP ${response.status}`);
    }

    return await response.json();
  } catch (error) {
    console.error(`Failed to control device ${deviceId}:`, error);
    return {
      success: false,
      error: error instanceof Error ? error.message : 'Failed to control device',
    };
  }
}

/**
 * Fetch states for ALL devices in a single request.
 * The backend iterates each device and calls {DeviceType}.getState for each.
 */
export async function fetchAllDeviceStates(storeId: number): Promise<ApiResponse<any>> {
  try {
    const response = await fetch(yosmartAllStates.url(storeId), {
      method: 'GET',
      headers: {
        'Content-Type': 'application/json',
        'Accept': 'application/json',
      },
      credentials: 'include',
    });

    const data = await response.json();

    if (!response.ok && !data.success) {
      return {
        success: false,
        error: data.error || `HTTP ${response.status}`,
      };
    }

    return data;
  } catch (error) {
    console.error('Failed to fetch all device states:', error);
    return {
      success: false,
      error: error instanceof Error ? error.message : 'Failed to fetch device states',
    };
  }
}
