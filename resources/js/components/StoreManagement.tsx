import {
  Plus,
  Pencil,
  Trash2,
  Store as StoreIcon,
  Link2,
  Unlink,
  Server,
  Thermometer,
  Activity,
  RefreshCw,
  Search,
  XCircle,
  ChevronDown,
  ChevronUp,
} from 'lucide-react';
import { useState, useEffect, useCallback } from 'react';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle, CardDescription } from '@/components/ui/card';
import { Checkbox } from '@/components/ui/checkbox';
import {
  Dialog,
  DialogContent,
  DialogDescription,
  DialogFooter,
  DialogHeader,
  DialogTitle,
} from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Separator } from '@/components/ui/separator';
import { Skeleton } from '@/components/ui/skeleton';
import { Spinner } from '@/components/ui/spinner';
import {
  index as storesIndex,
  store as storesCreate,
  show as storesShow,
  update as storesUpdate,
  destroy as storesDestroy,
} from '@/routes/stores';
import {
  link as devicesLink,
  unlink as devicesUnlink,
  available as devicesAvailable,
} from '@/routes/stores/devices';

// ─── Types ────────────────────────────────────────────────────────────

interface StoreDevice {
  id: number;
  store_id: number;
  device_id: string;
  device_token: string;
  device_type: string;
  device_name: string;
  model_name: string | null;
  is_hub: boolean;
}

interface Store {
  id: number;
  store_number: string;
  store_name: string;
  is_active: boolean;
  devices_count: number;
  hubs_count: number;
  sensors_count: number;
  devices?: StoreDevice[];
}

interface AvailableDevice {
  id: string;
  name: string;
  type: string;
  model: string;
  token: string;
}

// ─── CSRF helper ──────────────────────────────────────────────────────

function getCsrfToken(): string {
  const meta = document.querySelector('meta[name="csrf-token"]') as HTMLMetaElement;
  if (meta) return meta.content;
  const v = `; ${document.cookie}`;
  const parts = v.split('; XSRF-TOKEN=');
  if (parts.length === 2) return decodeURIComponent(parts.pop()?.split(';').shift() || '');
  return '';
}

async function apiFetch(url: string, method: string = 'GET', body?: Record<string, unknown>) {
  const headers: Record<string, string> = {
    Accept: 'application/json',
    'Content-Type': 'application/json',
    'X-Requested-With': 'XMLHttpRequest',
  };
  if (method !== 'GET' && method !== 'HEAD') {
    headers['X-CSRF-Token'] = getCsrfToken();
  }
  const res = await fetch(url, {
    method,
    headers,
    credentials: 'include',
    body: body ? JSON.stringify(body) : undefined,
  });
  return res.json();
}

// ─── Device type icon ─────────────────────────────────────────────────

function DeviceTypeIcon({ type }: { type: string }) {
  switch (type) {
    case 'Hub':
      return <Server className="size-4" />;
    case 'THSensor':
      return <Thermometer className="size-4" />;
    default:
      return <Activity className="size-4" />;
  }
}

// ─── Store Form Dialog ────────────────────────────────────────────────

