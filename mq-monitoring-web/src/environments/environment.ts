// Development environment — requests to /api/* are proxied to
// empty so the interceptor passes relative URLs through untouched.
export const environment = {
  production: true,
  apiBaseUrl: ' http://localhost:8081',
  reverbKey:          'mq-monitoring-key',
  reverbHost:         'localhost',
  reverbPort:         6001,
  reverbScheme:       'http',
  reverbAuthEndpoint: 'http://localhost:8081/broadcasting/auth',
};
