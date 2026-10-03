// Balances are stored as credits where 1 credit = 1 peso; customers only ever see pesos.
export const peso = (amount: number): string => `₱${amount.toLocaleString('en-PH')}`;
