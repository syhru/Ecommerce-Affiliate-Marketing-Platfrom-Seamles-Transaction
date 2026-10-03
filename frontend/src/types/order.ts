// ============================================================
// Types: Order
// Cerminan wire-format OrderResource / OrderItemResource /
// TrackingLogResource (snake_case).
//
// Status order mengikuti Order::STATUS_* — `verified` (bukan `paid`)
// adalah status sukses pembayaran yang canonical.
// ============================================================

export type OrderStatus =
  | 'pending'
  | 'verified'
  | 'processing'
  | 'shipped'
  | 'completed'
  | 'cancelled';

export interface OrderItem {
  id: number;
  product_id: number;
  product_name: string;
  product_price: number;
  quantity: number;
  subtotal: number;
}

export interface TrackingLog {
  id: number;
  order_id: number;
  status_title: string;
  description: string | null;
  created_at: string | null;
}

/** `customer`, `items`, `tracking_logs` hanya ada saat relasi di-load server. */
export interface Order {
  id: number;
  order_number: string;
  status: OrderStatus;
  payment_method: string | null;
  payment_verified_at: string | null;
  subtotal: number;
  commission_amount: number;
  total_amount: number;
  shipping_address: string | null;
  shipping_courier: string | null;
  shipping_tracking_number: string | null;
  shipped_at: string | null;
  completed_at: string | null;
  cancelled_at: string | null;
  cancellation_reason: string | null;
  notes: string | null;
  created_at: string | null;
  updated_at: string | null;
  customer?: import('./user').User | null;
  items?: OrderItem[];
  tracking_logs?: TrackingLog[];
}

/** Bentuk hasil POST /orders (handcrafted, bukan paginator). */
export interface OrderCreateResponse {
  message: string;
  order: Order;
  payment_url: string | null;
}

/** Bentuk paginator Laravel (field paginasi berada di top-level). */
export interface Paginated<T> {
  data: T[];
  current_page: number;
  last_page: number;
  per_page: number;
  total: number;
  from?: number | null;
  to?: number | null;
}
