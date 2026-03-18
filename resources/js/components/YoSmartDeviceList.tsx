import { useEffect, useState, useCallback } from 'react';
import { usePage } from '@inertiajs/react';
import {
  Thermometer,
  Droplets,
  BatteryLow,
  BatteryMedium,
  BatteryFull,
  Wifi,
  WifiOff,
  RefreshCw,
  Activity,
  CircleAlert,
  Clock,
  Snowflake,
  Server,
  Signal,
} from 'lucide-react';
import { useYoSmartDevices } from '@/hooks/useYoSmartDevices';
import { index as storesIndex } from '@/routes/stores';
import { Card, CardContent, CardHeader, CardTitle, CardDescription } from '@/components/ui/card';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Skeleton } from '@/components/ui/skeleton';
import { Separator } from '@/components/ui/separator';
import { Tooltip, TooltipTrigger, TooltipContent } from '@/components/ui/tooltip';
import { Spinner } from '@/components/ui/spinner';

// ─── Helpers ──────────────────────────────────────────────────────────

/** Convert Celsius to Fahrenheit */
function cToF(c: number): number {
  return c * 9 / 5 + 32;
}

/** Convert Fahrenheit to Celsius */
function fToC(f: number): number {
  return (f - 32) * 5 / 9;
}

/**
 * Convert a temperature value from the device's native mode to the
 * target display unit, then format it.
 * YoSmart API always returns values in Celsius regardless of the device's
 * physical display mode setting.
 */
function formatTemp(value: number, mode?: string, targetUnit: string = 'F'): string {
  const target = targetUnit.toUpperCase();
  // API always returns Celsius — convert to F if needed
  const converted = target === 'F' ? cToF(value) : value;
  return `${converted.toFixed(1)}°${target}`;
}

/** Pretty-print a UTC ISO timestamp as local time */
function formatReportTime(iso?: string): string {
  if (!iso) return '—';
  const d = new Date(iso);
  return d.toLocaleString(undefined, {
    month: 'short',
    day: 'numeric',
    hour: '2-digit',
    minute: '2-digit',
  });
}

/** Return how long ago a date was in human words */
function timeAgo(date: Date): string {
  const seconds = Math.floor((Date.now() - date.getTime()) / 1000);
  if (seconds < 60) return 'just now';
  const minutes = Math.floor(seconds / 60);
  if (minutes < 60) return `${minutes}m ago`;
  const hours = Math.floor(minutes / 60);
  if (hours < 24) return `${hours}h ago`;
  return `${Math.floor(hours / 24)}d ago`;
}

/** Battery icon based on level (0-4) */
function BatteryIcon({ level }: { level: number }) {
  if (level <= 1) return <BatteryLow className="size-4 text-red-500" />;
  if (level <= 2) return <BatteryMedium className="size-4 text-amber-500" />;
  return <BatteryFull className="size-4 text-emerald-500" />;
}

/** Map battery level (0-4) to percentage approximation */
function batteryPercent(level: number): string {
  const map: Record<number, string> = { 0: '0%', 1: '25%', 2: '50%', 3: '75%', 4: '100%' };
  return map[level] ?? `${level}`;
}

/** Determine temperature colour class (value is always Celsius from API) */
function tempColor(value: number, mode?: string): string {
  const f = cToF(value); // API always returns Celsius
  if (f <= 0) return 'text-blue-600 dark:text-blue-400';
  if (f <= 32) return 'text-sky-600 dark:text-sky-400';
  if (f <= 60) return 'text-teal-600 dark:text-teal-400';
  if (f <= 80) return 'text-amber-600 dark:text-amber-400';
  return 'text-red-600 dark:text-red-400';
}

/** Icon for device type */
function deviceTypeIcon(type: string) {
  switch (type) {
    case 'THSensor':
      return <Thermometer className="size-5" />;
    case 'Hub':
      return <Server className="size-5" />;
    default:
      return <Activity className="size-5" />;
  }
}

// ─── Sub-components ───────────────────────────────────────────────────

