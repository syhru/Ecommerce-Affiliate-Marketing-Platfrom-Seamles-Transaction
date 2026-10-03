// ============================================================
// Types: Affiliate
// Cerminan wire-format API Resource + model constants backend.
//
// - AffiliateProfileResource = affiliate_profile pada UserResource
//   dan hasil `GET /affiliate/profile` (bare object, tanpa `data`).
// - Status mengikuti AffiliateProfile::STATUSES.
// ============================================================

export type AffiliateProfileStatus = 'pending' | 'active' | 'rejected' | 'inactive';

export interface AffiliateProfile {
  id: number;
  user_id: number;
  referral_code: string;
  commission_rate: number;
  balance: number;
  total_earned: number;
  status: AffiliateProfileStatus;
  approved_at: string | null;
  bank_name: string | null;
  bank_account_number: string | null;
  bank_account_holder: string | null;
  created_at: string | null;
}

/** AffiliateCommission::STATUS_* — pending | earned | cancelled | withdrawn. */
export type CommissionStatus = 'pending' | 'earned' | 'cancelled' | 'withdrawn';

/** AffiliateWithdrawal::STATUS_* — pending | completed | rejected. */
export type WithdrawalStatus = 'pending' | 'completed' | 'rejected';

/**
 * Commission & withdrawal dikembalikan sebagai paginator Eloquent mentah
 * (`response()->json($model->paginate())`), bukan Resource.
 */
export interface Commission {
  id: number;
  order_id: number;
  amount: string | number;
  status: CommissionStatus;
  created_at: string;
  order?: { id: number; order_number: string } | null;
}

export interface Withdrawal {
  id: number;
  amount: string | number;
  status: WithdrawalStatus;
  bank_name: string;
  bank_account_number: string;
  bank_account_holder: string;
  created_at: string;
}

/** Bentuk `GET /affiliate/dashboard` (payload handcrafted via compact()). */
export interface AffiliateDashboardData {
  stats: {
    total_clicks: number;
    total_conversions: number;
    conversion_rate: number;
    total_commission: number;
    balance: number;
  };
  chartData: {
    labels: string[];
    clicks: number[];
    convs: number[];
  };
}
