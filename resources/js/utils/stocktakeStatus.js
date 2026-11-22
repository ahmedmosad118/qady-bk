import i18n from '../i18n';

const t = (key, params) => i18n.global.t(key, params);

export const stocktakeStatusLabel = (status) => {
  const map = {
    draft: t('stocktaking.statuses.draft'),
    in_progress: t('stocktaking.statuses.in_progress'),
    pending_approval: t('stocktaking.statuses.pending_approval'),
    approved: t('stocktaking.statuses.approved'),
    rejected: t('stocktaking.statuses.rejected'),
    cancelled: t('stocktaking.statuses.cancelled'),
    completed: t('stocktaking.statuses.completed'),
  };
  return map[status] || status || '—';
};

export const stocktakeStatusBadgeClass = (status) => {
  switch (status) {
    case 'draft':
      return 'bg-zinc-500/10 text-zinc-600 dark:text-zinc-400 border border-zinc-500/20';
    case 'in_progress':
      return 'bg-amber-500/10 text-amber-600 dark:text-amber-400 border border-amber-500/20';
    case 'pending_approval':
      return 'bg-blue-500/10 text-blue-600 dark:text-blue-400 border border-blue-500/20';
    case 'approved':
      return 'bg-purple-500/10 text-purple-600 dark:text-purple-400 border border-purple-500/20';
    case 'rejected':
      return 'bg-red-500/10 text-red-600 dark:text-red-400 border border-red-500/20';
    case 'cancelled':
      return 'bg-zinc-400/10 text-zinc-500 border border-zinc-400/20';
    case 'completed':
      return 'bg-emerald-500/10 text-emerald-600 dark:text-emerald-400 border border-emerald-500/20';
    default:
      return 'bg-zinc-500/10 text-zinc-500';
  }
};
