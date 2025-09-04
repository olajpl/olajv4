import React from 'react';

type PayStatus = 'opłacona' | 'częściowa' | 'nadpłata' | 'nieopłacona';

export const PayChip = ({ status }: { status: PayStatus }) => {
  const map = {
    opłacona: ['opłacone', 'bg-emerald-100 text-emerald-700 border border-emerald-200'],
    częściowa: ['częściowo', 'bg-amber-100 text-amber-700 border border-amber-200'],
    nadpłata: ['nadpłata', 'bg-indigo-100 text-indigo-700 border border-indigo-200'],
    nieopłacona: ['nieopłacone', 'bg-stone-100 text-stone-700 border border-stone-200'],
  } as const;

  const [label, className] = map[status] || map.nieopłacona;

  return <span className={`px-2 py-1 text-xs rounded-full ${className}`}>{label}</span>;
};
