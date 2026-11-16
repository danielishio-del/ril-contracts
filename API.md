# RIL Contracts — REST API

Base URL: `https://rockerinlove.com/wp-json/ril/v1/`

## Authentication

The API requires:
1. **WordPress User**: You must be logged in with admin/editor privileges
2. **Session Cookie**: Your WordPress session cookie is sent automatically via browser

For programmatic access, use your WordPress username & password or a generated application password.

## Endpoints

### List Contracts
```
GET /wp-json/ril/v1/contracts
```

**Parameters:**
- `page` (int, default: 1) — Pagination
- `per_page` (int, default: 20, max: 100) — Results per page
- `status` (string) — Filter: `draft`, `sent`, `signed`, `completed`, `expired`, `canceled`
- `search` (string) — Search client name or email

**Response:**
```json
{
  "data": [
    {
      "id": 1,
      "token": "abc123...",
      "title": "Wedding Package",
      "client_name": "John & Jane",
      "client_email": "john@example.com",
      "wedding_date": "2026-06-15",
      "total_cents": 550000,
      "deposit_cents": 220000,
      "prepaid_cents": 0,
      "balance_cents": 330000,
      "status": "sent",
      "payment_status": "unpaid",
      "soft_hold": false,
      "created_at": "2026-10-29 14:30:00",
      "sent_at": "2026-10-29 15:00:00",
      "signed_at": null,
      "completed_at": null,
      "expires_at": "2026-11-12 15:00:00",
      "link": "https://rockerinlove.com/contract/abc123.../"
    }
  ],
  "pagination": {
    "total": 42,
    "page": 1,
    "per_page": 20,
    "total_pages": 3
  }
}
```

### Get Single Contract
```
GET /wp-json/ril/v1/contracts/{id}
```

Returns full contract object (same as list item).

### Create Contract
```
POST /wp-json/ril/v1/contracts
```

**Body:**
```json
{
  "title": "Wedding Package",
  "client_name": "John & Jane",
  "client_email": "john@example.com",
  "wedding_date": "2026-06-15",
  "total_cents": 550000,
  "deposit_cents": 220000,
  "studio_fields": {
    "Coverage hours": "9 hours",
    "Gift": "Engagement Session ($560 value)",
    "Number of photographers": "2"
  }
}
```

**Required:**
- `title` (string)
- `client_name` (string)
- `client_email` (string, valid email)

**Optional:**
- `wedding_date` (string, format: YYYY-MM-DD)
- `total_cents` (integer, ≥ 0)
- `deposit_cents` (integer, ≥ 0)
- `studio_fields` (object, key-value pairs)

**Response:** Full contract object with `status: "draft"`

### Update Contract
```
PUT /wp-json/ril/v1/contracts/{id}
```

**Body:** Any of the create fields + optional:
- `status` (string): `draft`, `sent`, `signed`, `completed`, `expired`, `canceled`
- `payment_status` (string): `unpaid`, `deposit_paid`, `paid_in_full`

Only included fields are updated. Logs event as `api_updated`.

**Response:** Updated contract object.

### Delete Contract
```
DELETE /wp-json/ril/v1/contracts/{id}
```

Only `draft` contracts can be deleted.

**Response:**
```json
{
  "deleted": true
}
```

### Send Contract to Client
```
POST /wp-json/ril/v1/contracts/{id}/send
```

**Requirements:**
- Contract must be in `draft` status
- Client email must be set

**What happens:**
1. Status changes to `sent`
2. Expiry set to 14 days from now
3. Email sent to client with signing link
4. Event logged

**Response:**
```json
{
  "sent": true,
  "email": "sent" or "failed"
}
```

### Update Payment Status
```
POST /wp-json/ril/v1/contracts/{id}/payment
```

**Body:**
```json
{
  "status": "deposit_paid"
}
```

**Allowed values:**
- `unpaid`
- `deposit_paid`
- `paid_in_full`

**Response:** Updated contract object.

Triggers journey emails if payment received.

### Get Template
```
GET /wp-json/ril/v1/template
```

Returns the default contract template.

**Response:**
```json
{
  "name": "Wedding Photography Contract",
  "body": "... full template text with [[fields]] and {{options}}"
}
```

## Testing with cURL

### List contracts
```bash
curl -b "wordpress_cookies.txt" \
  https://rockerinlove.com/wp-json/ril/v1/contracts
```

### Create contract
```bash
curl -X POST -b "wordpress_cookies.txt" \
  -H "Content-Type: application/json" \
  -d '{
    "title": "Test Wedding",
    "client_name": "Test Couple",
    "client_email": "test@example.com",
    "wedding_date": "2026-12-15",
    "total_cents": 550000
  }' \
  https://rockerinlove.com/wp-json/ril/v1/contracts
```

### Send contract
```bash
curl -X POST -b "wordpress_cookies.txt" \
  https://rockerinlove.com/wp-json/ril/v1/contracts/1/send
```

### Update payment
```bash
curl -X POST -b "wordpress_cookies.txt" \
  -H "Content-Type: application/json" \
  -d '{
    "status": "deposit_paid"
  }' \
  https://rockerinlove.com/wp-json/ril/v1/contracts/1/payment
```

## Error Responses

All errors return JSON with status codes:

```json
{
  "code": "rest_not_logged_in",
  "message": "You must be logged in.",
  "data": {
    "status": 401
  }
}
```

**Common status codes:**
- `200` — Success
- `400` — Bad request (missing fields, invalid data)
- `401` — Not authenticated
- `403` — Permission denied
- `404` — Contract not found
- `500` — Server error

---

## Browser Testing

Open WordPress dashboard, then in browser console:

```javascript
// List contracts
fetch('/wp-json/ril/v1/contracts')
  .then(r => r.json())
  .then(d => console.log(d))

// Create contract
fetch('/wp-json/ril/v1/contracts', {
  method: 'POST',
  headers: { 'Content-Type': 'application/json' },
  body: JSON.stringify({
    title: 'Test',
    client_name: 'John Doe',
    client_email: 'john@example.com',
    total_cents: 550000
  })
})
  .then(r => r.json())
  .then(d => console.log(d))

// Send contract (replace 1 with real ID)
fetch('/wp-json/ril/v1/contracts/1/send', { method: 'POST' })
  .then(r => r.json())
  .then(d => console.log(d))
```

---

## Next Steps

The API is ready for the PWA frontend to:
1. List & search contracts
2. Create new contracts
3. Update contract fields and status
4. Track payments
5. Send contracts to clients

See the frontend build guide for integrating with React.
