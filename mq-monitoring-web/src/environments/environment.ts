// Development environment — requests to /api/* are proxied to
// the backend via Angular's dev-server proxy (proxy.conf.json).
// apiBaseUrl must be '' so the interceptor passes relative URLs through untouched.
export const environment = {
  production: false,
  apiBaseUrl: '',
  reverbKey:          'mq-monitoring-key',
  reverbHost:         'localhost',
  reverbPort:         6001,
  reverbScheme:       'http',
  reverbAuthEndpoint: 'http://localhost:8081/broadcasting/auth',
};