function StatItem({
  icon,
  label,
  value,
  valueClass,
}: {
  icon: React.ReactNode;
  label: string;
  value: React.ReactNode;
  valueClass?: string;
}) {
  return (
    <div className="flex items-center gap-2">
      <span className="text-muted-foreground">{icon}</span>
      <div className="flex flex-col">
        <span className="text-[11px] uppercase tracking-wider text-muted-foreground">{label}</span>
        <span className={valueClass ?? 'text-sm font-semibold'}>{value}</span>
      </div>
    </div>
  );
}

// ─── Loading skeleton ─────────────────────────────────────────────────

function DeviceCardSkeleton() {
  return (
    <Card className="overflow-hidden">
      <CardHeader className="pb-2">
        <div className="flex items-center gap-3">
          <Skeleton className="size-10 rounded-lg" />
          <div className="flex-1 space-y-2">
            <Skeleton className="h-4 w-28" />
            <Skeleton className="h-3 w-20" />
          </div>
          <Skeleton className="h-5 w-14 rounded-full" />
        </div>
      </CardHeader>
      <CardContent className="space-y-4">
        <div className="grid grid-cols-2 gap-4">
          <Skeleton className="h-16 rounded-lg" />
          <Skeleton className="h-16 rounded-lg" />
        </div>
        <Skeleton className="h-8 w-full rounded-lg" />
      </CardContent>
    </Card>
  );
}

// ─── Summary cards ────────────────────────────────────────────────────

function SummaryCards({
  devices,
  deviceStates,
}: {
  devices: any[];
  deviceStates: Record<string, any>;
}) {
  const totalDevices = devices.length;
  const onlineCount = devices.filter((d) => {
    const st = deviceStates[d.deviceId];
    return st?.state?.online === true;
  }).length;
  const offlineCount = devices.filter((d) => {
    const st = deviceStates[d.deviceId];
    return st?.state && st.state.online === false;
  }).length;
  const alertCount = devices.filter((d) => {
    const st = deviceStates[d.deviceId];
    const alarm = st?.state?.state?.alarm;
    if (!alarm) return false;
    return alarm.lowBattery || alarm.lowTemp || alarm.highTemp || alarm.lowHumidity || alarm.highHumidity;
  }).length;

  const summaryItems = [
    {
      label: 'Total Devices',
      value: totalDevices,
      icon: <Activity className="size-4" />,
      color: 'text-foreground',
      bg: 'bg-muted/50',
    },
    {
      label: 'Online',
      value: onlineCount,
      icon: <Wifi className="size-4 text-emerald-500" />,
      color: 'text-emerald-600 dark:text-emerald-400',
      bg: 'bg-emerald-50 dark:bg-emerald-950/30',
    },
    {
      label: 'Offline',
      value: offlineCount,
      icon: <WifiOff className="size-4 text-red-500" />,
      color: 'text-red-600 dark:text-red-400',
      bg: 'bg-red-50 dark:bg-red-950/30',
    },
    {
      label: 'Alerts',
      value: alertCount,
      icon: <CircleAlert className="size-4 text-amber-500" />,
      color: 'text-amber-600 dark:text-amber-400',
      bg: 'bg-amber-50 dark:bg-amber-950/30',
    },
  ];

  return (
    <div className="grid grid-cols-2 gap-3 sm:grid-cols-4">
      {summaryItems.map((item) => (
        <Card key={item.label} className={`${item.bg} border-0 shadow-none py-4`}>
          <CardContent className="flex items-center gap-3 px-4 py-0">
            {item.icon}
            <div>
              <p className="text-xs text-muted-foreground">{item.label}</p>
              <p className={`text-xl font-bold ${item.color}`}>{item.value}</p>
            </div>
          </CardContent>
        </Card>
      ))}
    </div>
  );
}

// ─── THSensor Card ────────────────────────────────────────────────────

