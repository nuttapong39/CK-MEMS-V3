import axios from 'axios';

const client = axios.create({
    baseURL: '/api/v1',
    headers: {
        'Content-Type': 'application/json',
        Accept: 'application/json',
    },
});

let onUnauthorized = null;
export function setUnauthorizedHandler(fn) {
    onUnauthorized = fn;
}

/* ── Request interceptor: attach Bearer token ── */
client.interceptors.request.use((config) => {
    const token = localStorage.getItem('ckmems.token');
    if (token) {
        config.headers.Authorization = `Bearer ${token}`;
    }
    return config;
});

/* ── Response interceptor: auto-refresh on 401 ── */
let isRefreshing   = false;
let refreshQueue   = [];   // pending requests waiting for new token

function processQueue(error, token = null) {
    refreshQueue.forEach((p) => {
        if (error) p.reject(error);
        else p.resolve(token);
    });
    refreshQueue = [];
}

client.interceptors.response.use(
    (response) => response,
    async (error) => {
        const original = error.config;

        // Only attempt refresh on 401, and not on auth endpoints themselves
        if (
            error.response?.status === 401 &&
            !original._retry &&
            !original.url?.includes('/auth/login') &&
            !original.url?.includes('/auth/refresh')
        ) {
            if (isRefreshing) {
                // Queue this request until refresh completes
                return new Promise((resolve, reject) => {
                    refreshQueue.push({ resolve, reject });
                }).then((newToken) => {
                    original.headers.Authorization = `Bearer ${newToken}`;
                    return client(original);
                });
            }

            original._retry   = true;
            isRefreshing      = true;

            try {
                const { data } = await client.post('/auth/refresh');
                const newToken = data.access_token;

                // Persist new token
                localStorage.setItem('ckmems.token', newToken);
                const raw = localStorage.getItem('ckmems.session');
                if (raw) {
                    try {
                        const session       = JSON.parse(raw);
                        session.token       = newToken;
                        localStorage.setItem('ckmems.session', JSON.stringify(session));
                    } catch (_) {}
                }

                // Update default header and retry
                client.defaults.headers.common.Authorization = `Bearer ${newToken}`;
                original.headers.Authorization               = `Bearer ${newToken}`;
                processQueue(null, newToken);
                return client(original);
            } catch (refreshError) {
                // Refresh also failed → force logout
                processQueue(refreshError, null);
                localStorage.removeItem('ckmems.token');
                localStorage.removeItem('ckmems.session');
                if (onUnauthorized) onUnauthorized();
                return Promise.reject(refreshError);
            } finally {
                isRefreshing = false;
            }
        }

        return Promise.reject(error);
    },
);

export default client;
