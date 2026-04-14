<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Face Embedding Microservice
    |--------------------------------------------------------------------------
    |
    | The Python FastAPI service that detects faces and generates InsightFace
    | buffalo_l embeddings from uploaded enrollment photos.
    |
    | EMBEDDING_SERVICE_URL — base URL of the running FastAPI process.
    |                         Set this in .env; the default assumes both
    |                         services run on the same machine in development.
    |
    */
    'service_url' => env('EMBEDDING_SERVICE_URL', 'http://127.0.0.1:8765'),

];