function THSensorCard({
  device,
  status,
  onRefresh,
  targetUnit,
}: {
  device: any;
  status: { loading: boolean; state: any; error: string | null; lastUpdated: Date | null };
  onRefresh: () => void;
  targetUnit: string;
}) {
  const data = status.state;
  const online = data?.online;
  const state = data?.state;
  const reportAt = data?.reportAt;

  const temperature = state?.temperature;
  const humidity = state?.humidity;
  const battery = state?.battery;
  const mode = state?.mode;
  const alarm = state?.alarm;
  const hasAlarm =
    alarm && (alarm.lowBattery || alarm.lowTemp || alarm.highTemp || alarm.lowHumidity || alarm.highHumidity);

  // API always returns Celsius; ≤8°C = fridge/freezer (shows snowflake icon)
  const isFreezer = temperature !== undefined && temperature <= 8;

  return (
    <Card className="group overflow-hidden transition-shadow hover:shadow-md">
      {/* Header */}
      <CardHeader className="pb-3">
        <div className="flex items-start justify-between">
          <div className="flex items-center gap-3">
            <div
              className={`flex size-10 items-center justify-center rounded-lg ${
                isFreezer
                  ? 'bg-blue-100 text-blue-600 dark:bg-blue-900/40 dark:text-blue-400'
                  : 'bg-orange-100 text-orange-600 dark:bg-orange-900/40 dark:text-orange-400'
              }`}
            >
              {isFreezer ? <Snowflake className="size-5" /> : <Thermometer className="size-5" />}
            </div>
            <div>
              <CardTitle className="text-base">{device.name}</CardTitle>
              <CardDescription className="text-xs">{device.modelName}</CardDescription>
            </div>
          </div>
          <div className="flex items-center gap-1.5">
            {hasAlarm && (
              <Tooltip>
                <TooltipTrigger>
                  <Badge variant="destructive" className="gap-1 text-[10px]">
                    <CircleAlert className="size-3" /> Alert
                  </Badge>
                </TooltipTrigger>
                <TooltipContent>
                  {alarm.lowTemp && <p>Low temperature alarm</p>}
                  {alarm.highTemp && <p>High temperature alarm</p>}
                  {alarm.lowBattery && <p>Low battery</p>}
                  {alarm.lowHumidity && <p>Low humidity alarm</p>}
                  {alarm.highHumidity && <p>High humidity alarm</p>}
                </TooltipContent>
              </Tooltip>
            )}
            {data ? (
              <Badge
                variant={online ? 'secondary' : 'destructive'}
                className={`text-[10px] ${
                  online
                    ? 'bg-emerald-100 text-emerald-700 dark:bg-emerald-900/40 dark:text-emerald-400'
                    : ''
                }`}
              >
                {online ? (
                  <>
                    <Wifi className="size-3" /> Online
                  </>
                ) : (
                  <>
                    <WifiOff className="size-3" /> Offline
                  </>
                )}
              </Badge>
            ) : null}
          </div>
        </div>
      </CardHeader>

      <CardContent className="space-y-3 pb-4">
        {status.loading && !data ? (
          <div className="flex items-center justify-center py-6 text-muted-foreground">
            <Spinner className="size-5 mr-2" />
            <span className="text-sm">Fetching sensor data…</span>
          </div>
        ) : status.error && !data ? (
          <div className="rounded-lg border border-red-200 bg-red-50 p-3 dark:border-red-800 dark:bg-red-950/30">
            <p className="text-xs text-red-600 dark:text-red-400">{status.error}</p>
          </div>
        ) : data ? (
          <>
            {/* Big temperature reading */}
            <div className="flex items-end justify-between">
              <div>
                <p className="text-[11px] uppercase tracking-wider text-muted-foreground mb-0.5">
                  Temperature
                </p>
                <p className={`text-3xl font-bold tabular-nums leading-none ${tempColor(temperature, mode)}`}>
                  {formatTemp(temperature, mode, targetUnit)}
                </p>
              </div>
              {humidity !== undefined && humidity > 0 && (
                <StatItem
                  icon={<Droplets className="size-4 text-sky-500" />}
                  label="Humidity"
                  value={`${humidity}%`}
                  valueClass="text-sm font-semibold text-sky-600 dark:text-sky-400"
                />
              )}
            </div>

            <Separator />

            {/* Bottom stats row */}
            <div className="flex items-center justify-between text-xs">
              <Tooltip>
                <TooltipTrigger asChild>
                  <div className="flex items-center gap-1.5 text-muted-foreground">
                    <BatteryIcon level={battery ?? 0} />
                    <span>{batteryPercent(battery ?? 0)}</span>
                  </div>
                </TooltipTrigger>
                <TooltipContent>Battery level: {batteryPercent(battery ?? 0)}</TooltipContent>
              </Tooltip>

              {state?.loraInfo && (
                <Tooltip>
                  <TooltipTrigger asChild>
                    <div className="flex items-center gap-1 text-muted-foreground">
                      <Signal className="size-3.5" />
                      <span>{state.loraInfo.devNetType}</span>
                    </div>
                  </TooltipTrigger>
                  <TooltipContent>LoRa network type {state.loraInfo.devNetType}</TooltipContent>
                </Tooltip>
              )}

              <Tooltip>
                <TooltipTrigger asChild>
                  <div className="flex items-center gap-1 text-muted-foreground">
                    <Clock className="size-3.5" />
                    <span>{formatReportTime(reportAt)}</span>
                  </div>
                </TooltipTrigger>
                <TooltipContent>Last report: {reportAt ?? 'unknown'}</TooltipContent>
              </Tooltip>
            </div>

            {/* Temp limits (subtle) */}
            {state?.tempLimit && state.tempLimit.min > -999 && (
              <div className="rounded-md bg-muted/50 px-3 py-1.5 text-[11px] text-muted-foreground">
                Temp range: {formatTemp(state.tempLimit.min, mode, targetUnit)} — {formatTemp(state.tempLimit.max, mode, targetUnit)}
              </div>
            )}
          </>
        ) : (
          <div className="flex flex-col items-center gap-2 py-6 text-center text-muted-foreground">
            <Thermometer className="size-8 opacity-30" />
            <p className="text-sm">No data yet</p>
          </div>
        )}

        {/* Refresh button */}
        <Button
          variant="outline"
          size="sm"
          className="w-full"
          onClick={onRefresh}
          disabled={status.loading}
        >
          {status.loading ? (
            <>
              <Spinner className="size-3.5" />
              <span>Updating…</span>
            </>
          ) : (
            <>
              <RefreshCw className="size-3.5" />
              <span>Refresh</span>
            </>
          )}
        </Button>
      </CardContent>
    </Card>
  );
}