function StoreFormDialog({
  store,
  open,
  onOpenChange,
  onSaved,
}: {
  store?: Store | null;
  open: boolean;
  onOpenChange: (open: boolean) => void;
  onSaved: () => void;
}) {
  const [storeNumber, setStoreNumber] = useState('');
  const [storeName, setStoreName] = useState('');
  const [saving, setSaving] = useState(false);
  const [error, setError] = useState<string | null>(null);

  const isEdit = !!store;

  useEffect(() => {
    if (open) {
      setStoreNumber(store?.store_number || '');
      setStoreName(store?.store_name || '');
      setError(null);
    }
  }, [open, store]);

  const handleSubmit = async (e: React.FormEvent) => {
    e.preventDefault();
    setSaving(true);
    setError(null);

    try {
      const payload = { store_number: storeNumber, store_name: storeName };
      let result;

      if (isEdit && store) {
        result = await apiFetch(storesUpdate.url(store.id), 'PUT', payload);
      } else {
        result = await apiFetch(storesCreate.url(), 'POST', payload);
      }

      if (result.success) {
        onOpenChange(false);
        onSaved();
      } else {
        // Handle validation errors
        if (result.errors) {
          const messages = Object.values(result.errors).flat().join(', ');
          setError(messages);
        } else {
          setError(result.error || result.message || 'Failed to save store');
        }
      }
    } catch (err) {
      setError(err instanceof Error ? err.message : 'An error occurred');
    } finally {
      setSaving(false);
    }
  };

  return (
    <Dialog open={open} onOpenChange={onOpenChange}>
      <DialogContent className="sm:max-w-md">
        <form onSubmit={handleSubmit}>
          <DialogHeader>
            <DialogTitle>{isEdit ? 'Edit Store' : 'Add Store'}</DialogTitle>
            <DialogDescription>
              {isEdit ? 'Update store details.' : 'Create a new store to link devices to.'}
            </DialogDescription>
          </DialogHeader>

          <div className="mt-4 space-y-4">
            <div className="space-y-2">
              <Label htmlFor="store_number">Store Number</Label>
              <Input
                id="store_number"
                value={storeNumber}
                onChange={(e) => setStoreNumber(e.target.value)}
                placeholder="e.g. 03795-02"
                required
              />
            </div>
            <div className="space-y-2">
              <Label htmlFor="store_name">Store Name</Label>
              <Input
                id="store_name"
                value={storeName}
                onChange={(e) => setStoreName(e.target.value)}
                placeholder="e.g. Hudson"
                required
              />
            </div>

            {error && (
              <div className="flex items-center gap-2 rounded-md bg-destructive/10 px-3 py-2 text-sm text-destructive">
                <XCircle className="size-4 shrink-0" />
                {error}
              </div>
            )}
          </div>

          <DialogFooter className="mt-6">
            <Button type="button" variant="outline" onClick={() => onOpenChange(false)} disabled={saving}>
              Cancel
            </Button>
            <Button type="submit" disabled={saving}>
              {saving && <Spinner className="mr-2 size-4" />}
              {isEdit ? 'Update' : 'Create'}
            </Button>
          </DialogFooter>
        </form>
      </DialogContent>
    </Dialog>
  );
}

// ─── Link Devices Dialog ──────────────────────────────────────────────

