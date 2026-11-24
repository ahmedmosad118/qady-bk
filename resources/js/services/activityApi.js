import api from './api';

export const fetchActivities = (params = {}) => api.get('/activities', { params });

export const clearAllActivities = () => api.delete('/activities/clear');