// ─── Hub Card ─────────────────────────────────────────────────────────

function HubCard({
  device,
  status,
  onRefresh,
}: {
  device: any;
  status: { loading: boolean; state: any; error: string | null; lastUpdated: Date | null };
  onRefresh: () => void;
}) {
  const data = status.state;
  const online = data?.online;

  return (
    <Card className="overflow-hidden border-2 border-dashed transition-shadow hover:shadow-md">
      <CardHeader className="pb-3">
        <div className="flex items-start justify-between">
          <div className="flex items-center gap-3">
            <div className="flex size-10 items-center justify-center rounded-lg bg-violet-100 text-violet-600 dark:bg-violet-900/40 dark:text-violet-400">
              <Server className="size-5" />
            </div>
            <div>
              <CardTitle className="text-base">{device.name}</CardTitle>
              <CardDescription className="text-xs">{device.modelName}</CardDescription>
            </div>
          </div>
          {data ? (
            <Badge
              variant={online ? 'secondary' : 'destructive'}
              className={`text-[10px] ${
                online
                  ? 'bg-emerald-100 text-emerald-700 dark:bg-emerald-900/40 dark:text-emerald-400'
                  : ''
              }`}
            >
              {online ? (
                <>
                  <Wifi className="size-3" /> Online
                </>
              ) : (
                <>
                  <WifiOff className="size-3" /> Offline
                </>
              )}
            </Badge>
          ) : null}
        </div>
      </CardHeader>

      <CardContent className="pb-4">
        {status.loading && !data ? (
          <div className="flex items-center justify-center py-4 text-muted-foreground">
            <Spinner className="size-5 mr-2" />
            <span className="text-sm">Fetching hub status…</span>
          </div>
        ) : status.error && !data ? (
          <div className="rounded-lg border border-red-200 bg-red-50 p-3 dark:border-red-800 dark:bg-red-950/30">
            <p className="text-xs text-red-600 dark:text-red-400">{status.error}</p>
          </div>
        ) : data ? (
          <div className="space-y-3">
            <div className="rounded-lg bg-muted/50 p-3">
              <p className="text-xs text-muted-foreground">
                Hub is the central bridge connecting all YoLink sensors.
              </p>
              {data.state?.version && (
                <p className="mt-1 text-xs text-muted-foreground">
                  Firmware: <span className="font-mono">{data.state.version}</span>
                </p>
              )}
              {data.state?.wifi && (
                <p className="mt-1 text-xs text-muted-foreground">
                  WiFi SSID: <span className="font-medium">{data.state.wifi.ssid}</span>
                </p>
              )}
            </div>
            {status.lastUpdated && (
              <p className="text-[11px] text-muted-foreground text-right">
                Updated {timeAgo(status.lastUpdated)}
              </p>
            )}
          </div>
        ) : (
          <div className="flex flex-col items-center gap-2 py-4 text-center text-muted-foreground">
            <Server className="size-8 opacity-30" />
            <p className="text-sm">No data yet</p>
          </div>
        )}

        <Button
          variant="outline"
          size="sm"
          className="mt-3 w-full"
          onClick={onRefresh}
          disabled={status.loading}
        >
          {status.loading ? (
            <>
              <Spinner className="size-3.5" />
              <span>Updating…</span>
            </>
          ) : (
            <>
              <RefreshCw className="size-3.5" />
              <span>Refresh</span>
            </>
          )}
        </Button>
      </CardContent>
    </Card>
  );
}