function LinkDevicesDialog({
  store,
  open,
  onOpenChange,
  onLinked,
}: {
  store: Store;
  open: boolean;
  onOpenChange: (open: boolean) => void;
  onLinked: () => void;
}) {
  const [availableDevs, setAvailableDevs] = useState<AvailableDevice[]>([]);
  const [selected, setSelected] = useState<Set<string>>(new Set());
  const [loading, setLoading] = useState(false);
  const [saving, setSaving] = useState(false);
  const [error, setError] = useState<string | null>(null);
  const [search, setSearch] = useState('');

  // Already-linked device IDs so we can mark them
  const linkedIds = new Set(store.devices?.map((d) => d.device_id) || []);

  const fetchAvailable = useCallback(async () => {
    setLoading(true);
    setError(null);
    try {
      const data = await apiFetch(devicesAvailable.url());
      if (data.success && data.devices) {
        setAvailableDevs(
          data.devices.map((d: Record<string, string>) => ({
            id: d.deviceId,
            name: d.name,
            type: d.type,
            model: d.modelName,
            token: d.token,
          }))
        );
      } else {
        setError(data.error || 'Failed to load devices');
      }
    } catch (err) {
      setError(err instanceof Error ? err.message : 'Failed to load devices');
    } finally {
      setLoading(false);
    }
  }, []);

  useEffect(() => {
    if (open) {
      setSelected(new Set());
      setSearch('');
      fetchAvailable();
    }
  }, [open, fetchAvailable]);

  const filtered = availableDevs.filter(
    (d) =>
      !linkedIds.has(d.id) &&
      (d.name.toLowerCase().includes(search.toLowerCase()) ||
        d.type.toLowerCase().includes(search.toLowerCase()) ||
        d.id.toLowerCase().includes(search.toLowerCase()))
  );

  const toggleDevice = (id: string) => {
    setSelected((prev) => {
      const next = new Set(prev);
      if (next.has(id)) next.delete(id);
      else next.add(id);
      return next;
    });
  };

  const toggleAll = () => {
    if (selected.size === filtered.length) {
      setSelected(new Set());
    } else {
      setSelected(new Set(filtered.map((d) => d.id)));
    }
  };

  const handleLink = async () => {
    if (selected.size === 0) return;
    setSaving(true);
    setError(null);

    const devices = availableDevs
      .filter((d) => selected.has(d.id))
      .map((d) => ({
        device_id: d.id,
        device_token: d.token,
        device_type: d.type,
        device_name: d.name,
        model_name: d.model,
        is_hub: d.type === 'Hub',
      }));

    try {
      const result = await apiFetch(devicesLink.url(store.id), 'POST', { devices });
      if (result.success) {
        onOpenChange(false);
        onLinked();
      } else {
        setError(result.error || 'Failed to link devices');
      }
    } catch (err) {
      setError(err instanceof Error ? err.message : 'Failed to link devices');
    } finally {
      setSaving(false);
    }
  };

  return (
    <Dialog open={open} onOpenChange={onOpenChange}>
      <DialogContent className="sm:max-w-lg">
        <DialogHeader>
          <DialogTitle>Link Devices to {store.store_name}</DialogTitle>
          <DialogDescription>
            Select devices from your YoSmart account to link to this store.
          </DialogDescription>
        </DialogHeader>

        <div className="mt-2 space-y-3">
          {/* Search */}
          <div className="relative">
            <Search className="text-muted-foreground absolute top-2.5 left-3 size-4" />
            <Input
              placeholder="Search devices..."
              className="pl-9"
              value={search}
              onChange={(e) => setSearch(e.target.value)}
            />
          </div>

          {/* Device list */}
          <div className="max-h-80 overflow-y-auto rounded-md border">
            {loading ? (
              <div className="flex items-center justify-center py-8">
                <Spinner className="size-6" />
                <span className="text-muted-foreground ml-2 text-sm">Loading devices...</span>
              </div>
            ) : filtered.length === 0 ? (
              <div className="text-muted-foreground py-8 text-center text-sm">
                {availableDevs.length === 0
                  ? 'No devices found in your account.'
                  : 'All devices are already linked or no match found.'}
              </div>
            ) : (
              <>
                {/* Select all header */}
                <div className="bg-muted/50 flex items-center gap-3 border-b px-3 py-2">
                  <Checkbox
                    checked={selected.size === filtered.length && filtered.length > 0}
                    onCheckedChange={toggleAll}
                  />
                  <span className="text-muted-foreground text-xs font-medium">
                    {selected.size > 0
                      ? `${selected.size} of ${filtered.length} selected`
                      : `Select all (${filtered.length})`}
                  </span>
                </div>
                {filtered.map((d) => (
                  <label
                    key={d.id}
                    className="hover:bg-muted/30 flex cursor-pointer items-center gap-3 border-b px-3 py-2.5 last:border-b-0"
                  >
                    <Checkbox
                      checked={selected.has(d.id)}
                      onCheckedChange={() => toggleDevice(d.id)}
                    />
                    <DeviceTypeIcon type={d.type} />
                    <div className="min-w-0 flex-1">
                      <div className="truncate text-sm font-medium">{d.name}</div>
                      <div className="text-muted-foreground truncate text-xs">
                        {d.type} &middot; {d.model} &middot; {d.id}
                      </div>
                    </div>
                  </label>
                ))}
              </>
            )}
          </div>

          {/* Already linked info */}
          {linkedIds.size > 0 && (
            <p className="text-muted-foreground text-xs">
              {linkedIds.size} device{linkedIds.size !== 1 ? 's' : ''} already linked (hidden above).
            </p>
          )}

          {error && (
            <div className="flex items-center gap-2 rounded-md bg-destructive/10 px-3 py-2 text-sm text-destructive">
              <XCircle className="size-4 shrink-0" />
              {error}
            </div>
          )}
        </div>

        <DialogFooter className="mt-2">
          <Button variant="outline" onClick={() => onOpenChange(false)} disabled={saving}>
            Cancel
          </Button>
          <Button onClick={handleLink} disabled={saving || selected.size === 0}>
            {saving && <Spinner className="mr-2 size-4" />}
            Link {selected.size > 0 ? `${selected.size} Device${selected.size !== 1 ? 's' : ''}` : 'Devices'}
          </Button>
        </DialogFooter>
      </DialogContent>
    </Dialog>
  );
}

// ─── Delete Confirm Dialog ────────────────────────────────────────────

