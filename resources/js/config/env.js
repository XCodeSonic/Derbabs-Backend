const rawApiUrl = import.meta.env.VITE_API_URL || 'http://127.0.0.1:8000';

const normalizedApiUrl = rawApiUrl
  .trim()
  .replace(/\/+$/, '');

export const API_ORIGIN = normalizedApiUrl
  .replace(/(?:\/api\/v1)+$/i, '');

export const API_BASE_URL = `${API_ORIGIN}/api/v1`;
