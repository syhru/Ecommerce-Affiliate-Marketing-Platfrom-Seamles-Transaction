'use client';

import { apiPost } from '@/src/lib/api';
import { useEffect } from 'react';

const STORAGE_KEY = 'tdr_affiliate_ref';
const VISITOR_KEY = 'tdr_affiliate_visitor';
const REFERRAL_LIFETIME_MS = 30 * 24 * 60 * 60 * 1000;

type ReferralState = { code: string; expires_at: number };
type TrackingResponse = { valid: boolean; click_created: boolean; referral_code?: string };

function visitorToken(): string {
  const existing = window.localStorage.getItem(VISITOR_KEY);
  if (existing) return existing;
  const token = crypto.randomUUID();
  window.localStorage.setItem(VISITOR_KEY, token);
  return token;
}

export function ReferralCapture() {
  useEffect(() => {
    const code = new URLSearchParams(window.location.search).get('ref')?.trim();
    if (!code) return;

    let current: ReferralState | null = null;
    try {
      const stored = JSON.parse(window.localStorage.getItem(STORAGE_KEY) || 'null') as ReferralState | null;
      if (stored && stored.expires_at > Date.now()) current = stored;
    } catch {
      window.localStorage.removeItem(STORAGE_KEY);
    }

    void apiPost<TrackingResponse>('/affiliate/track', {
      referral_code: code,
      visitor_token: visitorToken(),
      landing_url: window.location.href,
    }).then((result) => {
      if (!result.valid || !result.referral_code) return;
      const next: ReferralState = {
        code: result.referral_code,
        expires_at: Date.now() + REFERRAL_LIFETIME_MS,
      };
      // Last valid touch wins. Invalid touches never reach this write.
      window.localStorage.setItem(STORAGE_KEY, JSON.stringify(next));
    }).catch(() => {
      // Attribution is optional for browsing; checkout still revalidates it.
      if (current && current.expires_at > Date.now()) {
        window.localStorage.setItem(STORAGE_KEY, JSON.stringify(current));
      }
    });
  }, []);

  return null;
}
