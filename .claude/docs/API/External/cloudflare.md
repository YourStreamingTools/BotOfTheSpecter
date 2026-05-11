# Cloudflare R2 — Local API Reference (BotOfTheSpecter)

This document is a comprehensive local reference for Cloudflare R2's S3-compatible API. It covers the full API surface relevant to this project: authentication, bucket operations, object operations, multipart uploads, presigned URLs, CORS, public access, lifecycle rules, S3 compatibility notes, and SDK usage patterns.

For active callsites in this codebase see §11 at the bottom.

---

## Table of Contents

1. [Authentication & Credentials](#1-authentication--credentials)
2. [Endpoint Format](#2-endpoint-format)
3. [Bucket Operations](#3-bucket-operations)
4. [Object Operations](#4-object-operations)
5. [Multipart Uploads](#5-multipart-uploads)
6. [Presigned URLs](#6-presigned-urls)
7. [CORS Configuration](#7-cors-configuration)
8. [Public Bucket Access](#8-public-bucket-access)
9. [Lifecycle Rules](#9-lifecycle-rules)
10. [S3 Compatibility Notes](#10-s3-compatibility-notes)
11. [SDK Usage Patterns](#11-sdk-usage-patterns)
12. [BotOfTheSpecter Callsites](#12-botofthespecter-callsites)

---

## 1. Authentication & Credentials

### 1.1 Token types

| Type | Bound to | Deactivated when |
| ---- | -------- | ---------------- |
| **Account API token** | Cloudflare account | Manually revoked |
| **User API token** | Individual Cloudflare user | User removed from account |

Account API tokens are preferred for service integrations. Only Super Administrators can create them.

### 1.2 Permission scopes

| Permission | Can do |
| ---------- | ------ |
| **Admin Read & Write** | Create/list/delete buckets, edit bucket configuration, read/write/list objects, manage data catalog tables |
| **Admin Read Only** | List buckets, view configuration, read and list objects |
| **Object Read & Write** | Read, write, and list objects in specified buckets |
| **Object Read Only** | Read and list objects in specified buckets |

Use the lowest scope that satisfies the use case. For uploading stream recordings, `Object Read & Write` scoped to a specific bucket is correct. For export ZIPs (read back by presigned URL), `Object Read & Write` on the exports bucket only.

### 1.3 Creating a token

Dashboard path: R2 → (right panel) **Account Details** → **Manage API Tokens** → **Create API Token**.

- Choose token type (Account or User).
- Set permission level.
- Optionally scope to specific buckets (object-level permissions only).
- After creation, save the **Secret Access Key immediately** — it is shown only once. The Access Key ID remains visible.

S3 credential format after creation:

```
Access Key ID:     <random string>     (maps to API token id)
Secret Access Key: <sha256 hash>       (maps to SHA-256 of token value)
```

### 1.4 Temporary credentials

R2 supports short-lived, scoped credentials derived from a parent token — issued either through the Cloudflare Temporary Credentials API or by locally signing JWTs with the parent token's secret. Useful for delegating limited, time-bounded access without issuing full API tokens.

---

## 2. Endpoint Format

### 2.1 Standard S3 endpoint

```
https://<ACCOUNT_ID>.r2.cloudflarestorage.com
```

All standard S3 SDK operations target this root. The `<ACCOUNT_ID>` is your Cloudflare numeric account identifier.

### 2.2 Virtual-hosted style (required for presigned URLs)

```
https://<BUCKET_NAME>.<ACCOUNT_ID>.r2.cloudflarestorage.com/<OBJECT_KEY>
```

boto3 requires `s3={'addressing_style': 'virtual'}` in its `Config` to produce this form for presigned URL generation.

### 2.3 Jurisdiction-specific endpoints

| Jurisdiction | Endpoint |
| ------------ | -------- |
| Default | `https://<ACCOUNT_ID>.r2.cloudflarestorage.com` |
| EU | `https://<ACCOUNT_ID>.eu.r2.cloudflarestorage.com` |
| FedRAMP | `https://<ACCOUNT_ID>.fedramp.r2.cloudflarestorage.com` |

### 2.4 Region name

R2's canonical region name is `auto`. SDKs also accept `us-east-1` as an alias — R2 internally maps it to `auto`. **Never set a real AWS region** (`eu-west-1`, `ap-southeast-2`, etc.) — R2 rejects those.

---

## 3. Bucket Operations

R2 supports 12 bucket-level S3 operations. None support the `x-amz-expected-bucket-owner` header. No bucket policies, no bucket ACLs.

### 3.1 Bucket naming rules

- Lowercase letters (`a-z`), digits (`0-9`), hyphens (`-`) only.
- 3–63 characters.
- Cannot start or end with a hyphen.
- Case-sensitive (lowercase only).
- Private by default — no listing or access without credentials.

### 3.2 ListBuckets

Returns all buckets in the account. R2 adds custom headers for pagination on accounts with 1,000+ buckets.

**Request headers (R2 extensions):**

| Header | Description |
| ------ | ----------- |
| `cf-prefix` | Filter by bucket name prefix |
| `cf-start-after` | Start listing after this bucket name |
| `cf-continuation-token` | Resume from a previous page |
| `cf-max-keys` | Max buckets to return (default and max: 1,000) |

**Response headers (R2 extensions):**

| Header | Description |
| ------ | ----------- |
| `cf-is-truncated` | `true` if more buckets exist |
| `cf-next-continuation-token` | Token to pass in next request |

**Response body** (XML): `<ListAllMyBucketsResult>` with `<Buckets>` list, each containing `<Name>`, `<CreationDate>`.

### 3.3 CreateBucket

```
PUT /<bucket-name>
```

Creates an empty bucket. ACL, object locking, and bucket owner verification are not supported. No location constraint is required; bucket placement is automatic (`auto`).

**boto3:**
```python
s3.create_bucket(Bucket='my-bucket')
```

**PHP S3Client:**
```php
$s3->createBucket(['Bucket' => 'my-bucket']);
```

### 3.4 HeadBucket

```
HEAD /<bucket-name>
```

Returns `200 OK` if the bucket exists and the credentials have access. Returns `404 NoSuchBucket` or `403 AccessDenied` otherwise. Does not return a body.

### 3.5 DeleteBucket

```
DELETE /<bucket-name>
```

Deletes an empty bucket. Returns `409 BucketNotEmpty` if objects remain. Bucket deletion is irreversible. No bucket owner validation.

### 3.6 GetBucketLocation

```
GET /<bucket-name>?location
```

Returns `<LocationConstraint>` element. R2 always returns either `auto` or the jurisdiction string.

### 3.7 GetBucketEncryption

```
GET /<bucket-name>?encryption
```

Returns the bucket's encryption configuration. R2 encrypts all objects at rest by default; this operation returns that configuration.

---

## 4. Object Operations

R2 supports 14 object-level S3 operations. No object ACLs, no object tagging, no SSE-KMS, no object locking, no request payer headers.

### 4.1 PutObject

```
PUT /<bucket-name>/<object-key>
Host: <ACCOUNT_ID>.r2.cloudflarestorage.com
```

**Key request headers:**

| Header | Description |
| ------ | ----------- |
| `Content-Type` | MIME type of the object |
| `Content-Length` | Size in bytes |
| `Content-Disposition` | Browser download/inline hint |
| `Cache-Control` | Cache control directives |
| `x-amz-storage-class` | `STANDARD` (default) or `STANDARD_IA` |
| `x-amz-meta-*` | Custom metadata (Unicode via RFC 2047 encoding) |
| `Content-MD5` | Base64-encoded MD5 checksum for integrity |
| `x-amz-checksum-*` | CRC-32, CRC-32C, SHA-1, SHA-256 checksums |
| `If-Match` | Conditional write: only if ETag matches |
| `If-None-Match` | Conditional write: only if ETag does not match (use `*` to prevent overwrite) |
| `If-Modified-Since` | Conditional write |
| `If-Unmodified-Since` | Conditional write |

**Max single-part size:** 5 GiB.

**Response headers:**

| Header | Description |
| ------ | ----------- |
| `ETag` | MD5 hash of the object (quoted string) |
| `x-amz-checksum-*` | Echo of checksum if supplied |

**Not supported:** ACL headers (`x-amz-acl`), server-side encryption with KMS (`x-amz-server-side-encryption-aws-kms-key-id`), object tagging (`x-amz-tagging`), object locking headers.

### 4.2 GetObject

```
GET /<bucket-name>/<object-key>
```

**Key request headers:**

| Header | Description |
| ------ | ----------- |
| `Range` | Byte range (e.g. `bytes=0-1023`) — supported |
| `If-Match` | Return object only if ETag matches |
| `If-None-Match` | Return 304 if ETag matches (cache revalidation) |
| `If-Modified-Since` | Return object only if modified after date |
| `If-Unmodified-Since` | Return 412 if modified after date |

**Key response headers:**

| Header | Description |
| ------ | ----------- |
| `Content-Type` | MIME type set at upload |
| `Content-Length` | Object size in bytes |
| `Content-Range` | Present if `Range` was requested |
| `ETag` | MD5 hash of the object |
| `Last-Modified` | RFC 7231 date |
| `x-amz-storage-class` | `STANDARD` or `STANDARD_IA` |
| `x-amz-meta-*` | Custom metadata set at upload |

**Not supported:** `x-amz-request-payer`.

### 4.3 HeadObject

```
HEAD /<bucket-name>/<object-key>
```

Returns all response headers of GetObject but no body. Supports the same conditional and range headers. Used to verify an object exists and to read its metadata without downloading the content.

**Not supported:** `x-amz-request-payer`.

### 4.4 DeleteObject

```
DELETE /<bucket-name>/<object-key>
```

Permanently removes a single object. Returns `204 No Content` on success regardless of whether the object existed. Deletion is irreversible — R2 has no object versioning or soft-delete.

**Not supported:** multi-factor authentication delete, governance retention bypass.

### 4.5 DeleteObjects (batch delete)

```
POST /<bucket-name>?delete
```

Deletes up to 1,000 objects per request. Request body is XML:

```xml
<Delete>
  <Object><Key>key1</Key></Object>
  <Object><Key>key2</Key></Object>
</Delete>
```

Returns a `<DeleteResult>` listing `<Deleted>` keys and any `<Error>` entries.

### 4.6 ListObjectsV2

```
GET /<bucket-name>?list-type=2
```

Preferred over v1 (`ListObjects`). Paginates through all objects using `ContinuationToken`.

**Query parameters:**

| Parameter | Description |
| --------- | ----------- |
| `prefix` | Filter to keys starting with this prefix |
| `delimiter` | Group keys by delimiter (e.g. `/` for pseudo-folder listing) |
| `max-keys` | Max objects per page (default 1,000; max 1,000) |
| `continuation-token` | Resume from a previous response |
| `start-after` | Return keys lexicographically after this value |
| `fetch-owner` | Include object owner info in response (boolean) |
| `encoding-type` | URL-encode keys if set to `url` |

**Response fields per object:**

| Field | Description |
| ----- | ----------- |
| `Key` | Object key |
| `LastModified` | ISO 8601 timestamp |
| `ETag` | MD5 hash |
| `Size` | Bytes |
| `StorageClass` | `STANDARD` or `STANDARD_IA` |

`IsTruncated: true` means more pages exist; use `NextContinuationToken` for the next page.

**Not supported:** `x-amz-request-payer`.

### 4.7 CopyObject

```
PUT /<destination-bucket>/<destination-key>
x-amz-copy-source: /<source-bucket>/<source-key>
```

**Key request headers:**

| Header | Description |
| ------ | ----------- |
| `x-amz-copy-source` | Source path (`/bucket/key`) — URL-encoded if needed |
| `x-amz-metadata-directive` | `COPY` (default), `REPLACE`, or `MERGE` (R2 extension: combine source with new values) |
| `x-amz-storage-class` | Set storage class on destination object |
| Conditional headers | `x-amz-copy-source-if-match`, `x-amz-copy-source-if-none-match`, `x-amz-copy-source-if-modified-since`, `x-amz-copy-source-if-unmodified-since` |
| `cf-copy-destination-if-match` | (R2 extension, beta) Conditional on destination ETag |
| `cf-copy-destination-if-none-match` | (R2 extension, beta) |
| `cf-copy-destination-if-modified-since` | (R2 extension, beta) |
| `cf-copy-destination-if-unmodified-since` | (R2 extension, beta) |

`MERGE` metadata directive is an R2 extension not present in AWS S3. Destination conditionals return `412 PreconditionFailed` if unmet.

**Not supported:** ACL headers, tagging directives.

---

## 5. Multipart Uploads

Use for objects over ~100 MB, for parallel uploads, or when resumability is needed.

### 5.1 Constraints

| Constraint | Value |
| ---------- | ----- |
| Minimum part size | 5 MiB (except the last part) |
| Maximum part size | 5 GiB |
| Maximum number of parts | 10,000 |
| Maximum object size | 5 TiB |
| All non-final parts | Must be identical in size |
| Incomplete upload retention | Auto-aborted after 7 days (configurable via lifecycle) |

### 5.2 CreateMultipartUpload

```
POST /<bucket>/<key>?uploads
```

Initiates the upload. Returns an `UploadId` used for all subsequent part operations.

**Request headers:** Same as PutObject (`Content-Type`, `x-amz-storage-class`, `x-amz-meta-*`, etc.).

**Response:** XML `<InitiateMultipartUploadResult>` with `<Bucket>`, `<Key>`, `<UploadId>`.

R2 extension: `cf-create-bucket-if-missing` header on this request will auto-create the bucket if it doesn't exist (avoids `NoSuchBucket` errors when uploading to a streaming body target).

### 5.3 UploadPart

```
PUT /<bucket>/<key>?partNumber=<N>&uploadId=<UploadId>
```

`N` is an integer 1–10,000. Parts can be uploaded concurrently. Each part returns an `ETag` header that must be collected for `CompleteMultipartUpload`.

### 5.4 UploadPartCopy

```
PUT /<bucket>/<key>?partNumber=<N>&uploadId=<UploadId>
x-amz-copy-source: /<source-bucket>/<source-key>
x-amz-copy-source-range: bytes=<start>-<end>
```

Copies a byte range from an existing R2 object as a part, avoiding re-upload.

### 5.5 CompleteMultipartUpload

```
POST /<bucket>/<key>?uploadId=<UploadId>
```

Request body is XML listing all parts in order:

```xml
<CompleteMultipartUpload>
  <Part><PartNumber>1</PartNumber><ETag>"abc123"</ETag></Part>
  <Part><PartNumber>2</PartNumber><ETag>"def456"</ETag></Part>
</CompleteMultipartUpload>
```

Returns the final object's `ETag` — a hash of the concatenated binary MD5s of all parts, followed by `-<part-count>` (e.g. `"d8e8fca2dc0f896fd7cb4cb0031ba249-2"`). This differs from single-part ETags.

### 5.6 AbortMultipartUpload

```
DELETE /<bucket>/<key>?uploadId=<UploadId>
```

Cancels the upload and frees all stored parts. Always call this in error paths to avoid accumulating partial upload storage charges.

### 5.7 ListMultipartUploads

```
GET /<bucket>?uploads
```

Lists in-progress multipart uploads. Supports `prefix`, `delimiter`, `max-uploads`, `key-marker`, `upload-id-marker` parameters.

### 5.8 ListParts

```
GET /<bucket>/<key>?uploadId=<UploadId>
```

Lists uploaded parts for an in-progress upload. Supports `max-parts`, `part-number-marker` for pagination.

### 5.9 ETag behavior for multipart objects

The ETag of a completed multipart object is NOT an MD5 of the full content. It is:

```
MD5(concat(binary_md5_part_1, binary_md5_part_2, ...)) + "-" + part_count
```

Do not validate multipart objects by comparing ETag to an MD5 of the assembled content. Use HeadObject to confirm the key and size instead.

---

## 6. Presigned URLs

### 6.1 What presigned URLs are

A presigned URL embeds AWS SigV4 signature parameters directly in the query string, allowing a holder to perform a single operation against R2 without possessing API credentials. They are generated client-side — no network request to R2 is made during generation.

### 6.2 Expiry window

| Bound | Value |
| ----- | ----- |
| Minimum | 1 second |
| Maximum | 604,800 seconds (7 days) |

The project uses the maximum (7 days) for export download links.

### 6.3 Supported operations

| HTTP Method | S3 Operation | Use |
| ----------- | ------------ | --- |
| `GET` | `GetObject` | Download / read |
| `HEAD` | `HeadObject` | Metadata check |
| `PUT` | `PutObject` | Browser-direct upload |
| `DELETE` | `DeleteObject` | Delegated deletion |

**POST for multipart HTML form uploads is not supported.** Use presigned PUT for browser-direct uploads.

### 6.4 SigV4 query parameters embedded in the URL

| Parameter | Description |
| --------- | ----------- |
| `X-Amz-Algorithm` | `AWS4-HMAC-SHA256` |
| `X-Amz-Credential` | `<AccessKeyID>/<Date>/<Region>/s3/aws4_request` |
| `X-Amz-Date` | ISO 8601 timestamp at signing time |
| `X-Amz-Expires` | Validity duration in seconds |
| `X-Amz-SignedHeaders` | Semicolon-separated list of signed headers (`host`) |
| `X-Amz-Signature` | HMAC-SHA256 signature hex |

Tampering with any of these parameters produces `403 SignatureDoesNotMatch`.

### 6.5 Custom domain limitation

**Critical:** Presigned URLs are bound to the S3 API hostname (`<ACCOUNT_ID>.r2.cloudflarestorage.com`). They **cannot** be used with custom domains (e.g. `cdn.example.com` pointing at R2). The signature covers the `host` header — a different hostname produces a 403.

For custom-domain-based access control, use Cloudflare WAF HMAC Token Authentication (requires Pro plan).

### 6.6 boto3 presigned URL generation

```python
from botocore.config import Config
import boto3

s3 = boto3.session.Session().client(
    's3',
    endpoint_url=f'https://{ACCOUNT_ID}.r2.cloudflarestorage.com',
    aws_access_key_id=ACCESS_KEY_ID,
    aws_secret_access_key=SECRET_ACCESS_KEY,
    region_name='auto',
    config=Config(
        signature_version='s3v4',                   # Required — never omit
        s3={'addressing_style': 'virtual'},          # Required for presigned URLs
    ),
)

# GET presigned URL
get_url = s3.generate_presigned_url(
    'get_object',
    Params={'Bucket': 'my-bucket', 'Key': 'some/key.zip'},
    ExpiresIn=604800,   # 7 days maximum
)

# PUT presigned URL (browser-direct upload)
put_url = s3.generate_presigned_url(
    'put_object',
    Params={
        'Bucket': 'my-bucket',
        'Key': 'uploads/file.mp4',
        'ContentType': 'video/mp4',    # Lock Content-Type to prevent misuse
    },
    ExpiresIn=3600,
)
```

`signature_version='s3v4'` is mandatory. Without it boto3 may silently fall back to SigV2 and produce `400 InvalidRequest` from R2.

### 6.7 PHP presigned URL generation

Use `createPresignedRequest` — not `getObjectUrl`. `getObjectUrl` produces an unsigned URL that 403s on private buckets.

```php
// GET presigned URL
$cmd = $s3->getCommand('GetObject', ['Bucket' => $bucket, 'Key' => 'file.mp4']);
$request = $s3->createPresignedRequest($cmd, '+7 days');
$url = (string) $request->getUri();

// PUT presigned URL
$cmd = $s3->getCommand('PutObject', ['Bucket' => $bucket, 'Key' => 'file.mp4']);
$request = $s3->createPresignedRequest($cmd, '+1 hour');
$url = (string) $request->getUri();
```

---

## 7. CORS Configuration

### 7.1 Format

R2 CORS configuration is **JSON** (not XML as AWS S3 uses via the SDK). Each rule is an object in an array.

```json
[
  {
    "AllowedOrigins": ["https://dashboard.botofthespecter.com"],
    "AllowedMethods": ["GET", "PUT", "HEAD"],
    "AllowedHeaders": ["Content-Type", "Content-Length"],
    "ExposeHeaders": ["ETag", "Content-Length"],
    "MaxAgeSeconds": 3600
  }
]
```

### 7.2 Fields

| Field | Required | Description |
| ----- | -------- | ----------- |
| `AllowedOrigins` | Yes | `Access-Control-Allow-Origin` values. Must be `scheme://host[:port]` — no paths, no wildcards except `*` |
| `AllowedMethods` | Yes | `GET`, `POST`, `PUT`, `DELETE`, `HEAD` |
| `AllowedHeaders` | No | Headers the browser may send (`Access-Control-Allow-Headers`). Use `*` to allow all |
| `ExposeHeaders` | No | Headers JavaScript can read from the response (default: none) |
| `MaxAgeSeconds` | No | Preflight cache TTL, max 86400 (24 hours) |

### 7.3 Setting CORS via S3 API

```
PUT /<bucket>?cors
Content-Type: application/xml
```

Note: the S3 API uses XML for `PutBucketCors`; the Wrangler CLI and dashboard accept JSON.

**Wrangler CLI:**
```bash
npx wrangler r2 bucket cors set <BUCKET_NAME> --file cors.json
npx wrangler r2 bucket cors list <BUCKET_NAME>
```

### 7.4 Behavior notes

- CORS headers are only returned when the request includes an `Origin` header.
- Propagation after a policy change may take up to 30 seconds.
- Custom-domain caches require a Cloudflare cache purge after CORS policy changes.
- R2 triggers CORS evaluation on `OPTIONS` preflight and on actual cross-origin requests.

---

## 8. Public Bucket Access

### 8.1 Default state

Buckets are private by default. Unauthorized access attempts to private buckets do **not** incur R2 billing charges.

### 8.2 r2.dev subdomain (development only)

Each bucket can be given a managed Cloudflare URL of the form:

```
https://pub-<hash>.r2.dev/<object-key>
```

**Rate limited — do not use in production.** Suitable for testing public access before configuring a custom domain. Do not create CNAME records pointing at `r2.dev` addresses — that is an unsupported access path.

### 8.3 Custom domain (production)

Link any Cloudflare-managed domain (same Cloudflare account) to the bucket:

Dashboard: R2 → bucket → **Settings** → **Custom Domains** → **Add**.

Custom domains provide:
- Cloudflare Cache acceleration (objects are cached at the edge)
- WAF custom rules and bot management
- Zero Trust Access for teammate-only buckets
- WAF Token Authentication for per-user access control (Pro plan)
- Full Cloudflare Analytics

**Custom domains cannot be used with presigned URLs** (see §6.5).

### 8.4 Directory listings

Buckets do not expose directory listings at the root or any prefix. R2 uses a flat namespace; delimiter-based pseudo-folder listing is available only through the S3 `ListObjectsV2` API with `delimiter=/`.

---

## 9. Lifecycle Rules

Automate object expiration or storage class transitions.

### 9.1 Rule structure (XML via S3 API)

```xml
<LifecycleConfiguration>
  <Rule>
    <ID>expire-old-exports</ID>
    <Status>Enabled</Status>
    <Filter>
      <Prefix>user-exports/</Prefix>
    </Filter>
    <Expiration>
      <Days>90</Days>
    </Expiration>
  </Rule>
</LifecycleConfiguration>
```

### 9.2 Supported actions

| Action | Description |
| ------ | ----------- |
| `Expiration` → `Days` | Delete objects N days after creation |
| `Expiration` → `Date` | Delete objects on or after a specific date |
| `Transition` → `STANDARD_IA` | Move from Standard to Infrequent Access after N days |
| `AbortIncompleteMultipartUpload` → `DaysAfterInitiation` | Abort incomplete multipart uploads after N days |

**Reverse transitions (STANDARD_IA → STANDARD) via lifecycle are not supported.** Use CopyObject with `x-amz-storage-class: STANDARD` for manual promotion.

### 9.3 Constraints

- Maximum 1,000 rules per bucket.
- Filter conditions: prefix-based only (no tag-based filtering — tagging not supported).
- Objects are typically removed within 24 hours of expiration.
- When a storage class transition and expiration fall within the same 24-hour window, deletion takes precedence.
- Storage class transitions incur Class A operation charges.
- Default rule: all buckets have an implicit `AbortIncompleteMultipartUpload` rule expiring parts after 7 days. This can be overridden but not deleted.
- Minimum storage duration for `STANDARD_IA` is 30 days. Objects deleted or replaced before 30 days are still charged for the full 30-day period.

### 9.4 S3 API calls

```
GET /<bucket>?lifecycle   → GetBucketLifecycleConfiguration
PUT /<bucket>?lifecycle   → PutBucketLifecycleConfiguration
DELETE /<bucket>?lifecycle → DeleteBucketLifecycleConfiguration
```

---

## 10. S3 Compatibility Notes

### 10.1 What is fully supported

- All six multipart upload operations
- ListBuckets, HeadBucket, CreateBucket, DeleteBucket
- HeadObject, GetObject (range + conditional), PutObject, DeleteObject, DeleteObjects
- ListObjects, ListObjectsV2
- CopyObject (with MERGE extension)
- GetBucketCors, PutBucketCors, DeleteBucketCors
- GetBucketLifecycleConfiguration, PutBucketLifecycleConfiguration
- GetBucketLocation, GetBucketEncryption
- SigV4 request signing
- Virtual-hosted and path-style addressing

### 10.2 What is partially supported

| Feature | AWS S3 | R2 |
| ------- | ------ | -- |
| CopyObject metadata directive | `COPY`, `REPLACE` | `COPY`, `REPLACE`, plus R2-only `MERGE` |
| CopyObject destination conditionals | Not available | Available as `cf-*` beta headers |
| Checksums | CRC-32, CRC-32C, SHA-1, SHA-256 | CRC-64/NVME (full-object); CRC-32, CRC-32C, SHA-1, SHA-256 (composite) |
| ETag for multipart | Same algorithm | Same algorithm (MD5 concat + part count) |
| Unicode metadata | ASCII only (RFC 2047 not automatic) | Automatic RFC 2047 encoding/decoding of `x-amz-meta-*` |
| Region | Per-region endpoints | `auto` only; `us-east-1` aliased to auto |

### 10.3 What is not supported

| Feature | Notes |
| ------- | ----- |
| Object ACLs (`x-amz-acl`, `PutObjectAcl`, `GetObjectAcl`) | Not implemented. Access is via API token scopes |
| Bucket policies (`PutBucketPolicy`, `GetBucketPolicy`) | Not implemented |
| Bucket ACLs | Not implemented |
| Object tagging (`PutObjectTagging`, `GetObjectTagging`, `x-amz-tagging`) | Not implemented |
| Object locking (`x-amz-object-lock-*`, `PutObjectLockConfiguration`) | Not implemented (bucket lock feature is separate) |
| SSE-KMS (`x-amz-server-side-encryption-aws-kms-key-id`) | Not implemented; R2 encrypts at rest by default with its own keys |
| Request payer (`x-amz-request-payer`) | Not supported on any operation |
| `x-amz-expected-bucket-owner` | Not supported on any operation |
| Bucket notifications (`PutBucketNotificationConfiguration`) | Not via S3 API (use R2 Event Notifications via Cloudflare dashboard) |
| Bucket analytics, inventory, metrics configurations | Not implemented |
| POST presigned uploads (HTML form multipart) | Not supported; use presigned PUT |
| Versioning (`PutBucketVersioning`, version IDs) | Not implemented |
| Replication (`PutBucketReplication`) | Not implemented |
| Website hosting (`PutBucketWebsite`) | Not implemented |
| Transfer acceleration | Not implemented (use custom domains + Cloudflare cache instead) |

### 10.4 R2-only extensions

| Extension | Description |
| --------- | ----------- |
| `cf-create-bucket-if-missing` header | Auto-create bucket on PutObject or CreateMultipartUpload |
| `cf-prefix`, `cf-start-after`, `cf-continuation-token`, `cf-max-keys` headers | Pagination on ListBuckets for accounts with 1,000+ buckets |
| `x-amz-metadata-directive: MERGE` | Merge metadata on CopyObject instead of replace |
| `cf-copy-destination-if-*` headers | Conditional writes on CopyObject destination (beta) |

---

## 11. SDK Usage Patterns

### 11.1 boto3 (Python) — full client setup

```python
import boto3
from botocore.config import Config

s3 = boto3.session.Session().client(
    's3',
    endpoint_url=f'https://{ACCOUNT_ID}.r2.cloudflarestorage.com',
    aws_access_key_id=ACCESS_KEY_ID,
    aws_secret_access_key=SECRET_ACCESS_KEY,
    region_name='auto',
    config=Config(
        signature_version='s3v4',               # Required
        s3={'addressing_style': 'virtual'},      # Required for presigned URLs
        connect_timeout=10,
        read_timeout=120,
        retries={'max_attempts': 4, 'mode': 'standard'},
    ),
)
```

**Common operations:**

```python
# Upload (single part)
s3.put_object(Bucket='my-bucket', Key='path/to/file.zip', Body=file_bytes,
              ContentType='application/zip', StorageClass='STANDARD')

# Upload (from file, multipart when large)
from boto3.s3.transfer import TransferConfig
config = TransferConfig(multipart_threshold=100 * 1024 * 1024)   # 100 MB threshold
s3.upload_file('/path/to/file.mp4', 'my-bucket', 'path/file.mp4', Config=config)

# Upload (from file object)
import io
s3.upload_fileobj(io.BytesIO(file_content), 'my-bucket', 'key')

# Head (verify object exists)
info = s3.head_object(Bucket='my-bucket', Key='path/to/file.zip')
# info['ContentLength'], info['ETag'], info['LastModified']

# Download
obj = s3.get_object(Bucket='my-bucket', Key='path/to/file.zip')
data = obj['Body'].read()

# Download with range
obj = s3.get_object(Bucket='my-bucket', Key='file.mp4', Range='bytes=0-1048575')

# List objects
page = s3.list_objects_v2(Bucket='my-bucket', Prefix='user-exports/alice/')
for item in page.get('Contents', []):
    print(item['Key'], item['Size'])

# Paginate through all objects
paginator = s3.get_paginator('list_objects_v2')
for page in paginator.paginate(Bucket='my-bucket', Prefix='user-exports/'):
    for item in page.get('Contents', []):
        print(item['Key'])

# Delete single
s3.delete_object(Bucket='my-bucket', Key='path/to/file.zip')

# Delete multiple
s3.delete_objects(Bucket='my-bucket', Delete={
    'Objects': [{'Key': k} for k in ['key1', 'key2', 'key3']]
})

# Presigned GET (7-day max)
url = s3.generate_presigned_url(
    'get_object',
    Params={'Bucket': 'my-bucket', 'Key': 'exports/file.zip'},
    ExpiresIn=604800,
)

# Presigned PUT with ContentType lock
url = s3.generate_presigned_url(
    'put_object',
    Params={'Bucket': 'my-bucket', 'Key': 'uploads/video.mp4', 'ContentType': 'video/mp4'},
    ExpiresIn=3600,
)
```

**Environment variable pattern (this project):**

```python
import os
ACCOUNT_ID  = os.getenv('S3_ENDPOINT_HOSTNAME')   # No scheme — add https:// in code
ACCESS_KEY  = os.getenv('S3_ACCESS_KEY')
SECRET_KEY  = os.getenv('S3_SECRET_KEY')
BUCKET      = os.getenv('S3_BUCKET_NAME', 'specterexports')
```

### 11.2 aws-sdk-php (PHP) — full client setup

```php
<?php
require_once 'vendor/aws/aws-autoloader.php';
use Aws\S3\S3Client;

$s3 = new S3Client([
    'version'     => 'latest',
    'region'      => 'auto',           // Or 'us-east-1' — R2 accepts both
    'endpoint'    => "https://{$account_id}.r2.cloudflarestorage.com",
    'credentials' => [
        'key'    => $access_key_id,
        'secret' => $secret_access_key,
    ],
]);
```

**Common operations:**

```php
// Upload
$s3->putObject([
    'Bucket'      => $bucket,
    'Key'         => 'path/to/file.zip',
    'Body'        => fopen('/local/file.zip', 'r'),
    'ContentType' => 'application/zip',
]);

// Create prefix placeholder (simulated folder)
$s3->putObject([
    'Bucket' => $bucket,
    'Key'    => $username . '/.placeholder',
    'Body'   => '',
]);

// Head
$result = $s3->headObject(['Bucket' => $bucket, 'Key' => $key]);
// $result['ContentLength'], $result['ETag']

// Get
$result = $s3->getObject(['Bucket' => $bucket, 'Key' => $key]);
$body = $result['Body']->getContents();

// List objects
$result = $s3->listObjectsV2([
    'Bucket'    => $bucket,
    'Prefix'    => $username . '/',
    'Delimiter' => '/',
]);
foreach ($result['Contents'] ?? [] as $item) {
    echo $item['Key'] . ' ' . $item['Size'] . PHP_EOL;
}

// Delete
$s3->deleteObject(['Bucket' => $bucket, 'Key' => $key]);

// Presigned GET — use createPresignedRequest, NOT getObjectUrl
$cmd     = $s3->getCommand('GetObject', ['Bucket' => $bucket, 'Key' => $key]);
$request = $s3->createPresignedRequest($cmd, '+7 days');
$url     = (string) $request->getUri();

// Presigned PUT
$cmd     = $s3->getCommand('PutObject', ['Bucket' => $bucket, 'Key' => $key]);
$request = $s3->createPresignedRequest($cmd, '+1 hour');
$url     = (string) $request->getUri();
```

**WARNING:** `$s3->getObjectUrl($bucket, $key)` returns an unsigned URL. It produces a 403 on private buckets. Always use `createPresignedRequest` for download links. (Active bug in `./dashboard/persistent_storage.php:488` — see §12.)

**PHP config convention:** PHP never reads `.env`. Credentials always come from `./config/cloudflare.php` or `./config/object_storage.php` (dev) / `/var/www/config/` (server). See [`../rules/php-config.md`](../../../rules/php-config.md).

---

## 12. BotOfTheSpecter Callsites

### Buckets

| Bucket | Purpose | Region | Credentials source |
| ------ | ------- | ------ | ------------------ |
| `specterexports` | User data export ZIPs | auto | `S3_ACCESS_KEY`, `S3_SECRET_KEY` env vars |
| `botofthespecter-au-persistent` | Stream recordings (Sydney) | AU | `au_s3_access_key`, `au_s3_secret_key` env vars; `$au_s3_access_key` PHP |
| `botofthespecter-us-persistent` | Stream recordings (US-East, US-West) | US | `us_s3_access_key`, `us_s3_secret_key` env vars; `$us_s3_access_key` PHP |

### Operations used

| Operation | File | Notes |
| --------- | ---- | ----- |
| `PutObject` | `./bot/export_user_data.py` | Uploads ZIP under `user-exports/<username>/` key |
| `generate_presigned_url` (GET, 7 days) | `./bot/export_user_data.py` | For exports >50 MB (`MAX_EMAIL_ZIP_SIZE`). Requires `s3v4` + virtual addressing |
| `PutObject` (folder placeholder) | `./dashboard/persistent_storage.php` | `<username>/.placeholder` to simulate a folder |
| `ListObjectsV2` | `./dashboard/persistent_storage.php` | Lists recordings by `<username>/` prefix; calculates storage usage |
| `DeleteObject` | `./dashboard/persistent_storage.php` | Single recording deletion |
| `getObjectUrl` | `./dashboard/persistent_storage.php:488` | **Bug: returns unsigned URL, 403s on private bucket.** Switch to `createPresignedRequest` if this feature is reactivated |
| `HeadObject` | `./stream/upload_to_persistent_storage.py` | Verifies upload success after stream recording |
| `upload_file` (multipart via TransferConfig) | `./stream/upload_to_persistent_storage.py` | 100 MB multipart threshold for stream MP4s |

### Object key conventions

| Bucket | Key pattern |
| ------ | ----------- |
| `specterexports` | `user-exports/<username>/BotOfTheSpecter_Export_<username>_<YYYY-MM-DD>_<timestamp>.zip` |
| `botofthespecter-{au,us}-persistent` | `<username>/<location>/<filename>` (dashboard reads with `Prefix=<username>/`) |
| `botofthespecter-{au,us}-persistent` | `<username>/.placeholder` (PHP folder marker) |

### Known issue: stream uploader / dashboard bucket mismatch

`./stream/upload_to_persistent_storage.py` passes `Bucket=<username>` (the username as bucket name), but `./dashboard/persistent_storage.php` reads with `Bucket='botofthespecter-au-persistent'` and `Prefix=<username>/`. These are inconsistent. If reactivating persistent storage end-to-end, normalise to `Bucket=botofthespecter-{region}-persistent`, `Key=<username>/<location>/<filename>` throughout.

---

*Last updated: 2026-05-11. Source: Cloudflare R2 developer documentation (developers.cloudflare.com/r2/*).*
