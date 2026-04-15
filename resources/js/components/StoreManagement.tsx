import {
  Plus,
  Pencil,
  Trash2,
  Store as StoreIcon,
  Server,
  Thermometer,
  Activity,
  RefreshCw,
  XCircle,
  ChevronDown,
  ChevronUp,
  Eye,
  EyeOff,
  Key,
  ArrowRightLeft,
} from 'lucide-react';
import { useState, useEffect, useCallback } from 'react';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle, CardDescription } from '@/components/ui/card';
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
import {
  Select,
  SelectContent,
  SelectItem,
  SelectTrigger,
  SelectValue,
} from '@/components/ui/select';
import { Separator } from '@/components/ui/separator';
import { Skeleton } from '@/components/ui/skeleton';
import { Spinner } from '@/components/ui/spinner';
import {
  index as storesIndex,
  store as storesCreate,
  update as storesUpdate,
  destroy as storesDestroy,
} from '@/routes/stores';

// ─── Types ────────────────────────────────────────────────────────────

interface StoreDevice {
  id: number;
  store_id: number | null;
  credential_id: number | null;
  device_id: string;
  device_token: string;
  device_type: string;
  device_name: string;
  model_name: string | null;
  parsed_store_number: string | null;
  is_hub: boolean;
  store?: { id: number; store_number: string; store_name: string } | null;
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

interface Credential {
  id: number;
  uaid: string;
  is_active: boolean;
  last_synced_at: string | null;
  devices_count: number;
  hubs_count: number;
  sensors_count: number;
  devices?: StoreDevice[];
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
      const payload: Record<string, unknown> = {
        store_number: storeNumber,
        store_name: storeName,
      };
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
      <DialogContent className="sm:max-w-lg">
        <form onSubmit={handleSubmit}>
          <DialogHeader>
            <DialogTitle>{isEdit ? 'Edit Store' : 'Add Store'}</DialogTitle>
            <DialogDescription>
              {isEdit ? 'Update store details.' : 'Create a new store. Devices are auto-linked from credentials by store number.'}
            </DialogDescription>
          </DialogHeader>

