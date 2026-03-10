import { useState, useEffect, useCallback } from 'react';
import {
  fetchYoSmartDevices,
  fetchYoSmartHome,
  getDeviceState,
  controlDevice as controlDeviceAction,
  fetchAllDeviceStates,
  Device,
} from '@/lib/yosmart';

interface DeviceState {
  [deviceId: string]: {
    loading: boolean;
    state: any;
    error: string | null;
    lastUpdated: Date | null;
  };
}

export function useYoSmartDevices(storeId: number) {
  const [devices, setDevices] = useState<Device[]>([]);
  const [homeId, setHomeId] = useState<string | null>(null);
  const [deviceStates, setDeviceStates] = useState<DeviceState>({});
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState<string | null>(null);
  const [lastSync, setLastSync] = useState<Date | null>(null);

  /**
   * Load all devices
   */
  const loadDevices = useCallback(async () => {
    try {
      setLoading(true);
      setError(null);

      const response = await fetchYoSmartDevices(storeId);

      if (response.success && response.devices) {
        setDevices(response.devices);
        setLastSync(new Date());
      } else {
        setError(response.error || 'Failed to load devices');
        setDevices([]);
      }
    } catch (err) {
      const errorMsg = err instanceof Error ? err.message : 'Unknown error';
      setError(errorMsg);
      setDevices([]);
    } finally {
      setLoading(false);
    }
  }, []);

  /**
   * Load home info
   */
  const loadHome = useCallback(async () => {
    try {
      const response = await fetchYoSmartHome(storeId);

      if (response.success && response.home) {
        setHomeId(response.home.homeId || response.home.id);
      } else {
        console.warn('Failed to load home info:', response.error);
      }
    } catch (err) {
      console.error('Home info error:', err);
    }
  }, []);

  /**
   * Get device state with device-type-specific method handling.
   * deviceType is REQUIRED — it determines which API method is called.
   */
  const getState = useCallback(
    async (deviceId: string, deviceToken: string, deviceType: string) => {
      try {
        // Set loading state
        setDeviceStates(prev => ({
          ...prev,
          [deviceId]: {
            ...(prev[deviceId] || { loading: false, state: null, error: null, lastUpdated: null }),
            loading: true,
            error: null,
          },
        }));

        const response = await getDeviceState(storeId, deviceId, deviceToken, deviceType);

        if (response.success && response.state) {
          setDeviceStates(prev => ({
            ...prev,
            [deviceId]: {
              loading: false,
              state: response.state,
              error: null,
              lastUpdated: new Date(),
            },
          }));
          return response.state;
        } else {
          const errorMsg = response.error || 'Failed to get state';
          setDeviceStates(prev => ({
            ...prev,
            [deviceId]: {
              ...(prev[deviceId] || { loading: false, state: null, lastUpdated: null }),
              loading: false,
              error: errorMsg,
            },
          }));
          return null;
        }
      } catch (err) {
        const errorMsg = err instanceof Error ? err.message : 'Unknown error';
        setDeviceStates(prev => ({
          ...prev,
          [deviceId]: {
            ...(prev[deviceId] || { loading: false, state: null, lastUpdated: null }),
            loading: false,
            error: errorMsg,
          },
        }));
        return null;
      }
    },
    []
  );

  /**
   * Control a device
   */
  const control = useCallback(
    async (
      deviceId: string,
      deviceToken: string,
      method: string,
      params?: Record<string, any>
    ) => {
      try {
        setDeviceStates(prev => ({
          ...prev,
          [deviceId]: {
            ...(prev[deviceId] || { loading: false, state: null, error: null, lastUpdated: null }),
            loading: true,
            error: null,
          },
        }));

        const response = await controlDeviceAction(storeId, deviceId, deviceToken, method, params);

        if (response.success && response.data) {
          setDeviceStates(prev => ({
            ...prev,
            [deviceId]: {
              loading: false,
              state: response.data,
              error: null,
              lastUpdated: new Date(),
            },
          }));
          return response.data;
        } else {
          const errorMsg = response.error || 'Failed to control device';
          setDeviceStates(prev => ({
            ...prev,
            [deviceId]: {
              ...(prev[deviceId] || { loading: false, state: null, lastUpdated: null }),
              loading: false,
              error: errorMsg,
            },
          }));
          return null;
        }
      } catch (err) {
        const errorMsg = err instanceof Error ? err.message : 'Unknown error';
        setDeviceStates(prev => ({
          ...prev,
          [deviceId]: {
            ...(prev[deviceId] || { loading: false, state: null, lastUpdated: null }),
            loading: false,
            error: errorMsg,
          },
        }));
        return null;
      }
    },
    []
  );

  /**
   * Refresh devices manually
   */
  const refresh = useCallback(() => {
    loadDevices();
    loadHome();
  }, [loadDevices, loadHome]);


  /**
   * Fetch states for ALL devices in one go.
   * The backend calls {DeviceType}.getState for each device.
   */
  const fetchAllStates = useCallback(async () => {
    try {
      // Mark all devices as loading
      setDeviceStates(prev => {
        const next: DeviceState = { ...prev };
        devices.forEach(d => {
          next[d.deviceId] = {
            ...(prev[d.deviceId] || { state: null, error: null, lastUpdated: null }),
            loading: true,
            error: null,
          };
        });
        return next;
      });

      const response = await fetchAllDeviceStates(storeId);

      if (response.success && response.devices) {
        const next: DeviceState = {};
        for (const dev of response.devices as any[]) {
          next[dev.deviceId] = {
            loading: false,
            state: dev.success ? dev.state : null,
            error: dev.success ? null : (dev.error || 'Failed'),
            lastUpdated: dev.success ? new Date() : null,
          };
        }
        setDeviceStates(next);
        // Also update the device list with full info
        setDevices(
          (response.devices as any[]).map((d: any) => ({
            deviceId: d.deviceId,
            name: d.name,
            type: d.deviceType,
            modelName: d.modelName || '',
            ...d,
          }))
        );
        setLastSync(new Date());
      } else {
        console.error('Failed to fetch all device states:', response.error);
      }
    } catch (err) {
      console.error('fetchAllStates error:', err);
    }
  }, [devices]);

  /**
   * Initial load
   */
  useEffect(() => {
    // Reset state when switching stores
    setDevices([]);
    setDeviceStates({});
    setHomeId(null);
    setLastSync(null);
    setError(null);
    loadDevices();
    loadHome();
  }, [storeId, loadDevices, loadHome]);

  /**
   * Get state for a specific device (from cache or loading state)
   */
  const getDeviceStatus = useCallback((deviceId: string) => {
    return deviceStates[deviceId] || {
      loading: false,
      state: null,
      error: null,
      lastUpdated: null,
    };
  }, [deviceStates]);

  return {
    devices,
    homeId,
    deviceStates,
    loading,
    error,
    lastSync,
    refresh,
    getState,
    control,
    getDeviceStatus,
    fetchAllStates,
  };
}