// ─── Generic fallback card ────────────────────────────────────────────

function GenericDeviceCard({
  device,
  status,
  onRefresh,
}: {
  device: any;
  status: { loading: boolean; state: any; error: string | null; lastUpdated: Date | null };
  onRefresh: () => void;
}) {
  const data = status.state;
  const online = data?.online;

  return (
    <Card className="overflow-hidden transition-shadow hover:shadow-md">
      <CardHeader className="pb-3">
        <div className="flex items-start justify-between">
          <div className="flex items-center gap-3">
            <div className="flex size-10 items-center justify-center rounded-lg bg-muted text-muted-foreground">
              {deviceTypeIcon(device.type)}
            </div>
            <div>
              <CardTitle className="text-base">{device.name}</CardTitle>
              <CardDescription className="text-xs">
                {device.type} · {device.modelName}
              </CardDescription>
            </div>
          </div>
          {data !== undefined && data !== null && (
            <Badge
              variant={online ? 'secondary' : 'destructive'}
              className={`text-[10px] ${
                online
                  ? 'bg-emerald-100 text-emerald-700 dark:bg-emerald-900/40 dark:text-emerald-400'
                  : ''
              }`}
            >
              {online ? 'Online' : 'Offline'}
            </Badge>
          )}
        </div>
      </CardHeader>

      <CardContent className="pb-4">
        {status.loading && !data ? (
          <div className="flex items-center justify-center py-4 text-muted-foreground">
            <Spinner className="size-5 mr-2" />
            <span className="text-sm">Loading…</span>
          </div>
        ) : data ? (
          <div className="rounded-lg bg-muted/40 p-3">
            <pre className="text-xs text-muted-foreground overflow-auto max-h-40 whitespace-pre-wrap break-words">
              {JSON.stringify(data.state || data, null, 2)}
            </pre>
          </div>
        ) : status.error ? (
          <div className="rounded-lg border border-red-200 bg-red-50 p-3 dark:border-red-800 dark:bg-red-950/30">
            <p className="text-xs text-red-600 dark:text-red-400">{status.error}</p>
          </div>
        ) : (
          <div className="py-4 text-center text-sm text-muted-foreground">No data yet</div>
        )}

        <Button
          variant="outline"
          size="sm"
          className="mt-3 w-full"
          onClick={onRefresh}
          disabled={status.loading}
        >
          {status.loading ? (
            <>
              <Spinner className="size-3.5" />
              <span>Updating…</span>
            </>
          ) : (
            <>
              <RefreshCw className="size-3.5" />
              <span>Refresh</span>
            </>
          )}
        </Button>
      </CardContent>
    </Card>
  );
}

// ─── Dispatcher ───────────────────────────────────────────────────────

function DeviceCardDispatcher({
  device,
  status,
  onRefresh,
  targetUnit,
}: {
  device: any;
  status: any;
  onRefresh: () => void;
  targetUnit: string;
}) {
  switch (device.type) {
    case 'THSensor':
      return <THSensorCard device={device} status={status} onRefresh={onRefresh} targetUnit={targetUnit} />;
    case 'Hub':
      return <HubCard device={device} status={status} onRefresh={onRefresh} />;
    default:
      return <GenericDeviceCard device={device} status={status} onRefresh={onRefresh} />;
  }
}

