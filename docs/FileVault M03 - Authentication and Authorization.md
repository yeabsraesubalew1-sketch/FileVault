# FileVault M03 - Authentication and Authorization

**Status:** IMPLEMENTED / PRODUCTION BASELINE  
**Namespace:** `FileVault\ApiKey`  
**Architectural Role:** API Key Generation, Cryptographic Verification, and Tenant Scoping  

---

## 1. Overview & Purpose

The Authentication and Authorization module provides secure, server-to-server API key management for FileVault.

It issues cryptographically secure project API keys, enforces constant-time credential hashing, validates project authorization boundaries, and prevents client-side secret exposure.

---

## 2. Directory Structure

```text
src/ApiKey/
├── ApiKey.php                        # API Key domain entity
├── ApiKeyId.php                      # Typed primary key identifier
├── ApiKeyName.php                    # Descriptive label value object
├── ApiKeyStatus.php                  # Status Enum (active, revoked)
├── AuthenticatedApiKey.php           # Validated session context DTO
├── Application/
│   ├── AuthenticateApiKey.php        # Validates plaintext credential against DB
│   ├── CreateApiKey.php              # Generates and stores new API key
│   ├── CreatedApiKey.php             # Result DTO containing plaintext credential
│   └── RevokeApiKey.php              # Revokes an active key
├── Credential/
│   ├── ApiKeyCredentialParser.php    # Decodes fvk_* formatted credential strings
│   ├── ApiKeyGenerator.php           # Generates 16-byte ID and 32-byte secret
│   ├── ApiKeySecretHasher.php        # Binary SHA-256 hash generator
│   ├── ApiKeySecretVerifier.php      # Constant-time hash comparison
│   ├── Base64Url.php                 # RFC 4648 Base64URL encoder/decoder
│   ├── CreatedApiKeyCredential.php   # Internal generation pair
│   └── ParsedApiKeyCredential.php    # Internal parsed lookup pair
├── Exception/
│   ├── ApiKeyAuthenticationException.php
│   ├── ApiKeyNotFoundException.php
│   ├── DuplicateApiKeyNameException.php
│   └── MalformedApiKeyCredentialException.php
└── Persistence/
    ├── ApiKeyRecord.php              # Database persistence representation
    └── ApiKeyRepository.php          # Database repository interface
```

---

## 3. Credential Architecture & Lifecycle

### Credential Format
API keys follow the prefix format:
```text
fvk_<base64url_public_id>_<base64url_secret>
```
* **Public ID (`public_id`)**: 16 cryptographically random bytes (128 bits), encoded as URL-safe Base64 without padding. Used for $O(1)$ database indexed lookup.
* **Secret**: 32 cryptographically random bytes (256 bits), encoded as URL-safe Base64 without padding.

### Storage & Security
1. **Zero Plaintext Storage**: The database only stores `public_id` (`BINARY(16)`) and `secret_hash` (`BINARY(32)`).
2. **SHA-256 Binary Hash**: The secret is hashed with SHA-256 before insertion.
3. **Constant-Time Verification**: Verification uses `hash_equals($storedHash, $computedHash)` in `ApiKeySecretVerifier` to prevent timing attacks.
4. **One-Time Exposure**: The full plaintext key is only returned once during generation (`CreateApiKey::create`). If lost, the key must be revoked and regenerated.

---

## 4. Implemented Services & Contracts

### `CreateApiKey` (`FileVault\ApiKey\Application\CreateApiKey`)
* `public function create(int $projectId, string $name, DateTimeImmutable $now): CreatedApiKey`:
  1. Verifies target project exists.
  2. Generates new credential pair via `ApiKeyGenerator`.
  3. Hashes secret via `ApiKeySecretHasher`.
  4. Persists record to MySQL `api_keys` table.
  5. Returns `CreatedApiKey` containing the plaintext credential string.

### `AuthenticateApiKey` (`FileVault\ApiKey\Application\AuthenticateApiKey`)
* `public function authenticate(string $credential): AuthenticatedApiKey`:
  1. Parses key format using `ApiKeyCredentialParser`.
  2. Looks up active record by `public_id`.
  3. Rejects revoked keys or missing IDs with `ApiKeyAuthenticationException`.
  4. Verifies secret hash with `ApiKeySecretVerifier`.
  5. Returns `AuthenticatedApiKey` containing `apiKeyId`, `projectId`, and `name`.

### `RevokeApiKey` (`FileVault\ApiKey\Application\RevokeApiKey`)
* `public function revoke(int $keyId, DateTimeImmutable $now): void`: Marks status as `revoked` and sets `revoked_at` timestamp. Revoked keys immediately fail subsequent authentication attempts.

---

## 5. Invariants & Security Rules

1. **Project Scope Isolation**: An API key is strictly bound to exactly one `project_id`. Requests cannot cross project boundaries.
2. **Revocation Immutability**: A revoked key cannot be reactivated.
3. **Database Constraints**: The `api_keys` table enforces a CHECK constraint ensuring `(status = 'active' AND revoked_at IS NULL)` or `(status = 'revoked' AND revoked_at IS NOT NULL)`.
