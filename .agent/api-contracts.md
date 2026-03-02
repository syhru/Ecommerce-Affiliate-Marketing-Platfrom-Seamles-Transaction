# API Integration Contracts

## Known Endpoints

### 1. Authentication & Profil

- **Login:** `POST /api/login` (Expects: email, password | Returns: user object, token).
- **Register User:** `POST /api/register` (Expects: name, email, password, password_confirmation, telegram_chat_id).
- **Logout:** `POST /api/logout` (Requires: Bearer Token).
- **Forgot Password:** `POST /api/forgot-password` (Expects: email).
- **Reset Password:** `POST /api/reset-password` (Expects: email, password, password_confirmation, token).
- **Get Profile:** `GET /api/user` (Requires: Bearer Token).
- **Update Profile:** `PUT /api/user/profile` (Requires: Bearer Token | Expects: name, email).
- **Update Password:** `PUT /api/user/password` (Requires: Bearer Token | Expects: current_password, password, password_confirmation).
- **Link Telegram:** `POST /api/user/link-telegram` (Requires: Bearer Token | Expects: telegram_chat_id).

### 2. Products

- **List Products:** `GET /api/products` (Params: page, per_page, q, category, sort).
- **Product Detail:** `GET /api/products/{slug}` (Returns: Product detail).

### 3. Orders & Tracking

- **Create Order:** `POST /api/orders` (Requires: Bearer Token | Expects: product_id, quantity, shipping_address, etc.).
- **User Orders List:** `GET /api/user/orders` (Requires: Bearer Token | Params: page).
- **Order Detail:** `GET /api/orders/{id}` (Requires: Bearer Token | Returns OrderDetail include items & trackingLogs).
- **Order Tracking List:** `GET /api/orders/{id}/tracking` (Requires: Bearer Token).

### 4. Affiliate

- **Register Affiliate:** `POST /api/affiliate/register` (Requires: Bearer Token | Expects: bank_name, bank_account_number, account_holder_name).
- **Dashboard Data:** `GET /api/affiliate/dashboard` (Requires: Bearer Token + Active Affiliate).
- **Profile:** `GET /api/affiliate/profile` | `PUT /api/affiliate/profile` (Requires: Bearer Token + Active Affiliate).
- **Commissions List:** `GET /api/affiliate/commissions` (Requires: Bearer Token + Active Affiliate).
- **Clicks List:** `GET /api/affiliate/clicks` (Requires: Bearer Token + Active Affiliate).
- **Withdrawals List:** `GET /api/affiliate/withdrawals` (Requires: Bearer Token + Active Affiliate).
- **Request Withdraw:** `POST /api/affiliate/withdraw` (Requires: Bearer Token + Active Affiliate | Expects: amount).

## Implementation Rules

1. Gunakan TypeScript Interface untuk merepresentasikan setiap model respons API di atas.
2. Token autentikasi disimpan dalam Cookie `auth_token` untuk middleware/SSR dan `localStorage` untuk caching user.
3. Selalu tambahkan header Authorization dan gunakan instance `apiGet` / `apiPost` dari `src/lib/api.ts` yang otomatis menginjeksikan token dan mem-parsing _process.env.NEXT_PUBLIC_API_URL_.
