import i18n from '../i18n';

const t = (key, params) => i18n.global.t(key, params);

export const inventoryTypeLabel = (type) => {
  if (!type) return '';
  const clean = String(type).replace(/-/g, '_');
  const labels = {
    goods_receipt: t('inventory.shortTypes.goods_receipt'),
    goods_delivery: t('inventory.shortTypes.goods_delivery'),
    warehouse_transfer: t('inventory.shortTypes.warehouse_transfer'),
    stock_adjustment: t('inventory.shortTypes.stock_adjustment'),
    inventory_return: t('inventory.shortTypes.inventory_return'),
    stock_write_off: t('inventory.shortTypes.stock_write_off'),
    opening_balance: t('inventory.shortTypes.opening_balance'),
  };
  return labels[clean] || clean;
};

export const inventoryStatusLabel = (status) => {
  const labels = {
    draft: t('inventory.statuses.draft'),
    pending_approval: t('inventory.statuses.pending_approval'),
    approved: t('inventory.statuses.approved'),
    posted: t('inventory.statuses.posted'),
    rejected: t('inventory.statuses.rejected'),
    cancelled: t('inventory.statuses.cancelled'),
    reversed: t('inventory.statuses.reversed'),
  };
  return labels[status] || status;
};

export const inventoryStatusBadgeClass = (status) => {
  switch (status) {
    case 'draft':
      return 'bg-zinc-500/10 text-zinc-600 dark:text-zinc-400 border border-zinc-500/30';
    case 'pending_approval':
      return 'bg-blue-500/10 text-blue-600 dark:text-blue-400 border border-blue-500/30';
    case 'approved':
      return 'bg-emerald-500/10 text-emerald-600 dark:text-emerald-400 border border-emerald-500/30';
    case 'posted':
      return 'bg-purple-500/10 text-purple-600 dark:text-purple-400 border border-purple-500/30';
    case 'rejected':
      return 'bg-red-500/10 text-red-600 dark:text-red-400 border border-red-500/30';
    case 'cancelled':
      return 'bg-zinc-500/10 text-zinc-400 border border-zinc-500/20';
    case 'reversed':
      return 'bg-amber-500/10 text-amber-600 dark:text-amber-400 border border-amber-500/30';
    default:
      return 'bg-zinc-500/10 text-zinc-500';
  }
};

export const inventoryDirectionLabel = (dir) => {
  const labels = {
    increase: t('inventory.directions.increase'),
    decrease: t('inventory.directions.decrease'),
    inbound: t('inventory.directions.inbound'),
    outbound: t('inventory.directions.outbound'),
  };
  return labels[dir] || dir;
};
