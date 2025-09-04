import React from 'react';

export const WeightBadge = ({ kg }: { kg: number }) => {
  const label = `${kg.toFixed(2).replace('.', ',')} kg`;
  if (kg < 5)
    return <span className="px-2 py-1 text-xs rounded-full bg-emerald-100 text-emerald-700 border border-emerald-200" title="Lekka paczka">&lt;5 kg · {label}</span>;
  if (kg <= 15)
    return <span className="px-2 py-1 text-xs rounded-full bg-amber-100 text-amber-700 border border-amber-200" title="Średnia paczka">&le;15 kg · {label}</span>;
  if (kg <= 25)
    return <span className="px-2 py-1 text-xs rounded-full bg-red-100 text-red-700 border border-red-200" title="Ciężka paczka">&le;25 kg · {label}</span>;

  return <span className="px-2 py-1 text-xs rounded-full bg-red-200 text-red-800 border border-red-300" title="Bardzo ciężka paczka">&gt;25 kg · {label}</span>;
};
