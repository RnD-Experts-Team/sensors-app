# Sensors App — API Integration Guide

Complete reference for integrating with the Sensors App API. This guide covers authentication, all available endpoints, request/response formats, and code examples.

---

## Table of Contents

1. [Architecture Overview](#1-architecture-overview)
2. [Authentication](#2-authentication)
3. [Base URLs](#3-base-urls)
4. [Admin API (Auth Required)](#4-admin-api-auth-required)
   - [Credential Management](#41-credential-management)
   - [YoSmart Device API](#42-yosmart-device-api)
   - [Store Management](#43-store-management)
   - [Reports (Admin)](#44-reports-admin)
   - [Snapshot Schedules](#45-snapshot-schedules)
   - [App Settings](#46-app-settings)
5. [Public API (Token Required)](#5-public-api-token-required)
   - [Live Sensor Data](#51-live-sensor-data)
   - [Reports](#52-reports)
6. [Data Models](#6-data-models)
7. [YoSmart API Integration Details](#7-yosmart-api-integration-details)
8. [Error Handling](#8-error-handling)
9. [Code Examples](#9-code-examples)
10. [Deployment Notes](#10-deployment-notes)

---

## 1. Architecture Overview

```
┌─────────────┐       ┌──────────────────┐       ┌───────────────────┐
│  Frontend    │──────▶│  Laravel App      │──────▶│  YoSmart Cloud    │
│  (React +   │  HTTP │  (API Gateway)    │  HTTP │  api.yosmart.com  │
│   Inertia)  │◀──────│                   │◀──────│                   │
└─────────────┘       └──────────────────┘       └───────────────────┘
                              │
                              ▼
                      ┌──────────────────┐
                      │  Database         │
                      │  (MySQL / Postgres)│
                      └──────────────────┘
```

**Key Concepts:**

- **Credentials** — A YoSmart UAID + Secret pair. Each credential can have many devices.
- **Stores** — Physical locations identified by a store number (format: `XXXXX-XXXXX`, e.g., `03795-00038`).
- **Devices** — YoSmart IoT sensors/hubs discovered via `Home.getDeviceList`. Devices are linked to stores by matching the store number in their name.
- **Reports** — Historical snapshots of sensor readings (temperature, humidity, battery, alarms).

**Data Flow:**

1. Admin adds YoSmart credentials (UAID + Secret)
2. Admin triggers a device sync → devices are fetched from YoSmart and stored locally
3. Devices are auto-linked to stores by parsing the store number from the device name
4. The public API fetches live sensor state from YoSmart in real-time
5. Snapshots can be captured on-demand or on a schedule, persisting data to `sensor_reports`

---

## 2. Authentication

### Admin Routes (Session-Based)

Admin API routes use Laravel session authentication via Fortify. You must be logged in through the web interface.

**Login:**

```
POST /login
Content-Type: application/json

{
  "email": "admin@example.com",
  "password": "your-password"
}
```

Once authenticated, include the session cookie (and CSRF token for non-GET requests) in subsequent requests:

```
X-CSRF-TOKEN: {csrf_token}
Cookie: laravel_session={session_id}
```

### Public API Routes (Bearer Token)

Public API routes use Bearer token authentication verified by an external auth server. Include the token in the `Authorization` header:

```
Authorization: Bearer {your_api_token}
```

The middleware (`AuthTokenStoreScopeMiddleware`) validates the token against the configured auth server and enforces store-level access scoping.

---

## 3. Base URLs

| Environment | Base URL |
|---|---|
| Local Development | `http://localhost:8000` |
| Production | `https://your-domain.com` |

### Route Prefixes

| Scope | Prefix | Auth |
|---|---|---|
| Admin — Credentials | `/api/credentials` | Session |
| Admin — YoSmart API | `/api/credentials/{id}/yosmart` | Session |
| Admin — Stores | `/api/stores` | Session |
| Admin — Reports | `/api/reports` | Session |
| Admin — Schedules | `/api/reports/schedules` | Session |
| Admin — Settings | `/api/settings` | Session |
| Public — Sensors | `/api/stores/{store_number}/sensors` | Bearer Token |
| Public — Reports | `/api/stores/{store_number}/reports` | Bearer Token |

---

## 4. Admin API (Auth Required)

All admin routes require session authentication (`auth` + `verified` middleware).

### 4.1 Credential Management

Credentials are YoSmart API access pairs (UAID + Secret). The secret is stored encrypted at rest.

#### List All Credentials

```
GET /api/credentials
```

**Response:**

```json
{
  "success": true,
  "credentials": [
    {
      "id": 1,
      "uaid": "ua_E85917DC39024727B9655984666C5FF0",
      "is_active": true,
      "last_synced_at": "2026-04-07T18:06:14.000000Z",
      "devices_count": 36,
      "hubs_count": 2,
      "sensors_count": 34,
      "created_at": "2026-04-07T15:30:00.000000Z",
      "updated_at": "2026-04-07T18:06:14.000000Z"
    }
  ]
}
```

#### Create Credential

```
POST /api/credentials
Content-Type: application/json

{
  "uaid": "ua_YOUR_UAID_HERE",
  "secret": "sec_v1_YOUR_SECRET_HERE"
}
```

**Response (201):**

```json
{
  "success": true,
  "credential": {
    "id": 2,
    "uaid": "ua_YOUR_UAID_HERE",
    "is_active": true,
    "last_synced_at": null
  }
}
```

**Validation:**

| Field | Rules |
|---|---|
| `uaid` | Required, string, max 255, unique across all credentials |
| `secret` | Required, string, max 500 |

#### Show Credential (with Devices)

```
GET /api/credentials/{id}
```

**Response:**

```json
{
  "success": true,
  "credential": {
    "id": 1,
    "uaid": "ua_E85917DC39024727B9655984666C5FF0",
    "is_active": true,
    "last_synced_at": "2026-04-07T18:06:14.000000Z",
    "devices_count": 36,
    "hubs_count": 2,
    "sensors_count": 34,
    "devices": [
      {
        "id": 1,
        "credential_id": 1,
        "store_id": 3,
        "device_id": "d88b4c010005b264",
        "device_token": "...",
        "device_type": "THSensor",
        "device_name": "freezer 03795-00038",
        "model_name": "YS8003-UC",
        "is_hub": false,
        "parsed_store_number": "03795-00038",
        "store": {
          "id": 3,
          "store_number": "03795-00038",
          "store_name": "PNE Foods Store 38"
        }
      }
    ]
  }
}
```

#### Update Credential

```
PUT /api/credentials/{id}
Content-Type: application/json

{
  "uaid": "ua_UPDATED_UAID",
  "secret": "sec_v1_NEW_SECRET"
}
```

- `uaid` is required (unique, excluding current record)
- `secret` is optional — omit to keep the existing secret

**Response:**

```json
{
  "success": true,
  "credential": { ... }
}
```

#### Delete Credential

```
DELETE /api/credentials/{id}
```

**Response:**

```json
{
  "success": true
}
```

> **Warning:** Deleting a credential may cascade-delete or orphan associated devices depending on your foreign key constraints.

#### Sync Devices

Fetches the device list from YoSmart, upserts into the local database, and auto-links devices to stores by parsing store numbers from device names.

```
POST /api/credentials/{id}/sync
```

**Response:**

```json
{
  "success": true,
  "synced": 36,
  "linked": 30,
  "unmatched": 4,
  "removed": 0
}
```

| Field | Description |
|---|---|
| `synced` | Total devices upserted |
| `linked` | Devices auto-linked to stores (store number found in name and matched) |
| `unmatched` | Non-hub devices that couldn't be linked to a store |
| `removed` | Devices deleted because they no longer exist on YoSmart |

**How auto-linking works:**

1. The device name is parsed for a `XXXXX-XXXXX` pattern (e.g., `"freezer 03795-00038"` → `"03795-00038"`)
2. The parsed number is matched against active stores' `store_number`
3. Hub devices are never linked to stores (they are global)

#### Assign Device to Store

Manually assign or unassign a device to a store.

```
PUT /api/credentials/{credential_id}/devices/{device_id}/assign
Content-Type: application/json

{
  "store_id": 3
}
```

Set `store_id` to `null` to unassign.

**Response:**

```json
{
  "success": true,
  "device": {
    "id": 5,
    "store_id": 3,
    "device_name": "cooler sensor",
    "store": {
      "id": 3,
      "store_number": "03795-00038",
      "store_name": "PNE Foods Store 38"
    }
  }
}
```

---

### 4.2 YoSmart Device API

These endpoints proxy requests to the YoSmart cloud API through a specific credential.

**Base:** `/api/credentials/{credential_id}/yosmart`

#### List Devices

Fetches the full device list from YoSmart (cached locally).

```
GET /api/credentials/{credential_id}/yosmart/devices
```

**Response:**

```json
{
  "success": true,
  "count": 36,
  "devices": [
    {
      "deviceId": "d88b4c010005b264",
      "deviceUDID": "...",
      "name": "freezer 03795-00038",
      "token": "device_token_here",
      "type": "THSensor",
      "modelName": "YS8003-UC"
    }
  ]
}
```

#### Get Home Info

Returns general account/home information from YoSmart.

```
GET /api/credentials/{credential_id}/yosmart/home
```

**Response:**

```json
{
  "success": true,
  "home": {
    "id": "...",
    "name": "My Home",
    "devices": [...]
  }
}
```

#### Get Single Device State

Fetch the current state of a specific device.

```
POST /api/credentials/{credential_id}/yosmart/device/state
Content-Type: application/json

{
  "deviceId": "d88b4c010005b264",
  "deviceToken": "device_token_here",
  "deviceType": "THSensor"
}
```

**Response:**

```json
{
  "success": true,
  "deviceType": "THSensor",
  "method": "THSensor.getState",
  "state": {
    "online": true,
    "state": {
      "temperature": 4.2,
      "humidity": 65.3,
      "battery": 4,
      "alarm": {
        "lowBattery": false,
        "lowTemp": false,
        "highTemp": false,
        "lowHumidity": false,
        "highHumidity": false
      },
      "mode": "normal"
    },
    "reportAt": "2026-04-07T18:00:00.000Z"
  }
}
```

**Validation:**

| Field | Rules |
|---|---|
| `deviceId` | Required, string, max 100 |
| `deviceToken` | Required, string, max 200 |
| `deviceType` | Required, string, max 50 |

#### Get All Device States (Batch)

Fetches states for all devices concurrently using HTTP connection pooling.

```
GET /api/credentials/{credential_id}/yosmart/device/states
```

**Response:**

```json
{
  "success": true,
  "count": 36,
  "successCount": 34,
  "devices": [
    {
      "deviceId": "d88b4c010005b264",
      "deviceType": "THSensor",
      "name": "freezer 03795-00038",
      "modelName": "YS8003-UC",
      "success": true,
      "state": {
        "temperature": 4.2,
        "humidity": 65.3,
        "battery": 4,
        "alarm": { ... }
      },
      "online": true,
      "reportedAt": "2026-04-07T18:00:00.000Z"
    }
  ]
}
```

#### Control Device

Send a control command to a device (e.g., turn on/off, set parameters).

```
POST /api/credentials/{credential_id}/yosmart/device/control
Content-Type: application/json

{
  "deviceId": "d88b4c010005b264",
  "deviceToken": "device_token_here",
  "method": "Outlet.setState",
  "params": {
    "state": "open"
  }
}
```

**Validation:**

| Field | Rules |
|---|---|
| `deviceId` | Required, string, max 100 |
| `deviceToken` | Required, string, max 200 |
| `method` | Required, string, max 100 |
| `params` | Optional, array |

**Response:**

```json
{
  "success": true,
  "data": { ... }
}
```

#### Health Check

Check if the YoSmart API connection is working for a credential.

```
GET /api/credentials/{credential_id}/yosmart/health
```

**Response:**

```json
{
  "status": "ok",
  "yosmart_api": "connected",
  "credentials_configured": true
}
```

Possible `status` values: `ok`, `error`, `unconfigured`

---

### 4.3 Store Management

#### List All Stores

```
GET /api/stores
```

**Response:**

```json
{
  "success": true,
  "stores": [
    {
      "id": 1,
      "store_number": "03795-00038",
      "store_name": "PNE Foods Store 38",
      "is_active": true,
      "created_at": "2026-04-07T15:00:00.000000Z"
    }
  ]
}
```

#### Create Store

```
POST /api/stores
Content-Type: application/json

{
  "store_number": "03795-00040",
  "store_name": "PNE Foods Store 40"
}
```

**Validation:**

| Field | Rules |
|---|---|
| `store_number` | Required, must match `XXXXX-XXXXX` format (regex: `^\d{5}-\d{5}$`), unique |
| `store_name` | Required, string, max 255 |

**Response (201):**

```json
{
  "success": true,
  "store": {
    "id": 4,
    "store_number": "03795-00040",
    "store_name": "PNE Foods Store 40",
    "is_active": true
  }
}
```

#### Show Store

```
GET /api/stores/{id}
```

#### Update Store

```
PUT /api/stores/{id}
Content-Type: application/json

{
  "store_number": "03795-00040",
  "store_name": "PNE Foods Store 40 - Updated"
}
```

#### Delete Store

```
DELETE /api/stores/{id}
```

---

### 4.4 Reports (Admin)

#### Capture Snapshot

Manually trigger a live snapshot for all stores.

```
POST /api/reports/snapshot
```

**Response:**

```json
{
  "success": true,
  "total_captured": 30,
  "details": [ ... ]
}
```

#### Get Report Data

```
GET /api/reports/data?period=daily&date=2026-04-07&store_id=1
```

**Query Parameters:**

| Param | Default | Description |
|---|---|---|
| `period` | `daily` | One of: `daily`, `weekly`, `monthly` |
| `date` | today | Base date for the period (YYYY-MM-DD) |
| `store_id` | — | Filter by store ID |

#### Get Report History

```
GET /api/reports/history?from=2026-04-01&to=2026-04-07&per_page=50
```

---

### 4.5 Snapshot Schedules

Manage the global automated snapshot schedule.

#### Get Schedule

```
GET /api/reports/schedules
```

#### Create/Update Schedule

```
POST /api/reports/schedules
Content-Type: application/json

{
  "frequency": "hourly",
  "time": "00:00",
  "is_active": true
}
```

#### Toggle Schedule

```
PATCH /api/reports/schedules/toggle
```

#### Run Snapshot Now

```
POST /api/reports/schedules/run-now
```

---

### 4.6 App Settings

#### Get Temperature Unit

```
GET /api/settings/temperature-unit
```

**Response:**

```json
{
  "success": true,
  "unit": "C"
}
```

#### Update Temperature Unit

```
PUT /api/settings/temperature-unit
Content-Type: application/json

{
  "unit": "F"
}
```

Accepts `C` (Celsius) or `F` (Fahrenheit).

---

## 5. Public API (Token Required)

These routes are stateless and authenticated via Bearer token. They are designed for external systems consuming sensor data.

**Base:** `/api/stores/{store_number}`

`{store_number}` is the store's number in `XXXXX-XXXXX` format (e.g., `03795-00038`).

### 5.1 Live Sensor Data

Fetch real-time sensor readings for all devices in a store.

```
GET /api/stores/03795-00038/sensors
Authorization: Bearer {token}
```

**Query Parameters:**

| Param | Default | Description |
|---|---|---|
| `unit` | `C` | Temperature unit: `C` or `F` |

**Response:**

```json
{
  "success": true,
  "store": {
    "store_number": "03795-00038",
    "store_name": "PNE Foods Store 38"
  },
  "temperature_unit": "C",
  "hub": {
    "device_id": "d88b4c0100xxxxxx",
    "device_name": "YoLink Hub",
    "device_type": "Hub",
    "is_hub": true,
    "online": true,
    "temperature": null,
    "state": { ... },
    "success": true
  },
  "sensors": [
    {
      "device_id": "d88b4c010005b264",
      "device_name": "freezer 03795-00038",
      "device_type": "THSensor",
      "model_name": "YS8003-UC",
      "is_hub": false,
      "online": true,
      "temperature": 4.2,
      "temperature_unit": "C",
      "state": {
        "temperature": 4.2,
        "humidity": 65.3,
        "battery": 4,
        "alarm": {
          "lowBattery": false,
          "lowTemp": false,
          "highTemp": false
        },
        "mode": "normal"
      },
      "reported_at": "2026-04-07T18:00:00.000Z",
      "success": true,
      "error": null
    }
  ],
  "count": 8,
  "fetched_at": "2026-04-07T18:10:00+00:00"
}
```

#### Live Sensor Data — Many Stores (Bulk)

Fetch real-time sensor readings for several stores in one call. Provide
`store_ids` (an array of **store numbers**) to select specific stores, or omit
it / leave it empty to get **every active store**.

```
GET /api/stores/sensors?store_ids[]=03795-00038&store_ids[]=03795-00040&unit=F
Authorization: Bearer {token}
```

**Query Parameters:**

| Param | Default | Description |
|---|---|---|
| `store_ids[]` | _(all active stores)_ | Repeatable store-number filter. Also accepts a comma-separated string: `store_ids=03795-00038,03795-00040`. Empty/omitted ⇒ all active stores. |
| `unit` | `C` | Temperature unit: `C` or `F` |

**Response:**

```json
{
  "success": true,
  "temperature_unit": "F",
  "count": 2,
  "stores": [
    {
      "store": { "store_number": "03795-00038", "store_name": "PNE Foods Store 38" },
      "hub": { "device_id": "hub-001", "is_hub": true, "online": true, "...": "..." },
      "sensors": [ { "device_id": "...", "temperature": 41.18, "...": "..." } ],
      "count": 1
    }
  ],
  "requested": ["03795-00038", "03795-00040"],
  "missing":   ["03795-00040"],
  "fetched_at": "2026-04-07T18:10:00+00:00"
}
```

- `count` is the number of stores returned. Each store object has its own
  `count` of sensors (hub excluded).
- `requested` echoes the store numbers asked for (empty when none were given).
- `missing` lists requested store numbers that were not found or are inactive.

> **Performance:** with no `store_ids`, this fetches live YoSmart state for every
> device of every active store sequentially. For a large fleet this can be slow;
> prefer passing explicit `store_ids` for predictable latency.

> **Authorization:** the auth server (pizzasys) must have an AuthRule granting
> service `sensors-app` access to `GET /api/stores/sensors` (route
> `api.stores.sensors.bulk`), otherwise a valid token returns `403`.

#### Live-data freshness & rate limiting (both sensor endpoints)

YoSmart (YoLink) enforces an upstream rate limit. To stay under it and to keep
the API usable when it is hit, every device entry returned by the live sensor
endpoints carries freshness metadata:

Reads are **live-first**: every device is fetched fresh from YoSmart on each
request; the cache and the stored report are only used as fallbacks when the
live read fails. The fallback order is **live → cache → last_report → unavailable**.

| Field | Type | Meaning |
|---|---|---|
| `source` | string | Where the reading came from: `live` (just fetched, fresh), `cache` (live read failed → most recent cached live reading, ≤60s), `last_report` (no cache → last stored DB reading), or `unavailable` (no live data and nothing ever recorded). |
| `stale` | bool | `true` for any non-`live` source (`cache`/`last_report`/`unavailable`). `false` only for fresh `live` data. |
| `as_of` | string\|null | ISO-8601 timestamp the reading is accurate as of. |
| `notice` | string\|null | Human-readable reason when `stale`; `null` on fresh `live` reads. |

Behavior:

- **Live-first + chunked fetching:** device states are fetched fresh from
  YoSmart in **bounded concurrent chunks** (default 5 at a time — YoLink caps a
  UAC at ~5 connections) rather than all at once. This keeps data current and
  avoids self-inflicting the rate limit. Tunable via `YOSMART_STATE_CHUNK_SIZE`
  and `YOSMART_CHUNK_DELAY_MS`.
- **Cache fallback:** if a device's live read fails, the most recent cached
  live reading (≤60s) is returned instead — `source: "cache"`, `stale: true`.
- **Rate limited (YoSmart `010301`):** the request still returns `200`; the
  device falls back to `cache`, or to `last_report` (the last stored DB reading)
  if no cache exists — `stale: true`, with a `notice`. After a rate limit the
  API briefly stops calling YoSmart (cooldown) and serves fallbacks so the limit
  can recover.
- **No data at all:** if a device's live read fails, has no cache, **and** has
  never been recorded, its entry is `source: "unavailable"`, `success: false`.

> **Frontend tip:** treat `stale === true` as "show last value + subtle 'live
> refresh paused' badge", not as an error. The request itself is still `200`.

### 5.2 Reports

#### Aggregated Report

```
GET /api/stores/03795-00038/reports?period=daily&date=2026-04-07&unit=F&fields=overall,time_series
Authorization: Bearer {token}
```

**Query Parameters:**

| Param | Default | Description |
|---|---|---|
| `period` | `daily` | `daily`, `weekly`, or `monthly` |
| `date` | today | Base date (YYYY-MM-DD) |
| `unit` | `C` | Temperature unit: `C` or `F` |
| `device_id` | — | Filter by specific device ID |
| `fields` | all | Comma-separated: `overall`, `time_series`, `device_summary` |

**Response:**

```json
{
  "success": true,
  "store": {
    "store_number": "03795-00038",
    "store_name": "PNE Foods Store 38"
  },
  "period": "daily",
  "temperature_unit": "F",
  "range": {
    "from": "2026-04-07T00:00:00+00:00",
    "to": "2026-04-07T23:59:59+00:00"
  },
  "overall": {
    "avg_temp": 39.56,
    "min_temp": 33.80,
    "max_temp": 45.20,
    "avg_humidity": 62.30,
    "total_readings": 240,
    "total_alarms": 2,
    "total_offline": 0
  },
  "time_series": [
    {
      "time_bucket": "00:00",
      "avg_temp": 38.10,
      "min_temp": 37.40,
      "max_temp": 38.80,
      "avg_humidity": 63.10,
      "reading_count": 10
    }
  ],
  "device_summary": [
    {
      "device_id": "d88b4c010005b264",
      "device_name": "freezer 03795-00038",
      "avg_temp": 35.60,
      "min_temp": 33.80,
      "max_temp": 37.40,
      "avg_humidity": 70.20,
      "reading_count": 24,
      "alarm_count": 0,
      "offline_count": 0
    }
  ],
  "generated_at": "2026-04-07T18:15:00+00:00"
}
```

#### Capture Snapshot (Public)

```
POST /api/stores/03795-00038/reports/snapshot
Authorization: Bearer {token}
```

Fetches live state from YoSmart for every device in the store and saves a `SensorReport` row per device.

**Response:**

```json
{
  "success": true,
  "store": { "store_number": "03795-00038", "store_name": "PNE Foods Store 38" },
  "temperature_unit": "C",
  "snapshot": [
    {
      "device_id": "d88b4c010005b264",
      "device_name": "freezer 03795-00038",
      "device_type": "THSensor",
      "online": true,
      "temperature": 4.2,
      "temperature_unit": "C",
      "humidity": 65.3,
      "battery": 4,
      "alarm": false,
      "state": "normal",
      "reported_at": "2026-04-07T18:00:00+00:00",
      "success": true
    }
  ],
  "count": 8,
  "captured_at": "2026-04-07T18:10:00+00:00"
}
```

#### Report History (Paginated)

```
GET /api/stores/03795-00038/reports/history?from=2026-04-01&to=2026-04-07&per_page=50&device_type=THSensor
Authorization: Bearer {token}
```

**Query Parameters:**

| Param | Default | Description |
|---|---|---|
| `from` | — | Start date (YYYY-MM-DD) |
| `to` | — | End date (YYYY-MM-DD) |
| `per_page` | 50 | Results per page (max 200) |
| `device_type` | — | Filter by device type (e.g., `THSensor`) |
| `device_id` | — | Filter by specific device ID |
| `unit` | `C` | Temperature unit |

**Response:** Standard Laravel paginated response wrapping `SensorReport` records.

#### Alerts

```
GET /api/stores/03795-00038/reports/alerts?from=2026-04-06&to=2026-04-07
Authorization: Bearer {token}
```

Returns alarm events and offline periods within a date range (defaults to last 24 hours).

**Response:**

```json
{
  "success": true,
  "store": { ... },
  "temperature_unit": "C",
  "range": { "from": "...", "to": "..." },
  "alarms": [ ... ],
  "alarm_count": 2,
  "offline_events": [ ... ],
  "offline_count": 0
}
```

---

## 6. Data Models

### YoSmartCredential

| Column | Type | Description |
|---|---|---|
| `id` | int | Primary key |
| `uaid` | string | YoSmart User Access ID (unique) |
| `secret` | encrypted | YoSmart Secret (encrypted at rest via Laravel's `encrypted` cast) |
| `is_active` | boolean | Whether this credential is active |
| `last_synced_at` | datetime | Last time devices were synced |

**Relationships:**
- `hasMany` → StoreDevice (via `credential_id`)

### Store

| Column | Type | Description |
|---|---|---|
| `id` | int | Primary key |
| `store_number` | string | Unique store identifier (format: `XXXXX-XXXXX`) |
| `store_name` | string | Display name |
| `is_active` | boolean | Whether the store is active |

**Relationships:**
- `hasMany` → StoreDevice (via `store_id`)

### StoreDevice

| Column | Type | Description |
|---|---|---|
| `id` | int | Primary key |
| `credential_id` | int | FK → `yosmart_credentials.id` |
| `store_id` | int (nullable) | FK → `stores.id` |
| `device_id` | string | YoSmart device ID |
| `device_token` | string | YoSmart device token (for API calls) |
| `device_type` | string | Device type (e.g., `THSensor`, `Hub`) |
| `device_name` | string | Device name from YoSmart |
| `model_name` | string (nullable) | Hardware model name |
| `is_hub` | boolean | Whether this is a hub device |
| `parsed_store_number` | string (nullable) | Store number extracted from device name |

**Unique Constraint:** `(credential_id, device_id)`

**Relationships:**
- `belongsTo` → YoSmartCredential
- `belongsTo` → Store

### SensorReport

| Column | Type | Description |
|---|---|---|
| `id` | int | Primary key |
| `store_id` | int | FK → `stores.id` |
| `store_device_id` | int | FK → `store_devices.id` |
| `device_id` | string | YoSmart device ID |
| `device_type` | string | Device type |
| `device_name` | string | Device name |
| `online` | boolean | Whether device was online at capture time |
| `temperature` | decimal(8,2) | Temperature reading (always stored in Celsius) |
| `temperature_unit` | string | Always `c` for stored values |
| `humidity` | decimal(8,2) | Humidity percentage |
| `battery_level` | int (nullable) | Battery level (0-4) |
| `alarm` | boolean | Whether any alarm was active |
| `state` | string (nullable) | Device state (e.g., `normal`) |
| `raw_state` | JSON | Complete raw response from YoSmart |
| `reported_at` | datetime | When the device reported its state |
| `recorded_at` | datetime | When our system captured the snapshot |

**Query Scopes:** `forStore()`, `forDevice()`, `between()`, `thSensors()`, `today()`, `thisWeek()`, `thisMonth()`

### AppSetting

| Column | Type | Description |
|---|---|---|
| `id` | int | Primary key |
| `key` | string | Setting key (e.g., `temperature_unit`) |
| `value` | string | Setting value |

Currently used settings:
- `temperature_unit` — Global display unit: `C` or `F`

---

## 7. YoSmart API Integration Details

### How the App Communicates with YoSmart

The `YoSmartService` class handles all communication with the YoSmart cloud API.

#### API Endpoints

| Purpose | URL | Method |
|---|---|---|
| Get Access Token | `https://api.yosmart.com/open/yolink/token` | POST (form) |
| All API Calls | `https://api.yosmart.com/open/yolink/v2/api` | POST (JSON) |

#### Authentication Flow

```
1. POST to /open/yolink/token with:
   - grant_type: client_credentials
   - client_id: {UAID}
   - client_secret: {Secret}

2. Receive access_token + expires_in

3. Cache token for (expires_in - 300) seconds

4. Use token in Authorization header for API calls:
   Authorization: Bearer {access_token}

5. On 010103/010104 errors → auto-refresh token and retry once
```

#### Making API Calls

All YoSmart API calls follow the same pattern:

```json
POST https://api.yosmart.com/open/yolink/v2/api
Authorization: Bearer {access_token}
Content-Type: application/json

{
  "method": "THSensor.getState",
  "time": 1712524800000,
  "targetDevice": "d88b4c010005b264",
  "token": "device_specific_token"
}
```

**Response:**

```json
{
  "code": "000000",
  "time": 1712524800123,
  "msgid": "...",
  "method": "THSensor.getState",
  "desc": "Success",
  "data": {
    "online": true,
    "state": {
      "temperature": 4.2,
      "humidity": 65.3,
      "battery": 4,
      "alarm": { ... }
    },
    "reportAt": "2026-04-07T18:00:00.000Z"
  }
}
```

#### Supported Device Types

The service supports 28 device types. Each type maps to its own API method (`{Type}.getState`):

| Device Type | API Method | Description |
|---|---|---|
| `THSensor` | `THSensor.getState` | Temperature & humidity sensor |
| `DoorSensor` | `DoorSensor.getState` | Door open/close sensor |
| `MotionSensor` | `MotionSensor.getState` | Motion detection sensor |
| `LeakSensor` | `LeakSensor.getState` | Water leak sensor |
| `Hub` | `Hub.getState` | YoLink Hub |
| `Outlet` | `Outlet.getState` | Smart outlet |
| `Switch` | `Switch.getState` | Smart switch |
| `Lock` | `Lock.getState` | Smart lock |
| `GarageDoor` | `GarageDoor.getState` | Garage door controller |
| `Siren` | `Siren.getState` | Alarm siren |
| `Thermostat` | `Thermostat.getState` | Smart thermostat |
| `VibrationSensor` | `VibrationSensor.getState` | Vibration sensor |
| `COSmokeSensor` | `COSmokeSensor.getState` | CO/Smoke sensor |
| `Sprinkler` | `Sprinkler.getState` | Sprinkler controller |
| `PowerFailureAlarm` | `PowerFailureAlarm.getState` | Power failure alarm |
| ... | ... | (and 13 more types) |

#### Batch State Fetching

The `batchGetStates()` method uses Laravel's `Http::pool()` to fetch states for multiple devices concurrently, dramatically improving performance for stores with many sensors.

```php
$service = new YoSmartService(
    uaid: $credential->uaid,
    secret: $credential->secret,
    credentialId: $credential->id,
);

// Fetch all devices
$devices = $service->listDevices();

// Get states concurrently
$states = $service->batchGetStates($devices);
```

#### Token Auto-Refresh

If the API returns error codes `010103` (authorization invalid) or `010104` (token expired), the service automatically:
1. Clears the cached token
2. Fetches a new token
3. Retries the original request once

### YoSmart Error Codes

| Code | Meaning | Hint |
|---|---|---|
| `000000` | Success | — |
| `000101` | Cannot connect to Hub | Hub may be offline |
| `000102` | Hub cannot respond | Hub busy or unsupported command |
| `000201` | Cannot connect to device | Device offline or out of range |
| `000202` | Device cannot respond | Unsupported operation |
| `010103` | Authorization invalid | Token will auto-refresh |
| `010104` | Token expired | Token will auto-refresh |
| `010203` | Method not supported | Wrong device type mapping |
| `020101` | Device does not exist | Refresh device list |

---

## 8. Error Handling

### Standard Error Response

All API endpoints return errors in a consistent format:

```json
{
  "success": false,
  "error": "Human-readable error description"
}
```

### HTTP Status Codes

| Code | Meaning |
|---|---|
| `200` | Success |
| `201` | Created (new resource) |
| `400` | Bad request (YoSmart API failed, invalid params) |
| `401` | Unauthorized (missing or invalid auth) |
| `404` | Resource not found |
| `422` | Validation error or incomplete config |
| `500` | Internal server error |

### Validation Errors (422)

Laravel validation errors are returned as:

```json
{
  "message": "The uaid field is required.",
  "errors": {
    "uaid": ["The uaid field is required."],
    "secret": ["The secret field is required."]
  }
}
```

---

## 9. Code Examples

### cURL — Fetch Live Sensors

```bash
# Public API: Get live sensor data
curl -X GET "https://your-domain.com/api/stores/03795-00038/sensors?unit=F" \
  -H "Authorization: Bearer YOUR_API_TOKEN" \
  -H "Accept: application/json"
```

### cURL — Create a Credential and Sync (Admin)

```bash
# Step 1: Login (get session cookie)
curl -X POST "https://your-domain.com/login" \
  -H "Content-Type: application/json" \
  -c cookies.txt \
  -d '{"email":"admin@example.com","password":"your-password"}'

# Step 2: Create credential
curl -X POST "https://your-domain.com/api/credentials" \
  -H "Content-Type: application/json" \
  -H "Accept: application/json" \
  -b cookies.txt \
  -d '{"uaid":"ua_YOUR_UAID","secret":"sec_v1_YOUR_SECRET"}'

# Step 3: Sync devices (assuming credential ID = 1)
curl -X POST "https://your-domain.com/api/credentials/1/sync" \
  -H "Accept: application/json" \
  -b cookies.txt
```

### JavaScript / Fetch

```javascript
// Fetch live sensor data from a store
async function getSensorData(storeNumber, apiToken) {
  const response = await fetch(
    `https://your-domain.com/api/stores/${storeNumber}/sensors?unit=F`,
    {
      headers: {
        'Authorization': `Bearer ${apiToken}`,
        'Accept': 'application/json',
      },
    }
  );

  const data = await response.json();

  if (data.success) {
    console.log(`Store: ${data.store.store_name}`);
    console.log(`Hub online: ${data.hub?.online}`);

    data.sensors.forEach(sensor => {
      console.log(
        `${sensor.device_name}: ${sensor.temperature}°${data.temperature_unit} ` +
        `| Humidity: ${sensor.state?.humidity}% ` +
        `| Online: ${sensor.online}`
      );
    });
  }

  return data;
}

// Usage
getSensorData('03795-00038', 'your-bearer-token');
```

### Python

```python
import requests

BASE_URL = "https://your-domain.com"
API_TOKEN = "your-bearer-token"

headers = {
    "Authorization": f"Bearer {API_TOKEN}",
    "Accept": "application/json",
}

# Get live sensor data
response = requests.get(
    f"{BASE_URL}/api/stores/03795-00038/sensors",
    headers=headers,
    params={"unit": "F"},
)
data = response.json()

if data["success"]:
    for sensor in data["sensors"]:
        print(
            f"{sensor['device_name']}: "
            f"{sensor['temperature']}°{data['temperature_unit']} | "
            f"Humidity: {sensor['state'].get('humidity', 'N/A')}% | "
            f"Online: {sensor['online']}"
        )

# Get daily report
response = requests.get(
    f"{BASE_URL}/api/stores/03795-00038/reports",
    headers=headers,
    params={"period": "daily", "date": "2026-04-07", "unit": "F"},
)
report = response.json()

if report["success"]:
    overall = report["overall"]
    print(f"Avg Temp: {overall['avg_temp']}°F")
    print(f"Min/Max: {overall['min_temp']}–{overall['max_temp']}°F")
    print(f"Total Readings: {overall['total_readings']}")
    print(f"Alarms: {overall['total_alarms']}")
```

### PHP (External Application)

```php
<?php

$baseUrl  = 'https://your-domain.com';
$apiToken = 'your-bearer-token';

// Fetch live sensors
$ch = curl_init("{$baseUrl}/api/stores/03795-00038/sensors?unit=C");
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_HTTPHEADER     => [
        "Authorization: Bearer {$apiToken}",
        'Accept: application/json',
    ],
]);

$response = json_decode(curl_exec($ch), true);
curl_close($ch);

if ($response['success']) {
    foreach ($response['sensors'] as $sensor) {
        printf(
            "%s: %.1f°C | Humidity: %.1f%% | Online: %s\n",
            $sensor['device_name'],
            $sensor['temperature'],
            $sensor['state']['humidity'] ?? 0,
            $sensor['online'] ? 'Yes' : 'No'
        );
    }
}
```

---

## 10. Deployment Notes

### Environment Variables

Ensure these are set in `.env`:

```env
# App key (required for credential secret encryption)
APP_KEY=base64:...

# Database
DB_CONNECTION=mysql        # or pgsql
DB_HOST=127.0.0.1
DB_PORT=3306
DB_DATABASE=your_database
DB_USERNAME=your_user
DB_PASSWORD=your_password

# Auth server (for public API token verification)
# IMPORTANT: VERIFY_PATH must match the auth server route exactly:
#   POST {BASE_URL}/api/v1/auth/token-verify   (hyphen, NOT token/verify)
# SERVICE_NAME must match a registered ServiceClient name on the auth server.
# If CALL_TOKEN (or any of these) is empty, every public API call returns HTTP 500.
AUTH_SERVER_BASE_URL=https://auth.example.com
AUTH_SERVER_VERIFY_PATH=/api/v1/auth/token-verify
AUTH_SERVER_SERVICE_NAME=sensors-app
AUTH_SERVER_CALL_TOKEN=service-to-service-token
```

### Critical: APP_KEY

The `APP_KEY` is used to encrypt/decrypt credential secrets. If you change it:
- All existing encrypted secrets become unreadable
- You'll need to re-enter all credential secrets

### Database Compatibility

The app supports both **PostgreSQL** and **MySQL**. The report queries and migrations handle dialect differences automatically (date formatting, boolean literals, foreign key handling).

### Building Frontend Assets

```bash
npm run build     # Production build
npm run dev       # Development with HMR
```

### Running Migrations

```bash
php artisan migrate
```

### Cache

Token caching uses Laravel's default cache driver. For production, configure Redis:

```env
CACHE_DRIVER=redis
REDIS_HOST=127.0.0.1
REDIS_PORT=6379
```

YoSmart access tokens are cached for `(expires_in - 300)` seconds (typically ~55 minutes). Device lists are cached for 1 hour.