// ─── Types ───────────────────────────────────────────────────────────

interface StoreOption {
  id: number;
  store_name: string;
  store_number: string;
  yosmart_uaid?: string | null;
}

// ─── Store Selector ───────────────────────────────────────────────────

function StoreSelector({
  stores,
  selectedId,
  onChange,
}: {
  stores: StoreOption[];
  selectedId: number | null;
  onChange: (id: number) => void;
}) {
  return (
    <div className="flex flex-wrap gap-2">
      {stores.map((s) => (
        <button
          key={s.id}
          onClick={() => onChange(s.id)}
          className={`rounded-lg border px-4 py-2 text-sm font-medium transition-colors ${
            selectedId === s.id
              ? 'border-primary bg-primary text-primary-foreground'
              : 'border-border bg-background text-foreground hover:bg-muted'
          }`}
        >
          {s.store_name}
          <span className="ml-1.5 font-mono text-[11px] opacity-60">{s.store_number}</span>
        </button>
      ))}
    </div>
  );
}

// ─── Main Component ───────────────────────────────────────────────────

export function YoSmartDeviceList() {
  const { temperatureUnit } = usePage().props;
  const targetUnit = temperatureUnit ?? 'F';

  // ── Store selection ──
  const [stores, setStores] = useState<StoreOption[]>([]);
  const [storesLoading, setStoresLoading] = useState(true);
  const [selectedStoreId, setSelectedStoreId] = useState<number | null>(null);

  const loadStores = useCallback(async () => {
    setStoresLoading(true);
    try {
      const res = await fetch(storesIndex.url(), {
        headers: { Accept: 'application/json' },
        credentials: 'include',
      });
      const data = await res.json();
      if (data.success && data.stores) {
        const configured: StoreOption[] = data.stores.filter((s: StoreOption) => s.yosmart_uaid);
        setStores(configured);
        if (configured.length > 0) setSelectedStoreId(configured[0].id);
      }
    } catch {
      // silent
    } finally {
      setStoresLoading(false);
    }
  }, []);

  useEffect(() => { loadStores(); }, [loadStores]);

  // ── Guard: no stores configured ──
  if (storesLoading) {
    return (
      <div className="space-y-6">
        <div className="grid grid-cols-2 gap-3 sm:grid-cols-4">
          {Array.from({ length: 4 }).map((_, i) => <Skeleton key={i} className="h-20 rounded-xl" />)}
        </div>
        <div className="grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-3">
          {Array.from({ length: 6 }).map((_, i) => <DeviceCardSkeleton key={i} />)}
        </div>
      </div>
    );
  }

  if (stores.length === 0) {
    return (
      <Card>
        <CardContent className="flex flex-col items-center gap-3 py-12 text-center">
          <WifiOff className="size-12 text-muted-foreground/30" />
          <p className="text-lg font-medium">No YoSmart credentials configured</p>
          <p className="text-sm text-muted-foreground">
            Go to <a href="/stores" className="underline">Stores</a> and add YoSmart UAID & secret to at least one store.
          </p>
        </CardContent>
      </Card>
    );
  }

  if (!selectedStoreId) return null;

  return (
    <div className="space-y-4">
      {stores.length > 1 && (
        <StoreSelector stores={stores} selectedId={selectedStoreId} onChange={setSelectedStoreId} />
      )}
      <StoreDeviceView storeId={selectedStoreId} targetUnit={targetUnit} />
    </div>
  );
}

// ─── Per-store device view ────────────────────────────────────────────