function DeleteStoreDialog({
  store,
  open,
  onOpenChange,
  onDeleted,
}: {
  store: Store;
  open: boolean;
  onOpenChange: (open: boolean) => void;
  onDeleted: () => void;
}) {
  const [deleting, setDeleting] = useState(false);

  const handleDelete = async () => {
    setDeleting(true);
    try {
      const result = await apiFetch(storesDestroy.url(store.id), 'DELETE');
      if (result.success) {
        onOpenChange(false);
        onDeleted();
      }
    } catch {
      // ignore
    } finally {
      setDeleting(false);
    }
  };

  return (
    <Dialog open={open} onOpenChange={onOpenChange}>
      <DialogContent className="sm:max-w-md">
        <DialogHeader>
          <DialogTitle>Delete Store</DialogTitle>
          <DialogDescription>
            Are you sure you want to delete <strong>{store.store_name}</strong> ({store.store_number})? This will
            unlink all {store.devices_count} device{store.devices_count !== 1 ? 's' : ''} and cannot be undone.
          </DialogDescription>
        </DialogHeader>
        <DialogFooter className="mt-4">
          <Button variant="outline" onClick={() => onOpenChange(false)} disabled={deleting}>
            Cancel
          </Button>
          <Button variant="destructive" onClick={handleDelete} disabled={deleting}>
            {deleting && <Spinner className="mr-2 size-4" />}
            Delete Store
          </Button>
        </DialogFooter>
      </DialogContent>
    </Dialog>
  );
}

// ─── Store Card (expanded view with linked devices) ───────────────────

function StoreCard({
  store,
  onEdit,
  onDelete,
  onRefresh,
}: {
  store: Store;
  onEdit: () => void;
  onDelete: () => void;
  onRefresh: () => void;
}) {
  const [expanded, setExpanded] = useState(false);
  const [devices, setDevices] = useState<StoreDevice[]>(store.devices || []);
  const [loadingDevices, setLoadingDevices] = useState(false);
  const [linkOpen, setLinkOpen] = useState(false);
  const [unlinking, setUnlinking] = useState<number | null>(null);

  const loadDetails = useCallback(async () => {
    setLoadingDevices(true);
    try {
      const data = await apiFetch(storesShow.url(store.id));
      if (data.success && data.store?.devices) {
        setDevices(data.store.devices);
      }
    } catch {
      // silent
    } finally {
      setLoadingDevices(false);
    }
  }, [store.id]);

  useEffect(() => {
    if (expanded && devices.length === 0) {
      loadDetails();
    }
  }, [expanded, devices.length, loadDetails]);

  const handleUnlink = async (device: StoreDevice) => {
    setUnlinking(device.id);
    try {
      const result = await apiFetch(
        devicesUnlink.url({ store: store.id, device: device.id }),
        'DELETE'
      );
      if (result.success) {
        setDevices((prev) => prev.filter((d) => d.id !== device.id));
        onRefresh();
      }
    } catch {
      // silent
    } finally {
      setUnlinking(null);
    }
  };

  const handleLinked = () => {
    loadDetails();
    onRefresh();
  };

  const hubs = devices.filter((d) => d.is_hub);
  const sensors = devices.filter((d) => !d.is_hub);

  return (
    <>
      <Card className="transition-shadow hover:shadow-md">
        <CardHeader className="pb-3">
          <div className="flex items-start justify-between">
            <div className="flex items-center gap-3">
              <div className="bg-primary/10 text-primary flex size-10 items-center justify-center rounded-lg">
                <StoreIcon className="size-5" />
              </div>
              <div>
                <CardTitle className="text-base">{store.store_name}</CardTitle>
                <CardDescription className="font-mono text-xs">{store.store_number}</CardDescription>
              </div>
            </div>
            <div className="flex items-center gap-1">
              <Badge variant={store.is_active ? 'default' : 'secondary'} className="text-xs">
                {store.is_active ? 'Active' : 'Inactive'}
              </Badge>
            </div>
          </div>
        </CardHeader>

        <CardContent className="space-y-3">
          {/* Device counts */}
          <div className="flex gap-4 text-sm">
            <div className="flex items-center gap-1.5">
              <Server className="text-muted-foreground size-3.5" />
              <span className="text-muted-foreground">
                {store.hubs_count} hub{store.hubs_count !== 1 ? 's' : ''}
              </span>
            </div>
            <div className="flex items-center gap-1.5">
              <Thermometer className="text-muted-foreground size-3.5" />
              <span className="text-muted-foreground">
                {store.sensors_count} sensor{store.sensors_count !== 1 ? 's' : ''}
              </span>
            </div>
          </div>

          <Separator />

          {/* Action buttons */}
          <div className="flex flex-wrap items-center gap-2">
            <Button size="sm" variant="outline" onClick={() => setLinkOpen(true)}>
              <Link2 className="mr-1.5 size-3.5" />
              Link Devices
            </Button>
            <Button size="sm" variant="outline" onClick={onEdit}>
              <Pencil className="mr-1.5 size-3.5" />
              Edit
            </Button>
            <Button size="sm" variant="outline" className="text-destructive hover:text-destructive" onClick={onDelete}>
              <Trash2 className="mr-1.5 size-3.5" />
              Delete
            </Button>

            <div className="flex-1" />

            <Button
              size="sm"
              variant="ghost"
              onClick={() => setExpanded(!expanded)}
              className="text-muted-foreground"
            >
              {expanded ? (
                <>
                  <ChevronUp className="mr-1 size-3.5" /> Hide Devices
                </>
              ) : (
                <>
                  <ChevronDown className="mr-1 size-3.5" /> Show Devices ({store.devices_count})
                </>
              )}
            </Button>
          </div>

          {/* Expanded device list */}
          {expanded && (
            <div className="mt-2 space-y-2">
              {loadingDevices ? (
                <div className="space-y-2">
                  {[1, 2, 3].map((i) => (
                    <Skeleton key={i} className="h-12 w-full rounded-md" />
                  ))}
                </div>
              ) : devices.length === 0 ? (
                <div className="text-muted-foreground rounded-md border border-dashed py-6 text-center text-sm">
                  No devices linked yet. Click "Link Devices" to get started.
                </div>
              ) : (
                <>
                  {/* Hubs */}
                  {hubs.length > 0 && (
                    <div className="space-y-1">
                      <p className="text-muted-foreground text-xs font-medium uppercase tracking-wide">Hub</p>
                      {hubs.map((d) => (
                        <DeviceRow key={d.id} device={d} unlinking={unlinking === d.id} onUnlink={() => handleUnlink(d)} />
                      ))}
                    </div>
                  )}
                  {/* Sensors */}
                  {sensors.length > 0 && (
                    <div className="space-y-1">
                      <p className="text-muted-foreground text-xs font-medium uppercase tracking-wide">Sensors</p>
                      {sensors.map((d) => (
                        <DeviceRow key={d.id} device={d} unlinking={unlinking === d.id} onUnlink={() => handleUnlink(d)} />
                      ))}
                    </div>
                  )}
                </>
              )}
            </div>
          )}
        </CardContent>
      </Card>

      <LinkDevicesDialog
        store={{ ...store, devices }}
        open={linkOpen}
        onOpenChange={setLinkOpen}
        onLinked={handleLinked}
      />
    </>
  );
}