          <div className="mt-4 space-y-4">
            <div className="space-y-2">
              <Label htmlFor="store_number">Store Number</Label>
              <Input
                id="store_number"
                value={storeNumber}
                onChange={(e) => {
                  const v = e.target.value.replace(/[^0-9-]/g, '');
                  if (v.length <= 11) setStoreNumber(v);
                }}
                placeholder="e.g. 03795-00038"
                pattern="\d{5}-\d{5}"
                title="Format: XXXXX-XXXXX (e.g. 03795-00038)"
                maxLength={11}
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

// ─── Credential Form Dialog ───────────────────────────────────────────

function CredentialFormDialog({
  credential,
  open,
  onOpenChange,
  onSaved,
}: {
  credential?: Credential | null;
  open: boolean;
  onOpenChange: (open: boolean) => void;
  onSaved: () => void;
}) {
  const [uaid, setUaid] = useState('');
  const [secret, setSecret] = useState('');
  const [showSecret, setShowSecret] = useState(false);
  const [saving, setSaving] = useState(false);
  const [error, setError] = useState<string | null>(null);

  const isEdit = !!credential;

  useEffect(() => {
    if (open) {
      setUaid(credential?.uaid || '');
      setSecret('');
      setShowSecret(false);
      setError(null);
    }
  }, [open, credential]);

  const handleSubmit = async (e: React.FormEvent) => {
    e.preventDefault();
    setSaving(true);
    setError(null);

    try {
      const payload: Record<string, unknown> = { uaid };
      if (secret !== '') payload.secret = secret;
      let result;

      if (isEdit && credential) {
        result = await apiFetch(`/api/credentials/${credential.id}`, 'PUT', payload);
      } else {
        payload.secret = secret;
        result = await apiFetch('/api/credentials', 'POST', payload);
      }

      if (result.success) {
        onOpenChange(false);
        onSaved();
      } else {
        if (result.errors) {
          const messages = Object.values(result.errors).flat().join(', ');
          setError(messages);
        } else {
          setError(result.error || result.message || 'Failed to save credential');
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
      <DialogContent className="sm:max-w-lg">
        <form onSubmit={handleSubmit}>
          <DialogHeader>
            <DialogTitle>{isEdit ? 'Edit Credential' : 'Add Credential'}</DialogTitle>
            <DialogDescription>
              {isEdit ? 'Update credential details.' : 'Add a YoSmart UAID / secret pair. Sync to discover devices.'}
            </DialogDescription>
          </DialogHeader>

          <div className="mt-4 space-y-4">
            <div className="space-y-2">
              <Label htmlFor="uaid">UAID</Label>
              <Input
                id="uaid"
                value={uaid}
                onChange={(e) => setUaid(e.target.value)}
                placeholder="ua_xxxxxxxxxxxxxx"
                required
              />
            </div>
            <div className="space-y-2">
              <Label htmlFor="secret">Secret Key</Label>
              <div className="relative">
                <Input
                  id="secret"
                  type={showSecret ? 'text' : 'password'}
                  value={secret}
                  onChange={(e) => setSecret(e.target.value)}
                  placeholder={isEdit ? '••••••• (leave blank to keep)' : 'Enter secret key'}
                  className="pr-9"
                  required={!isEdit}
                />
                <Button
                  type="button"
                  variant="ghost"
                  size="sm"
                  className="absolute right-0 top-0 size-9 p-0"
                  onClick={() => setShowSecret(!showSecret)}
                  tabIndex={-1}
                >
                  {showSecret ? <EyeOff className="size-4" /> : <Eye className="size-4" />}
                </Button>
              </div>
              {isEdit && (
                <p className="text-muted-foreground text-xs">Leave blank to keep the existing secret.</p>
              )}
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
            Are you sure you want to delete <strong>{store.store_name}</strong> ({store.store_number})? Devices will be
            unlinked but kept under their credential. This cannot be undone.
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

function DeleteCredentialDialog({
  credential,
  open,
  onOpenChange,
  onDeleted,
}: {
  credential: Credential;
  open: boolean;
  onOpenChange: (open: boolean) => void;
  onDeleted: () => void;
}) {
  const [deleting, setDeleting] = useState(false);

  const handleDelete = async () => {
    setDeleting(true);
    try {
      const result = await apiFetch(`/api/credentials/${credential.id}`, 'DELETE');
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
          <DialogTitle>Delete Credential</DialogTitle>
          <DialogDescription>
            Are you sure you want to delete credential <strong>{credential.uaid}</strong>? This will remove all{' '}
            {credential.devices_count} associated device{credential.devices_count !== 1 ? 's' : ''}. This cannot be
            undone.
          </DialogDescription>
        </DialogHeader>
        <DialogFooter className="mt-4">
          <Button variant="outline" onClick={() => onOpenChange(false)} disabled={deleting}>
            Cancel
          </Button>
          <Button variant="destructive" onClick={handleDelete} disabled={deleting}>
            {deleting && <Spinner className="mr-2 size-4" />}
            Delete
          </Button>
        </DialogFooter>
      </DialogContent>
    </Dialog>
  );
}

// ─── Assign Device Dialog ─────────────────────────────────────────────

function AssignDeviceDialog({
  device,
  stores,
  open,
  onOpenChange,
  onAssigned,
}: {
  device: StoreDevice;
  stores: Store[];
  open: boolean;
  onOpenChange: (open: boolean) => void;
  onAssigned: () => void;
}) {
  const [selectedStoreId, setSelectedStoreId] = useState<string>('');
  const [saving, setSaving] = useState(false);
  const [error, setError] = useState<string | null>(null);

  useEffect(() => {
    if (open) {
      setSelectedStoreId('');
      setError(null);
    }
  }, [open]);

  const handleAssign = async () => {
    if (!selectedStoreId) return;
    setSaving(true);
    setError(null);

    try {
      const result = await apiFetch(
        `/api/credentials/${device.credential_id}/devices/${device.id}/assign`,
        'PUT',
        { store_id: Number(selectedStoreId) }
      );

      if (result.success) {
        onOpenChange(false);
        onAssigned();
      } else {
        setError(result.error || 'Failed to assign device');
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
        <DialogHeader>
          <DialogTitle>Assign Device to Store</DialogTitle>
          <DialogDescription>
            Manually assign <strong>{device.device_name}</strong> to a store.
          </DialogDescription>
        </DialogHeader>

        <div className="mt-4 space-y-4">
          <div className="space-y-2">
            <Label>Store</Label>
            <Select value={selectedStoreId} onValueChange={setSelectedStoreId}>
              <SelectTrigger>
                <SelectValue placeholder="Select a store..." />
              </SelectTrigger>
              <SelectContent>
                {stores.map((s) => (
                  <SelectItem key={s.id} value={String(s.id)}>
                    {s.store_name} ({s.store_number})
                  </SelectItem>
                ))}
              </SelectContent>
            </Select>
          </div>

          {error && (
            <div className="flex items-center gap-2 rounded-md bg-destructive/10 px-3 py-2 text-sm text-destructive">
              <XCircle className="size-4 shrink-0" />
              {error}
            </div>
          )}
        </div>

        <DialogFooter className="mt-4">
          <Button variant="outline" onClick={() => onOpenChange(false)} disabled={saving}>
            Cancel
          </Button>
          <Button onClick={handleAssign} disabled={saving || !selectedStoreId}>
            {saving && <Spinner className="mr-2 size-4" />}
            Assign
          </Button>
        </DialogFooter>
      </DialogContent>
    </Dialog>
  );
}

// ─── Store Card ───────────────────────────────────────────────────────

function StoreCard({
  store,
  onEdit,
  onDelete,
}: {
  store: Store;
  onEdit: () => void;
  onDelete: () => void;
}) {
  return (
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
          <Badge variant={store.is_active ? 'default' : 'secondary'} className="text-xs">
            {store.is_active ? 'Active' : 'Inactive'}
          </Badge>
        </div>
      </CardHeader>

      <CardContent className="space-y-3">
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

        <div className="flex flex-wrap items-center gap-2">
          <Button size="sm" variant="outline" onClick={onEdit}>
            <Pencil className="mr-1.5 size-3.5" />
            Edit
          </Button>
          <Button size="sm" variant="outline" className="text-destructive hover:text-destructive" onClick={onDelete}>
            <Trash2 className="mr-1.5 size-3.5" />
            Delete
          </Button>
        </div>
      </CardContent>
    </Card>
  );
}

// ─── Credential Card ──────────────────────────────────────────────────

function CredentialCard({
  credential,
  stores,
  onEdit,
  onDelete,
  onRefresh,
}: {
  credential: Credential;
  stores: Store[];
  onEdit: () => void;
  onDelete: () => void;
  onRefresh: () => void;
}) {
  const [expanded, setExpanded] = useState(false);
  const [devices, setDevices] = useState<StoreDevice[]>([]);
  const [loadingDevices, setLoadingDevices] = useState(false);
  const [syncing, setSyncing] = useState(false);
  const [syncResult, setSyncResult] = useState<string | null>(null);
  const [assignDevice, setAssignDevice] = useState<StoreDevice | null>(null);

  const loadDetails = useCallback(async () => {
    setLoadingDevices(true);
    try {
      const data = await apiFetch(`/api/credentials/${credential.id}`);
      if (data.success && data.credential?.devices) {
        setDevices(data.credential.devices);
      }
    } catch {
      // silent
    } finally {
      setLoadingDevices(false);
    }
  }, [credential.id]);

  useEffect(() => {
    if (expanded && devices.length === 0) {
      loadDetails();
    }
  }, [expanded, devices.length, loadDetails]);

  const handleSync = async () => {
    setSyncing(true);
    setSyncResult(null);
    try {
      const result = await apiFetch(`/api/credentials/${credential.id}/sync`, 'POST');
      if (result.success) {
        setSyncResult(
          `Synced ${result.synced} device(s), ${result.linked} auto-linked, ${result.unmatched} unmatched, ${result.removed} removed`
        );
        loadDetails();
        onRefresh();
      } else {
        setSyncResult(result.error || 'Sync failed');
      }
    } catch (err) {
      setSyncResult(err instanceof Error ? err.message : 'Sync failed');
    } finally {
      setSyncing(false);
    }
  };

  const unmatched = devices.filter((d) => !d.store_id && !d.is_hub);
  const linked = devices.filter((d) => d.store_id && !d.is_hub);
  const hubs = devices.filter((d) => d.is_hub);

  return (
    <>
      <Card className="transition-shadow hover:shadow-md">
        <CardHeader className="pb-3">
          <div className="flex items-start justify-between">
            <div className="flex items-center gap-3">
              <div className="flex size-10 items-center justify-center rounded-lg bg-emerald-100 text-emerald-700 dark:bg-emerald-950 dark:text-emerald-400">
                <Key className="size-5" />
              </div>
              <div>
                <CardTitle className="text-base font-mono">{credential.uaid}</CardTitle>
                <CardDescription className="text-xs">
                  {credential.last_synced_at
                    ? `Last synced ${new Date(credential.last_synced_at).toLocaleString()}`
                    : 'Never synced'}
                </CardDescription>
              </div>
            </div>
            <Badge variant={credential.is_active ? 'default' : 'secondary'} className="text-xs">
              {credential.is_active ? 'Active' : 'Inactive'}
            </Badge>
          </div>
        </CardHeader>

        <CardContent className="space-y-3">
          <div className="flex gap-4 text-sm">
            <div className="flex items-center gap-1.5">
              <Server className="text-muted-foreground size-3.5" />
              <span className="text-muted-foreground">
                {credential.hubs_count} hub{credential.hubs_count !== 1 ? 's' : ''}
              </span>
            </div>
            <div className="flex items-center gap-1.5">
              <Thermometer className="text-muted-foreground size-3.5" />
              <span className="text-muted-foreground">
                {credential.sensors_count} sensor{credential.sensors_count !== 1 ? 's' : ''}
              </span>
            </div>
          </div>

          {syncResult && (
            <div className="rounded-md bg-muted px-3 py-2 text-xs text-muted-foreground">{syncResult}</div>
          )}

          <Separator />

          <div className="flex flex-wrap items-center gap-2">
            <Button size="sm" variant="outline" onClick={handleSync} disabled={syncing}>
              {syncing ? <Spinner className="mr-1.5 size-3.5" /> : <RefreshCw className="mr-1.5 size-3.5" />}
              Sync Devices
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
                  <ChevronUp className="mr-1 size-3.5" /> Hide
                </>
              ) : (
                <>
                  <ChevronDown className="mr-1 size-3.5" /> Devices ({credential.devices_count})
                </>
              )}
            </Button>
          </div>

          {expanded && (
            <div className="mt-2 space-y-3">
              {loadingDevices ? (
                <div className="space-y-2">
                  {[1, 2, 3].map((i) => (
                    <Skeleton key={i} className="h-12 w-full rounded-md" />
                  ))}
                </div>
              ) : devices.length === 0 ? (
                <div className="text-muted-foreground rounded-md border border-dashed py-6 text-center text-sm">
                  No devices found. Click "Sync Devices" to fetch from YoSmart.
                </div>
              ) : (
                <>
                  {hubs.length > 0 && (
                    <div className="space-y-1">
                      <p className="text-muted-foreground text-xs font-medium uppercase tracking-wide">Hubs</p>
                      {hubs.map((d) => (
                        <DeviceRow key={d.id} device={d} />
                      ))}
                    </div>
                  )}
                  {linked.length > 0 && (
                    <div className="space-y-1">
                      <p className="text-muted-foreground text-xs font-medium uppercase tracking-wide">
                        Linked Sensors ({linked.length})
                      </p>
                      {linked.map((d) => (
                        <DeviceRow key={d.id} device={d} />
                      ))}
                    </div>
                  )}
                  {unmatched.length > 0 && (
                    <div className="space-y-1">
                      <p className="text-xs font-medium uppercase tracking-wide text-amber-600 dark:text-amber-400">
                        Unmatched ({unmatched.length})
                      </p>
                      {unmatched.map((d) => (
                        <div key={d.id} className="bg-amber-50/50 dark:bg-amber-950/20 flex items-center gap-3 rounded-md border border-amber-200/50 dark:border-amber-800/30 px-3 py-2">
                          <DeviceTypeIcon type={d.device_type} />
                          <div className="min-w-0 flex-1">
                            <div className="truncate text-sm font-medium">{d.device_name}</div>
                            <div className="text-muted-foreground truncate text-xs">
                              {d.device_type}
                              {d.model_name && <> &middot; {d.model_name}</>}
                            </div>
                          </div>
                          <Button
                            size="sm"
                            variant="outline"
                            className="shrink-0"
                            onClick={() => setAssignDevice(d)}
                          >
                            <ArrowRightLeft className="mr-1 size-3.5" />
                            Assign
                          </Button>
                        </div>
                      ))}
                    </div>
                  )}
                </>
              )}
            </div>
          )}
        </CardContent>
      </Card>

      {assignDevice && (
        <AssignDeviceDialog
          device={assignDevice}
          stores={stores}
          open={!!assignDevice}
          onOpenChange={(open) => !open && setAssignDevice(null)}
          onAssigned={() => {
            loadDetails();
            onRefresh();
          }}
        />
      )}
    </>
  );
}

// ─── Device Row ───────────────────────────────────────────────────────

function DeviceRow({ device }: { device: StoreDevice }) {
  return (
    <div className="bg-muted/40 flex items-center gap-3 rounded-md px-3 py-2">
      <DeviceTypeIcon type={device.device_type} />
      <div className="min-w-0 flex-1">
        <div className="truncate text-sm font-medium">{device.device_name}</div>
        <div className="text-muted-foreground truncate text-xs">
          {device.device_type}
          {device.model_name && <> &middot; {device.model_name}</>}
          {device.store && (
            <span className="ml-1 text-emerald-600 dark:text-emerald-400">
              &rarr; {device.store.store_name}
            </span>
          )}
        </div>
      </div>
      {device.is_hub && (
        <Badge variant="outline" className="text-xs">
          Hub
        </Badge>
      )}
    </div>
  );
}

// ─── Main Component ───────────────────────────────────────────────────

export function StoreManagement() {
  const [stores, setStores] = useState<Store[]>([]);
  const [credentials, setCredentials] = useState<Credential[]>([]);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState<string | null>(null);

  // Store dialog states
  const [storeFormOpen, setStoreFormOpen] = useState(false);
  const [editingStore, setEditingStore] = useState<Store | null>(null);
  const [deleteStore, setDeleteStore] = useState<Store | null>(null);

  // Credential dialog states
  const [credFormOpen, setCredFormOpen] = useState(false);
  const [editingCred, setEditingCred] = useState<Credential | null>(null);
  const [deleteCred, setDeleteCred] = useState<Credential | null>(null);

  const fetchAll = useCallback(async () => {
    try {
      setLoading(true);
      setError(null);
      const [storesData, credsData] = await Promise.all([
        apiFetch(storesIndex.url()),
        apiFetch('/api/credentials'),
      ]);
      if (storesData.success && storesData.stores) setStores(storesData.stores);
      if (credsData.success && credsData.credentials) setCredentials(credsData.credentials);
    } catch (err) {
      setError(err instanceof Error ? err.message : 'Failed to load data');
    } finally {
      setLoading(false);
    }
  }, []);

  useEffect(() => {
    fetchAll();
  }, [fetchAll]);

  return (
    <div className="space-y-8">
      {/* ── Credentials Section ── */}
      <div className="space-y-4">
        <div className="flex items-center justify-between">
          <div>
            <h2 className="text-2xl font-bold tracking-tight">YoSmart Credentials</h2>
            <p className="text-muted-foreground text-sm">
              Manage UAID / secret pairs. Sync to discover and auto-link devices to stores.
            </p>
          </div>
          <div className="flex items-center gap-2">
            <Button variant="outline" size="sm" onClick={fetchAll} disabled={loading}>
              <RefreshCw className={`mr-1.5 size-3.5 ${loading ? 'animate-spin' : ''}`} />
              Refresh
            </Button>
            <Button size="sm" onClick={() => { setEditingCred(null); setCredFormOpen(true); }}>
              <Plus className="mr-1.5 size-3.5" />
              Add Credential
            </Button>
          </div>
        </div>

        {error && (
          <div className="flex items-center gap-2 rounded-md bg-destructive/10 px-4 py-3 text-sm text-destructive">
            <XCircle className="size-4 shrink-0" />
            {error}
            <Button variant="ghost" size="sm" className="ml-auto" onClick={fetchAll}>
              Retry
            </Button>
          </div>
        )}

        {loading && credentials.length === 0 && (
          <div className="grid gap-4 md:grid-cols-2 xl:grid-cols-3">
            {[1, 2].map((i) => (
              <Card key={i}>
                <CardHeader className="pb-3">
                  <div className="flex items-center gap-3">
                    <Skeleton className="size-10 rounded-lg" />
                    <div className="space-y-2">
                      <Skeleton className="h-4 w-40" />
                      <Skeleton className="h-3 w-24" />
                    </div>
                  </div>
                </CardHeader>
                <CardContent>
                  <Skeleton className="h-4 w-32" />
                </CardContent>
              </Card>
            ))}
          </div>
        )}

        {!loading && credentials.length === 0 && !error && (
          <Card className="border-dashed">
            <CardContent className="flex flex-col items-center justify-center py-12">
              <div className="bg-muted mb-4 flex size-16 items-center justify-center rounded-full">
                <Key className="text-muted-foreground size-8" />
              </div>
              <h3 className="text-lg font-semibold">No credentials yet</h3>
              <p className="text-muted-foreground mt-1 text-sm">
                Add your first YoSmart UAID / secret to start discovering devices.
              </p>
              <Button className="mt-4" onClick={() => { setEditingCred(null); setCredFormOpen(true); }}>
                <Plus className="mr-1.5 size-4" />
                Add Your First Credential
              </Button>
            </CardContent>
          </Card>
        )}

        {credentials.length > 0 && (
          <div className="grid gap-4 md:grid-cols-2 xl:grid-cols-3">
            {credentials.map((cred) => (
              <CredentialCard
                key={cred.id}
                credential={cred}
                stores={stores}
                onEdit={() => { setEditingCred(cred); setCredFormOpen(true); }}
                onDelete={() => setDeleteCred(cred)}
                onRefresh={fetchAll}
              />
            ))}
          </div>
        )}
      </div>

      <Separator />

      {/* ── Stores Section ── */}
      <div className="space-y-4">
        <div className="flex items-center justify-between">
          <div>
            <h2 className="text-2xl font-bold tracking-tight">Stores</h2>
            <p className="text-muted-foreground text-sm">
              Devices are automatically linked to stores by parsing the store number from device names.
            </p>
          </div>
          <Button size="sm" onClick={() => { setEditingStore(null); setStoreFormOpen(true); }}>
            <Plus className="mr-1.5 size-3.5" />
            Add Store
          </Button>
        </div>

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
                </CardContent>
              </Card>
            ))}
          </div>
        )}

        {!loading && stores.length === 0 && !error && (
          <Card className="border-dashed">
            <CardContent className="flex flex-col items-center justify-center py-12">
              <div className="bg-muted mb-4 flex size-16 items-center justify-center rounded-full">
                <StoreIcon className="text-muted-foreground size-8" />
              </div>
              <h3 className="text-lg font-semibold">No stores yet</h3>
              <p className="text-muted-foreground mt-1 text-sm">
                Create stores so devices can be auto-linked by their store number.
              </p>
              <Button className="mt-4" onClick={() => { setEditingStore(null); setStoreFormOpen(true); }}>
                <Plus className="mr-1.5 size-4" />
                Add Your First Store
              </Button>
            </CardContent>
          </Card>
        )}

        {stores.length > 0 && (
          <div className="grid gap-4 md:grid-cols-2 xl:grid-cols-3">
            {stores.map((store) => (
              <StoreCard
                key={store.id}
                store={store}
                onEdit={() => { setEditingStore(store); setStoreFormOpen(true); }}
                onDelete={() => setDeleteStore(store)}
              />
            ))}
          </div>
        )}
      </div>

      {/* ── Dialogs ── */}
      <StoreFormDialog
        store={editingStore}
        open={storeFormOpen}
        onOpenChange={setStoreFormOpen}
        onSaved={fetchAll}
      />

      <CredentialFormDialog
        credential={editingCred}
        open={credFormOpen}
        onOpenChange={setCredFormOpen}
        onSaved={fetchAll}
      />

      {deleteStore && (
        <DeleteStoreDialog
          store={deleteStore}
          open={!!deleteStore}
          onOpenChange={(open) => !open && setDeleteStore(null)}
          onDeleted={fetchAll}
        />
      )}

      {deleteCred && (
        <DeleteCredentialDialog
          credential={deleteCred}
          open={!!deleteCred}
          onOpenChange={(open) => !open && setDeleteCred(null)}
          onDeleted={fetchAll}
        />
      )}
    </div>
  );
}