function StoreDeviceView({ storeId, targetUnit }: { storeId: number; targetUnit: string }) {
  const {
    devices,
    deviceStates,
    loading,
    error,
    refresh,
    lastSync,
    fetchAllStates,
    getState,
    getDeviceStatus,
  } = useYoSmartDevices(storeId);

  // Auto-fetch all states once devices are loaded
  useEffect(() => {
    if (devices.length > 0 && Object.keys(deviceStates).length === 0) {
      fetchAllStates();
    }
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [devices.length]);

  // ── Loading state ──
  if (loading) {
    return (
      <div className="space-y-6">
        <div className="grid grid-cols-2 gap-3 sm:grid-cols-4">
          {Array.from({ length: 4 }).map((_, i) => (
            <Skeleton key={i} className="h-20 rounded-xl" />
          ))}
        </div>
        <div className="grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-3">
          {Array.from({ length: 6 }).map((_, i) => (
            <DeviceCardSkeleton key={i} />
          ))}
        </div>
      </div>
    );
  }

  // ── Error state ──
  if (error) {
    return (
      <Card className="border-red-200 bg-red-50 dark:border-red-800 dark:bg-red-950/30">
        <CardHeader>
          <div className="flex items-center gap-2">
            <CircleAlert className="size-5 text-red-500" />
            <CardTitle className="text-red-700 dark:text-red-400">Connection Error</CardTitle>
          </div>
          <CardDescription className="text-red-600/80 dark:text-red-400/80">{error}</CardDescription>
        </CardHeader>
        <CardContent>
          <Button variant="destructive" size="sm" onClick={() => refresh()}>
            <RefreshCw className="size-4" />
            Try Again
          </Button>
        </CardContent>
      </Card>
    );
  }

  // ── Sort: sensors first, hub last ──
  const sortedDevices = [...devices].sort((a, b) => {
    if (a.type === 'Hub' && b.type !== 'Hub') return 1;
    if (a.type !== 'Hub' && b.type === 'Hub') return -1;
    return (a.name ?? '').localeCompare(b.name ?? '');
  });

  const sensors = sortedDevices.filter((d) => d.type !== 'Hub');
  const hubs = sortedDevices.filter((d) => d.type === 'Hub');

  return (
    <div className="space-y-6">
      {/* ── Header ── */}
      <div className="flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
        <div>
          <h2 className="text-2xl font-bold tracking-tight">Sensor Dashboard</h2>
          {lastSync && (
            <p className="mt-0.5 text-sm text-muted-foreground">
              Last synced {timeAgo(lastSync)}
            </p>
          )}
        </div>
        <div className="flex gap-2">
          <Button variant="default" onClick={() => fetchAllStates()}>
            <Activity className="size-4" />
            Refresh All
          </Button>
          <Button variant="outline" onClick={() => refresh()}>
            <RefreshCw className="size-4" />
            Reload Devices
          </Button>
        </div>
      </div>

      {/* ── Summary cards ── */}
      <SummaryCards devices={devices} deviceStates={deviceStates} />

      {/* ── No devices ── */}
      {devices.length === 0 && (
        <Card>
          <CardContent className="flex flex-col items-center gap-3 py-12 text-center">
            <WifiOff className="size-12 text-muted-foreground/30" />
            <p className="text-lg font-medium text-muted-foreground">No devices found</p>
            <p className="text-sm text-muted-foreground/70">
              Make sure your YoSmart Hub is connected and devices are paired.
            </p>
          </CardContent>
        </Card>
      )}

      {/* ── Sensor grid ── */}
      {sensors.length > 0 && (
        <div>
          <h3 className="mb-3 text-sm font-medium uppercase tracking-wider text-muted-foreground">
            Sensors ({sensors.length})
          </h3>
          <div className="grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-3">
            {sensors.map((device) => (
              <DeviceCardDispatcher
                key={device.deviceId}
                device={device}
                status={getDeviceStatus(device.deviceId)}
                onRefresh={() => getState(device.deviceId, device.token, device.type)}
                targetUnit={targetUnit}
              />
            ))}
          </div>
        </div>
      )}

      {/* ── Hub section ── */}
      {hubs.length > 0 && (
        <div>
          <h3 className="mb-3 text-sm font-medium uppercase tracking-wider text-muted-foreground">
            Hub
          </h3>
          <div className="grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-3">
            {hubs.map((device) => (
              <DeviceCardDispatcher
                key={device.deviceId}
                device={device}
                status={getDeviceStatus(device.deviceId)}
                onRefresh={() => getState(device.deviceId, device.token, device.type)}
                targetUnit={targetUnit}
              />
            ))}
          </div>
        </div>
      )}
    </div>
  );
}
