// ============================================================
// Types: Order
// sesuai dengan API response POST /api/orders
// ============================================================

export interface OrderRequest {
  productId: number;
  quantity: number;
}

export interface OrderItem {
  id: number;
  productId: number;
  productName: string;
  quantity: number;
  unitPrice: number;
  subtotal: number;
}

export interface Order {
  id: number;
  orderNumber: string;
  customerId: number;
  affiliateId: number | null;
  subtotal: number;
  commissionAmount: number;
  shippingCost: number;
  totalAmount: number;
  status: 'pending' | 'paid' | 'processing' | 'shipped' | 'completed' | 'cancelled';
  paymentMethod: string | null;
  midtransSnapToken: string | null;
  shippingAddress: string | null;
  items: OrderItem[];
  createdAt: string;
  updatedAt: string;
}
