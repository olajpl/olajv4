import React from 'react';

export const StatusBadge = ({ status }: { status: string }) => {
  const map: Record<string, [string, string]> = {
    'nowe': ['Nowe', 'bg-blue-100 text-blue-700 border border-blue-200'],
    'otwarta_paczka:add_products': ['Dodawanie produktów', 'bg-amber-100 text-amber-700 border border-amber-200'],
    'otwarta_paczka:payment_only': ['Czeka na checkout', 'bg-amber-100 text-amber-700 border border-amber-200'],
    'oczekuje_na_płatność': ['Czeka na płatność', 'bg-yellow-100 text-yellow-700 border border-yellow-200'],
    'gotowe_do_wysylki': ['Gotowe do wysyłki', 'bg-emerald-100 text-emerald-700 border border-emerald-200'],
    'wyslane': ['Wysłane', 'bg-indigo-100 text-indigo-700 border border-indigo-200'],
    'zrealizowane': ['Zrealizowane', 'bg-stone-200 text-stone-800 border border-stone-300'],
    'anulowane': ['Anulowane', 'bg-red-100 text-red-700 border border-red-200'],
    'archiwized': ['Zarchiwizowane', 'bg-gray-200 text-gray-700 border border-gray-300'],
  };

  const [label, className] = map[status] || [status, 'bg-stone-100 text-stone-700 border border-stone-200'];

  return <span className={`inline-block px-2 py-1 text-xs rounded-full ${className}`}>{label}</span>;
};
