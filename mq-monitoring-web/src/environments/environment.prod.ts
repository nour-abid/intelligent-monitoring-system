// Production environment — set apiBaseUrl to the absolute URL of the
// deployed Laravel API before building for production.
export const environment = {
  production: false,
  apiBaseUrl: ' http://localhost:8081', 
  reverbKey:          'mq-monitoring-key',
  reverbHost:         'localhost',
  reverbPort:         6001,
  reverbScheme:       'http',
  reverbAuthEndpoint: 'http://localhost:8081/broadcasting/auth',
};