// ─── Device Row ───────────────────────────────────────────────────────

function DeviceRow({
  device,
  unlinking,
  onUnlink,
}: {
  device: StoreDevice;
  unlinking: boolean;
  onUnlink: () => void;
}) {
  return (
    <div className="bg-muted/40 flex items-center gap-3 rounded-md px-3 py-2">
      <DeviceTypeIcon type={device.device_type} />
      <div className="min-w-0 flex-1">
        <div className="truncate text-sm font-medium">{device.device_name}</div>
        <div className="text-muted-foreground truncate text-xs">
          {device.device_type}
          {device.model_name && <> &middot; {device.model_name}</>}
          <span className="ml-1 font-mono opacity-60">{device.device_id}</span>
        </div>
      </div>
      {device.is_hub && (
        <Badge variant="outline" className="text-xs">
          Hub
        </Badge>
      )}
      <Button
        variant="ghost"
        size="sm"
        className="text-muted-foreground hover:text-destructive size-8 p-0"
        onClick={onUnlink}
        disabled={unlinking}
        title="Unlink device"
      >
        {unlinking ? <Spinner className="size-3.5" /> : <Unlink className="size-3.5" />}
      </Button>
    </div>
  );
}

// ─── Main Component ───────────────────────────────────────────────────

export function StoreManagement() {
  const [stores, setStores] = useState<Store[]>([]);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState<string | null>(null);

  // Dialog states
  const [formOpen, setFormOpen] = useState(false);
  const [editingStore, setEditingStore] = useState<Store | null>(null);
  const [deleteStore, setDeleteStore] = useState<Store | null>(null);

  const fetchStores = useCallback(async () => {
    try {
      setLoading(true);
      setError(null);
      const data = await apiFetch(storesIndex.url());
      if (data.success && data.stores) {
        setStores(data.stores);
      } else {
        setError(data.error || 'Failed to load stores');
      }
    } catch (err) {
      setError(err instanceof Error ? err.message : 'Failed to load stores');
    } finally {
      setLoading(false);
    }
  }, []);

  useEffect(() => {
    fetchStores();
  }, [fetchStores]);

  const handleCreateClick = () => {
    setEditingStore(null);
    setFormOpen(true);
  };

  const handleEditClick = (store: Store) => {
    setEditingStore(store);
    setFormOpen(true);
  };

  return (
    <div className="space-y-6">
      {/* Header */}
      <div className="flex items-center justify-between">
        <div>
          <h2 className="text-2xl font-bold tracking-tight">Stores</h2>
          <p className="text-muted-foreground text-sm">
            Manage your stores and link YoSmart devices to each location.
          </p>
        </div>
        <div className="flex items-center gap-2">
          <Button variant="outline" size="sm" onClick={fetchStores} disabled={loading}>
            <RefreshCw className={`mr-1.5 size-3.5 ${loading ? 'animate-spin' : ''}`} />
            Refresh
          </Button>
          <Button size="sm" onClick={handleCreateClick}>
            <Plus className="mr-1.5 size-3.5" />
            Add Store
          </Button>
        </div>
      </div>

      {/* Error */}
      {error && (
        <div className="flex items-center gap-2 rounded-md bg-destructive/10 px-4 py-3 text-sm text-destructive">
          <XCircle className="size-4 shrink-0" />
          {error}
          <Button variant="ghost" size="sm" className="ml-auto" onClick={fetchStores}>
            Retry
          </Button>
        </div>
      )}

      {/* Loading skeleton */}
      {loading && stores.length === 0 && (
        <div className="grid gap-4 md:grid-cols-2 xl:grid-cols-3">
          {[1, 2, 3].map((i) => (
            <Card key={i}>
              <CardHeader className="pb-3">
                <div className="flex items-center gap-3">
                  <Skeleton className="size-10 rounded-lg" />
                  <div className="space-y-2">
                    <Skeleton className="h-4 w-32" />
                    <Skeleton className="h-3 w-20" />
                  </div>
                </div>
              </CardHeader>
              <CardContent>
                <Skeleton className="h-4 w-40" />
                <Skeleton className="mt-3 h-8 w-full" />
              </CardContent>
            </Card>
          ))}
        </div>
      )}

      {/* Empty state */}
      {!loading && stores.length === 0 && !error && (
        <Card className="border-dashed">
          <CardContent className="flex flex-col items-center justify-center py-12">
            <div className="bg-muted mb-4 flex size-16 items-center justify-center rounded-full">
              <StoreIcon className="text-muted-foreground size-8" />
            </div>
            <h3 className="text-lg font-semibold">No stores yet</h3>
            <p className="text-muted-foreground mt-1 text-sm">
              Create your first store to start linking YoSmart devices.
            </p>
            <Button className="mt-4" onClick={handleCreateClick}>
              <Plus className="mr-1.5 size-4" />
              Add Your First Store
            </Button>
          </CardContent>
        </Card>
      )}

      {/* Store grid */}
      {stores.length > 0 && (
        <div className="grid gap-4 md:grid-cols-2 xl:grid-cols-3">
          {stores.map((store) => (
            <StoreCard
              key={store.id}
              store={store}
              onEdit={() => handleEditClick(store)}
              onDelete={() => setDeleteStore(store)}
              onRefresh={fetchStores}
            />
          ))}
        </div>
      )}

      {/* Create / Edit dialog */}
      <StoreFormDialog
        store={editingStore}
        open={formOpen}
        onOpenChange={setFormOpen}
        onSaved={fetchStores}
      />

      {/* Delete confirm dialog */}
      {deleteStore && (
        <DeleteStoreDialog
          store={deleteStore}
          open={!!deleteStore}
          onOpenChange={(open) => !open && setDeleteStore(null)}
          onDeleted={fetchStores}
        />
      )}
    </div>
  );
}
