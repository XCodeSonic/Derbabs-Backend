// src/utils/apiResponse.js

export const unwrapPayload = (response) => {
    return response?.data?.data ?? response?.data ?? response ?? null;
};

export const unwrapList = (response) => {
    const payload = unwrapPayload(response);

    if (Array.isArray(payload)) {
        return {
            data: payload,
            total: payload.length,
            current_page: 1,
            per_page: payload.length
        };
    }

    if (Array.isArray(payload?.data)) {
        return {
            ...payload,
            data: payload.data,
            total: Number(payload.total ?? payload.meta?.total ?? payload.data.length),
            current_page: Number(payload.current_page ?? payload.meta?.current_page ?? 1),
            per_page: Number(payload.per_page ?? payload.meta?.per_page ?? payload.data.length)
        };
    }

    return {
        data: [],
        total: 0,
        current_page: 1,
        per_page: 0
    };
};

export const unwrapObject = (response, fallback = {}) => {
    const payload = unwrapPayload(response);

    if (payload && typeof payload === 'object' && !Array.isArray(payload)) {
        return payload;
    }

    return fallback;
};