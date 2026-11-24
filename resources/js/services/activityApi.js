import api from './api';

export const fetchActivities = (params = {}) => api.get('/activities', { params });

export const deleteActivity = (id) => api.delete(`/activities/${id}`);

export const clearAllActivities = () => api.delete('/activities/clear');
